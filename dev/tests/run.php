<?php
/**
 * dev/tests/run.php — Pruebas de integración (HTTP + BD) contra el servidor de pruebas.
 *
 * Requisitos: servidor en http://localhost:8383 arrancado con APP_DB=dev (ver docs/DESARROLLO.md)
 * y BD local facturacion_saas_dev. NUNCA apunta a producción: se niega a correr si la BD no es *_dev.
 *
 * Uso:  APP_DB=dev php dev/tests/run.php [filtro]
 */
if (getenv('APP_DB') !== 'dev') exit("Ejecuta con APP_DB=dev\n");
require __DIR__ . '/../../includes/db.php';
if (substr($pdo->query("SELECT DATABASE()")->fetchColumn(), -4) !== '_dev') exit("La BD no es de desarrollo. Abortado.\n");

const BASE = 'http://localhost:8383/proyectos/NARANJA/sistemafacturacion/clientes/naranjaymedia/';
const BASE_CCIC = 'http://localhost:8383/proyectos/NARANJA/sistemafacturacion/clientes/ccic/';
const API  = 'http://localhost:8383/proyectos/NARANJA/sistemafacturacion/includes/api/';

// La empresa CCIC entra por su propia URL /clientes/ccic/ (clientes/.htaccess y dev/router.php
// la sirven con la app común).
define('QA_PASS', trim(@file_get_contents(__DIR__ . '/../.qa_password')));
define('UPLOADS', realpath(__DIR__ . '/../../clientes/naranjaymedia/includes/uploads'));

$filtro = $argv[1] ?? '';
$GLOBALS['ok'] = $GLOBALS['fail'] = 0;
$GLOBALS['fallos'] = [];

// ── Utilidades ───────────────────────────────────────────────────────────────
class Cliente
{
    public string $jar;
    public string $base = BASE;
    public string $csrf = '';
    public bool $sinCsrf = false;   // para probar que el servidor rechaza solicitudes sin token
    public function __construct(public string $nombre)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'qa_');
    }
    public function req(string $method, string $url, $data = null, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
        ]);
        if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        if ($method !== 'GET' && $this->csrf && !$this->sinCsrf) $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $head = substr($raw, 0, $hs);
        $body = substr($raw, $hs);
        preg_match('/^Location:\s*(.+)$/mi', $head, $m);
        return ['code' => $code, 'body' => $body, 'loc' => trim($m[1] ?? ''), 'json' => json_decode($body, true), 'head' => $head];
    }
    public function get(string $p, array $q = []): array
    {
        return $this->req('GET', (str_starts_with($p, 'http') ? $p : $this->base . $p) . ($q ? '?' . http_build_query($q) : ''));
    }
    public function post(string $p, array $d): array
    {
        return $this->req('POST', str_starts_with($p, 'http') ? $p : $this->base . $p, $d);
    }
    public function postJson(string $p, array $d): array
    {
        return $this->req('POST', $this->base . $p, json_encode($d), ['Content-Type: application/json']);
    }
}

function login(string $correo, int $estab = 1, ?int $clienteSel = null): Cliente
{
    $c = new Cliente($correo);
    // Cada empresa usa su carpeta: los usuarios de CCIC entran por /clientes/ccic/
    if (str_contains($correo, 'ccic')) $c->base = BASE_CCIC;
    $r = $c->post('index.php', ['correo' => $correo, 'clave' => QA_PASS]);
    if (str_contains($r['loc'], 'seleccionar_cliente')) {
        $form = $c->get('seleccionar_cliente');
        preg_match('/name="_csrf" value="([a-f0-9]+)"/', $form['body'], $m);
        $c->post('seleccionar_cliente', ['cliente_id' => $clienteSel ?? 2, '_csrf' => $m[1] ?? '']);
        $r = ['loc' => 'seleccionar_establecimiento'];
    }
    if (str_contains($r['loc'], 'seleccionar_establecimiento')) {
        $form = $c->get('seleccionar_establecimiento');
        preg_match('/name="_csrf" value="([a-f0-9]+)"/', $form['body'], $m);
        $c->post('seleccionar_establecimiento', ['establecimiento_id' => $estab, '_csrf' => $m[1] ?? '']);
    }
    // Token CSRF de la sesión (como lo toma el navegador del <meta>)
    $d = $c->get('dashboard');
    // Roles sin Inicio (p. ej. Nómina y gastos) se redirigen: el token se toma de su primera página
    if (!preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $d['body'], $m)) $d = $c->get('colaboradores');
    if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $d['body'], $m)) $c->csrf = $m[1];
    return $c;
}

function check(string $nombre, bool $cond, string $detalle = ''): void
{
    clearstatcache();
    if ($cond) {
        $GLOBALS['ok']++;
        echo "  ✔ $nombre\n";
    } else {
        $GLOBALS['fail']++;
        $GLOBALS['fallos'][] = $nombre . ($detalle ? " — $detalle" : '');
        echo "  ✘ $nombre" . ($detalle ? "  [$detalle]" : '') . "\n";
    }
}

function sinErroresPhp(string $html): bool
{
    return !preg_match('/(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught )/', $html);
}

function errorPhp(string $html): string
{
    return preg_match('/(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught )[^\n]{0,160}/', strip_tags($html), $m) ? $m[0] : '';
}

function suite(string $nombre, callable $fn): void
{
    global $filtro;
    if ($filtro && stripos($nombre, $filtro) === false) return;
    echo "\n▶ $nombre\n";
    try {
        $fn();
    } catch (Throwable $e) {
        check("$nombre (excepción)", false, $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

function db(): PDO
{
    return $GLOBALS['pdo'];
}

function archivoTemporal(string $tipo): string
{
    $f = tempnam(sys_get_temp_dir(), 'qa') . ".$tipo";
    if ($tipo === 'png') {
        file_put_contents($f, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    } elseif ($tipo === 'pdf') {
        file_put_contents($f, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Count 0/Kids[]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
    } else {
        file_put_contents($f, "<?php echo 'esto no es una imagen'; ?>");
    }
    return $f;
}

// ── Suites ───────────────────────────────────────────────────────────────────
require __DIR__ . '/suites.php';

echo "\n══════════════════════════════════════\n";
echo "Resultado: {$GLOBALS['ok']} OK, {$GLOBALS['fail']} fallos\n";
foreach ($GLOBALS['fallos'] as $f) echo "  ✘ $f\n";
exit($GLOBALS['fail'] ? 1 : 0);
