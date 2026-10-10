<?php
/**
 * correo.php — Envío de correo por SMTP (sin librerías externas) y configuración por empresa.
 *
 *   correoConfig($pdo, $cid)                 → configuración de la empresa o null
 *   correoEnviar($pdo, $cid, $para, $asunto, $html, $texto, $adjuntos, $tipo, $refId, $usuario)
 *       $adjuntos = [['ruta' => '/abs/archivo.jpg', 'nombre' => 'comprobante.jpg'], …]
 *       Devuelve true o lanza Exception con el motivo; siempre deja registro en correos_enviados.
 *
 * Tablas: sql/migraciones/2026-10-04_correo.sql
 */

function correoDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool)$pdo->query("SHOW TABLES LIKE 'configuracion_correo'")->fetchColumn();
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** Clave para cifrar la contraseña SMTP: derivada de los datos de conexión del servidor (no se guarda en la BD). */
function correoClaveCifrado(): string
{
    return hash('sha256', 'correo-smtp|' . (defined('DB_PASS') ? DB_PASS : '') . '|' . (defined('DB_NAME') ? DB_NAME : ''), true);
}

function correoCifrar(string $texto): string
{
    $iv = random_bytes(16);
    $c = openssl_encrypt($texto, 'aes-256-cbc', correoClaveCifrado(), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $c);
}

function correoDescifrar(?string $guardado): string
{
    if (!$guardado) return '';
    $raw = base64_decode($guardado, true);
    if ($raw === false || strlen($raw) < 17) return '';
    $t = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', correoClaveCifrado(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $t === false ? '' : $t;
}

function correoConfig(PDO $pdo, int $cid, string $perfil = 'nomina'): ?array
{
    if (!correoDisponible($pdo)) return null;
    if (correoTienePerfiles($pdo)) {
        $st = $pdo->prepare("SELECT * FROM configuracion_correo WHERE cliente_id = ? AND perfil = ?");
        $st->execute([$cid, correoPerfil($perfil)]);
    } else {
        $st = $pdo->prepare("SELECT * FROM configuracion_correo WHERE cliente_id = ?");
        $st->execute([$cid]);
    }
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Cuentas de envío por empresa: cada una con su remitente (p. ej. nomina@… y facturacion@…). */
const CORREO_PERFILES = ['nomina' => 'Nómina', 'facturacion' => 'Facturación'];

function correoPerfil(?string $p): string
{
    return isset(CORREO_PERFILES[$p ?? '']) ? $p : 'nomina';
}

function correoTienePerfiles(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) $ok = (bool)$pdo->query("SHOW COLUMNS FROM configuracion_correo LIKE 'perfil'")->fetchColumn();
    return $ok;
}

/** Guarda la configuración. Si $d['clave'] viene vacía se conserva la anterior. */
function correoGuardarConfig(PDO $pdo, int $cid, array $d): void
{
    $host = trim((string)($d['host'] ?? ''));
    $puerto = (int)($d['puerto'] ?? 0);
    $seg = in_array($d['seguridad'] ?? '', ['tls', 'ssl', 'ninguna'], true) ? $d['seguridad'] : 'tls';
    $usuario = trim((string)($d['usuario'] ?? ''));
    $remitente = trim((string)($d['remitente_email'] ?? ''));
    if ($host === '' || !preg_match('/^[a-z0-9.-]+$/i', $host)) throw new Exception("Servidor SMTP inválido.");
    if ($puerto < 1 || $puerto > 65535) throw new Exception("Puerto inválido.");
    if ($usuario === '') throw new Exception("Indica el usuario SMTP.");
    if (!filter_var($remitente, FILTER_VALIDATE_EMAIL)) throw new Exception("Correo del remitente inválido.");
    foreach (['logo_url', 'enlace_url'] as $k) {
        $u = trim((string)($d[$k] ?? ''));
        if ($u !== '' && !preg_match('#^https://[^\s"<>]+$#i', $u)) throw new Exception("El " . ($k === 'logo_url' ? 'logo' : 'enlace') . " debe ser una dirección https://");
        if ($k === 'logo_url' && preg_match('/\.svg($|\?)/i', $u)) throw new Exception("Usa un logo PNG o JPG: Gmail y Outlook no muestran SVG.");
    }
    // «Responder a» y «copia oculta» aceptan varios correos separados por coma o punto y coma
    foreach (['responder_a', 'copia_oculta'] as $k) {
        $lista = correoLista((string)($d[$k] ?? ''));
        foreach ($lista as $m) if (!filter_var($m, FILTER_VALIDATE_EMAIL)) throw new Exception("Correo inválido en «" . str_replace('_', ' ', $k) . "»: $m");
        $d[$k] = implode(', ', $lista);
    }
    $perfil = correoPerfil($d['perfil'] ?? 'nomina');
    $actual = correoConfig($pdo, $cid, $perfil);
    $clave = (string)($d['clave'] ?? '');
    $cifrada = $clave !== '' ? correoCifrar($clave) : ($actual['clave_cifrada'] ?? null);
    if (!$cifrada) throw new Exception("Indica la contraseña SMTP.");
    $marca = (bool)$pdo->query("SHOW COLUMNS FROM configuracion_correo LIKE 'logo_url'")->fetchColumn();
    $conPerfil = correoTienePerfiles($pdo);
    $pdo->prepare("INSERT INTO configuracion_correo (cliente_id, " . ($conPerfil ? "perfil, " : "") . "host, puerto, seguridad, usuario, clave_cifrada, remitente_email, remitente_nombre, responder_a, copia_oculta, activo)
                   VALUES (?, " . ($conPerfil ? "?, " : "") . "?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE host = VALUES(host), puerto = VALUES(puerto), seguridad = VALUES(seguridad), usuario = VALUES(usuario),
                       clave_cifrada = VALUES(clave_cifrada), remitente_email = VALUES(remitente_email), remitente_nombre = VALUES(remitente_nombre),
                       responder_a = VALUES(responder_a), copia_oculta = VALUES(copia_oculta), activo = VALUES(activo)")
        ->execute([...($conPerfil ? [$cid, $perfil] : [$cid]), $host, $puerto, $seg, $usuario, $cifrada, $remitente, trim((string)($d['remitente_nombre'] ?? '')) ?: null,
            trim((string)($d['responder_a'] ?? '')) ?: null, trim((string)($d['copia_oculta'] ?? '')) ?: null, empty($d['activo']) ? 0 : 1]);
    if ($pdo->query("SHOW COLUMNS FROM configuracion_correo LIKE 'aviso_pago_auto'")->fetchColumn()) {
        $hora = max(0, min(23, (int)($d['aviso_pago_hora'] ?? 7)));
        $pdo->prepare("UPDATE configuracion_correo SET aviso_pago_auto = ?, aviso_pago_hora = ? WHERE cliente_id = ?" . ($conPerfil ? " AND perfil = ?" : ""))
            ->execute([empty($d['aviso_pago_auto']) ? 0 : 1, $hora, $cid, ...($conPerfil ? [$perfil] : [])]);
    }
    if ($pdo->query("SHOW COLUMNS FROM configuracion_correo LIKE 'aviso_pago_desde'")->fetchColumn() && array_key_exists('aviso_pago_desde', $d)) {
        $desde = trim((string)$d['aviso_pago_desde']);
        if ($desde !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) throw new Exception("Fecha de corte de avisos inválida.");
        $pdo->prepare("UPDATE configuracion_correo SET aviso_pago_desde = ? WHERE cliente_id = ?" . ($conPerfil ? " AND perfil = ?" : ""))
            ->execute([$desde ?: null, $cid, ...($conPerfil ? [$perfil] : [])]);
    }
    if ($pdo->query("SHOW COLUMNS FROM configuracion_correo LIKE 'verificar_ssl'")->fetchColumn()) {
        $pdo->prepare("UPDATE configuracion_correo SET verificar_ssl = ? WHERE cliente_id = ?" . ($conPerfil ? " AND perfil = ?" : ""))->execute([empty($d['verificar_ssl']) ? 0 : 1, $cid, ...($conPerfil ? [$perfil] : [])]);
    }
    if ($marca) {
        $pdo->prepare("UPDATE configuracion_correo SET logo_url = ?, enlace_url = ? WHERE cliente_id = ?" . ($conPerfil ? " AND perfil = ?" : ""))
            ->execute([trim((string)($d['logo_url'] ?? '')) ?: null, trim((string)($d['enlace_url'] ?? '')) ?: null, $cid, ...($conPerfil ? [$perfil] : [])]);
    }
}

/** Texto del pie: mensaje automático y a quién escribir (los correos de «Responder a»). $e escapa HTML. */
function correoPieAutomatico(array $cfg, callable $e): string
{
    $resp = correoLista((string)($cfg['responder_a'] ?? ''));
    $links = implode(', ', array_map(fn($m) => '<a href="mailto:' . $e($m) . '" style="color:#64748b">' . $e($m) . '</a>', $resp));
    return 'Este es un mensaje automático. Si tiene alguna consulta puede responder a este correo'
        . ($links ? ' o escribir a ' . $links : '') . '.';
}

/**
 * Copia oculta de cada envío: los correos de «Responder a» y los de «Copia oculta» de la cuenta (los de Configuración),
 * solo en días hábiles (lunes a viernes, hora de Honduras); sábado y domingo no se manda copia.
 * No repite a quien ya va en Para o CC.
 */
function correoCopiaOculta(array $cfg, array $yaVan = [], ?int $diaSemana = null): array
{
    if (($diaSemana ?? (int)date('N')) >= 6) return [];
    $ya = array_map('strtolower', $yaVan);
    $out = [];
    foreach (array_merge(correoLista((string)($cfg['responder_a'] ?? '')), correoLista((string)($cfg['copia_oculta'] ?? ''))) as $m) {
        if (!filter_var($m, FILTER_VALIDATE_EMAIL) || in_array(strtolower($m), $ya, true) || in_array(strtolower($m), array_map('strtolower', $out), true)) continue;
        $out[] = $m;
    }
    return $out;
}

/** "a@x.com; b@y.com" → ['a@x.com', 'b@y.com'] */
function correoLista(string $t): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $t))));
}

function correoRegistrar(PDO $pdo, int $cid, string $tipo, ?int $ref, string $para, string $asunto, bool $ok, ?string $error, ?int $usuario): void
{
    try {
        $pdo->prepare("INSERT INTO correos_enviados (cliente_id, tipo, referencia_id, destinatario, asunto, estado, error, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$cid, $tipo, $ref, mb_substr($para, 0, 150), mb_substr($asunto, 0, 255), $ok ? 'enviado' : 'error', $error ? mb_substr($error, 0, 500) : null, $usuario]);
    } catch (Throwable $e) {
        error_log('correoRegistrar: ' . $e->getMessage());
    }
}

/** Envía un correo con la configuración de la empresa. Lanza Exception si falla (y lo registra). */
function correoEnviar(PDO $pdo, int $cid, string $para, string $asunto, string $html, string $texto, array $adjuntos = [], string $tipo = 'general', ?int $refId = null, ?int $usuario = null, string $perfil = 'nomina', string $cc = ''): bool
{
    $cfg = correoConfig($pdo, $cid, $perfil);
    try {
        if (!$cfg) throw new Exception("La cuenta de correo «" . CORREO_PERFILES[correoPerfil($perfil)] . "» no está configurada (Configuración → Correo SMTP).");
        if (!(int)$cfg['activo']) throw new Exception("El envío de correos está desactivado.");
        $paraLista = correoLista($para);
        $ccLista = correoLista($cc);
        if (!$paraLista) throw new Exception("El destinatario no tiene un correo válido.");
        foreach (array_merge($paraLista, $ccLista) as $m) if (!filter_var($m, FILTER_VALIDATE_EMAIL)) throw new Exception("Correo inválido: $m");
        $smtp = new SmtpCliente($cfg['host'], (int)$cfg['puerto'], $cfg['seguridad'], $cfg['usuario'],
            preg_replace('/\s+/', '', correoDescifrar($cfg['clave_cifrada'])), !empty($cfg['verificar_ssl']));
        $bcc = correoCopiaOculta($cfg, array_merge($paraLista, $ccLista));
        $smtp->enviar($cfg['remitente_email'], (string)($cfg['remitente_nombre'] ?? ''), $para, $asunto, $html, $texto, $adjuntos,
            $cfg['responder_a'] ?: null, $bcc ? implode(', ', $bcc) : null, implode(', ', $ccLista));
        correoRegistrar($pdo, $cid, $tipo, $refId, $para, $asunto, true, null, $usuario);
        return true;
    } catch (Throwable $e) {
        correoRegistrar($pdo, $cid, $tipo, $refId, $para, $asunto, false, $e->getMessage(), $usuario);
        throw new Exception($e->getMessage());
    }
}

/** Cliente SMTP mínimo: STARTTLS (tls), SMTPS (ssl) o sin cifrado; AUTH LOGIN; MIME con HTML, texto y adjuntos. */
class SmtpCliente
{
    private $s;
    private array $log = [];

    public function __construct(private string $host, private int $puerto, private string $seguridad, private string $usuario, private string $clave, private bool $verificarSsl = false) {}

    private function leer(): string
    {
        $r = '';
        while (($l = fgets($this->s, 515)) !== false) {
            $r .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') break;   // última línea de la respuesta
        }
        $this->log[] = 'S: ' . trim($r);
        return $r;
    }

    private function cmd(string $c, array $esperado, bool $ocultar = false): string
    {
        fwrite($this->s, $c . "\r\n");
        $this->log[] = 'C: ' . ($ocultar ? '***' : $c);
        $r = $this->leer();
        if (!in_array((int)substr($r, 0, 3), $esperado, true)) {
            throw new Exception("El servidor SMTP respondió: " . trim(preg_replace('/\s+/', ' ', $r)));
        }
        return $r;
    }

    private static function encab(string $t): string
    {
        return preg_match('/[^\x20-\x7e]/', $t) ? '=?UTF-8?B?' . base64_encode($t) . '?=' : $t;
    }

    public function enviar(string $de, string $deNombre, string $para, string $asunto, string $html, string $texto, array $adjuntos = [], ?string $responderA = null, ?string $cco = null, string $cc = ''): void
    {
        // Igual que el sistema que ya funciona en este hosting: sin verificar el certificado salvo que se active
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $this->verificarSsl, 'verify_peer_name' => $this->verificarSsl,
            'allow_self_signed' => !$this->verificarSsl, 'SNI_enabled' => true, 'peer_name' => $this->host]]);
        $dest = ($this->seguridad === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->puerto;
        $this->s = @stream_socket_client($dest, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->s) throw new Exception("No se pudo conectar a {$this->host}:{$this->puerto} ($errstr).");
        stream_set_timeout($this->s, 30);
        try {
            $r = $this->leer();
            if ((int)substr($r, 0, 3) !== 220) throw new Exception("El servidor SMTP no respondió correctamente.");
            $yo = preg_replace('/[^a-z0-9.-]/i', '', preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '') ?: (gethostname() ?: 'localhost')) ?: 'localhost';
            $ehlo = $this->cmd("EHLO $yo", [250]);
            if ($this->seguridad === 'tls') {
                $this->cmd('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($this->s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new Exception("No se pudo iniciar la conexión segura (STARTTLS).");
                }
                $ehlo = $this->cmd("EHLO $yo", [250]);
            }
            if ($this->usuario !== '') {
                $this->cmd('AUTH LOGIN', [334]);
                $this->cmd(base64_encode($this->usuario), [334], true);
                try {
                    $this->cmd(base64_encode($this->clave), [235], true);
                } catch (Exception $e) {
                    throw new Exception("Usuario o contraseña SMTP incorrectos.");
                }
            }
            $this->cmd("MAIL FROM:<$de>", [250]);
            foreach (array_merge(correoLista($para), correoLista($cc)) as $p) $this->cmd("RCPT TO:<$p>", [250, 251]);
            foreach (correoLista((string)$cco) as $c) $this->cmd("RCPT TO:<$c>", [250, 251]);
            $this->cmd('DATA', [354]);
            $msg = $this->armar($de, $deNombre, $para, $asunto, $html, $texto, $adjuntos, $responderA, $cc);
            // Transparencia SMTP: líneas que empiezan con punto se duplican
            $msg = preg_replace('/^\./m', '..', $msg);
            fwrite($this->s, $msg . "\r\n.\r\n");
            $r = $this->leer();
            if ((int)substr($r, 0, 3) !== 250) throw new Exception("El servidor rechazó el mensaje: " . trim($r));
            @fwrite($this->s, "QUIT\r\n");
        } finally {
            @fclose($this->s);
        }
    }

    private function armar(string $de, string $deNombre, string $para, string $asunto, string $html, string $texto, array $adjuntos, ?string $responderA, string $cc = ''): string
    {
        $b1 = 'mix_' . bin2hex(random_bytes(8));
        $b2 = 'alt_' . bin2hex(random_bytes(8));
        $dominio = substr(strrchr($de, '@'), 1) ?: 'localhost';
        $h = [
            'Date: ' . date('r'),
            'From: ' . ($deNombre !== '' ? self::encab($deNombre) . " <$de>" : $de),
            'To: ' . implode(', ', array_map(fn($m) => "<$m>", correoLista($para))),
            'Subject: ' . self::encab($asunto),
            'Message-ID: <' . bin2hex(random_bytes(12)) . "@$dominio>",
            'MIME-Version: 1.0',
            "Content-Type: multipart/mixed; boundary=\"$b1\"",
        ];
        if ($cc !== '') $h[] = 'Cc: ' . implode(', ', array_map(fn($m) => "<$m>", correoLista($cc)));
        if ($responderA) $h[] = 'Reply-To: ' . implode(', ', array_map(fn($m) => "<$m>", correoLista($responderA)));
        $cuerpo = "--$b1\r\nContent-Type: multipart/alternative; boundary=\"$b2\"\r\n\r\n"
            . "--$b2\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texto)) . "\r\n"
            . "--$b2\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n"
            . "--$b2--\r\n";
        foreach ($adjuntos as $a) {
            if (empty($a['ruta']) || !is_file($a['ruta'])) continue;
            $mime = function_exists('mime_content_type') ? (mime_content_type($a['ruta']) ?: 'application/octet-stream') : 'application/octet-stream';
            $nombre = self::encab($a['nombre'] ?? basename($a['ruta']));
            $cuerpo .= "--$b1\r\nContent-Type: $mime; name=\"$nombre\"\r\nContent-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"$nombre\"\r\n\r\n" . chunk_split(base64_encode(file_get_contents($a['ruta']))) . "\r\n";
        }
        $cuerpo .= "--$b1--";
        return implode("\r\n", $h) . "\r\n\r\n" . $cuerpo;
    }
}
