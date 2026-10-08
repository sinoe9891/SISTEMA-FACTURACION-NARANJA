<?php
/**
 * permisos.php — Qué opciones del menú lateral ve cada rol (Configuración → Permisos por rol, solo superadmin).
 * Solo restringe: una opción oculta desaparece del menú y su página (y sus páginas hijas) redirige al inicio.
 * Lo que el código ya prohíbe a un rol (p. ej. Usuarios para el facturador) no se puede habilitar desde aquí.
 * El superadmin siempre ve todo. Sin la tabla permisos_menu, todo sigue como antes.
 */
const PERMISOS_ROLES = ['admin' => 'Admin', 'facturador' => 'Facturador', 'lector' => 'Lector', 'nomina' => 'Nómina y gastos'];

/** [rol][pagina] => bool, de la tabla (en caché por petición). */
function permisosMenu(PDO $pdo): array
{
    static $m = null;
    if ($m !== null) return $m;
    $m = [];
    try {
        foreach ($pdo->query("SELECT rol, pagina, permitido FROM permisos_menu") as $r) $m[$r['rol']][$r['pagina']] = (bool)$r['permitido'];
    } catch (Throwable $e) {
        $m = [];   // tabla no instalada: sin restricciones extra
    }
    return $m;
}

/** ¿El rol ve esta opción del menú? (por defecto sí) */
function permisoMenu(PDO $pdo, string $rol, string $pagina): bool
{
    if ($rol === 'superadmin') return true;
    return permisosMenu($pdo)[$rol][$pagina] ?? true;
}

/**
 * ¿El código permite a este rol usar la página? (lo que aquí es «no» no se puede habilitar desde la configuración).
 * Refleja las restricciones de cada página: Configuración y Cobros por correo son de administradores; el rol
 * Nómina y gastos tiene Personal, Pagos y gastos y Bancos (ROL_NOMINA_ARCHIVOS en session.php).
 */
function permisoDisponible(string $rol, string $pagina): bool
{
    $soloSuper = ['configuracion_permisos', 'empresas', 'seleccionar_cliente'];
    $soloAdmin = ['configuracion_firmas', 'configuracion_documentos', 'configuracion_cai', 'configuracion_mensajes', 'configuracion_correo', 'usuarios', 'respaldos', 'cobros_programados', 'bouchers', 'pagos_nomina', 'socios', 'licencias'];
    if ($pagina === 'dashboard' || in_array($pagina, $soloSuper, true)) return false;
    if ($rol === 'nomina') return defined('ROL_NOMINA_ARCHIVOS') && in_array($pagina, ROL_NOMINA_ARCHIVOS, true);
    if ($rol !== 'admin' && in_array($pagina, $soloAdmin, true)) return false;
    return true;
}
