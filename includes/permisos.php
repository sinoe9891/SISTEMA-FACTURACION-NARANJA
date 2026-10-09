<?php
/**
 * permisos.php — Qué puede ver y usar cada rol (Configuración → Permisos, solo superadmin).
 * Cada opción del menú (y cada pestaña de Configuración) tiene un interruptor por rol:
 *   encendido = el rol la ve en el menú, abre su página y usa sus acciones; apagado = no.
 * Si el superadmin nunca tocó un interruptor, vale lo de siempre (permisoDefecto): así nada cambia hasta que se mueva.
 * Fijos (sin interruptor): Inicio siempre; Empresas, Cambiar empresa, Permisos y Respaldos son del superadmin
 * (Respaldos, además, del admin de la empresa dueña). Dentro de cada página, eliminar y anular siguen siendo de administradores.
 */
const PERMISOS_ROLES = ['admin' => 'Admin', 'facturador' => 'Facturador', 'lector' => 'Lector', 'nomina' => 'Nómina y gastos'];

/** Opciones que no se pueden encender para otros roles. */
const PERMISOS_FIJAS = ['configuracion_permisos', 'empresas', 'seleccionar_cliente', 'respaldos'];

/** Pestañas de Configuración: cada una con su interruptor. [página => [icono, texto, páginas y acciones que le pertenecen]] */
const PERMISOS_CONFIG = [
    'configuracion_cai' => ['bi-key', 'CAI', ['crear_cai', 'editar_cai', 'guardar_cai', 'guardar_edicion_cai']],
    'configuracion_mensajes' => ['bi-envelope', 'Mensajes y pagos', []],
    'configuracion_correo' => ['bi-envelope-at', 'Correo', []],
    'configuracion_firmas' => ['bi-pen', 'Firmas', ['firmante_accion']],
    'configuracion_documentos' => ['bi-folder-check', 'Documentos', ['documento_accion']],
    'configuracion_tasa' => ['bi-currency-exchange', 'Tasa del dólar', []],
];

/**
 * Páginas sin menú y acciones (clientes/<empresa>/includes/) → opciones a las que pertenecen.
 * Se pueden usar si el rol tiene encendida al menos una de esas opciones.
 */
const PERMISOS_ACCIONES = [
    // Facturación
    'guardar_factura' => ['generar_factura'], 'ver_factura' => ['lista_facturas', 'generar_factura', 'cuentas_cobrar', 'contratos'],
    'editar_factura' => ['lista_facturas'], 'guardar_factura_editada' => ['lista_facturas'], 'facturas_xlsx' => ['lista_facturas'],
    'procesar_accion_factura' => ['lista_facturas', 'generar_factura', 'cuentas_cobrar', 'contratos', 'cobros_programados'],
    // Contratos
    'editar_contrato' => ['contratos'], 'facturas_contrato' => ['contratos'], 'generar_recibo' => ['contratos'], 'recibo_pdf' => ['contratos', 'cuentas_cobrar'],
    'contrato_guardar' => ['crear_contrato'], 'contrato_actualizar' => ['contratos'], 'contrato_actualizar_periodo' => ['contratos'], 'contrato_cancelar' => ['contratos'],
    'contrato_eliminar' => ['contratos'], 'contrato_desvincular_factura' => ['contratos'], 'contrato_vincular_factura' => ['contratos'], 'contrato_verificar' => ['contratos', 'crear_contrato'],
    'contrato_plan_accion' => ['contratos', 'crear_contrato'], 'facturas_sin_contrato' => ['contratos'], 'recibo_guardar' => ['contratos'],
    'anticipo_accion' => ['contratos', 'cuentas_cobrar'],
    // Cobros
    'estado_cuenta' => ['cuentas_cobrar'], 'cxc_accion' => ['cuentas_cobrar', 'contratos', 'lista_facturas'],
    'cobro_accion' => ['cobros_programados'], 'nuevo_cobro' => ['cobros_programados'], 'cobros_filas' => ['cobros_programados'],
    // Pagos y gastos
    'gasto_ver' => ['gastos', 'cuentas_pagar'], 'gasto_guardar' => ['gastos'], 'gasto_actualizar' => ['gastos', 'cuentas_pagar'],
    'gasto_eliminar' => ['gastos', 'cuentas_pagar'], 'gasto_marcar_pagado' => ['gastos', 'cuentas_pagar'], 'gastos_exportar' => ['gastos'],
    'gasto_archivo' => ['gastos', 'cuentas_pagar', 'colaboradores', 'pagos_nomina', 'bouchers'],
    'boucher_lote' => ['bouchers', 'pagos_nomina', 'colaboradores'], 'boucher_pdf' => ['bouchers', 'pagos_nomina', 'colaboradores', 'gastos'],
    'licencia_accion' => ['licencias'],
    'categoria_gasto_guardar' => ['categorias_gastos'], 'categoria_gasto_actualizar' => ['categorias_gastos'], 'categoria_gasto_eliminar' => ['categorias_gastos'],
    // Bancos
    'banco_cuenta' => ['bancos'], 'movimiento_archivo' => ['bancos'], 'banco_accion' => ['bancos', 'cheques', 'configuracion_tasa'],
    'tarjeta_guardar' => ['tarjetas'], 'tarjeta_actualizar' => ['tarjetas'], 'tarjeta_eliminar' => ['tarjetas'],
    'activo_accion' => ['activos'], 'socio_accion' => ['socios'], 'socio_archivo' => ['socios'],
    // Reportes
    'proyeccion_desglose_real' => ['proyeccion'],
    // Personal
    'colaborador_ver' => ['colaboradores'], 'colaborador_reporte' => ['colaboradores'], 'colaborador_recibo_pdf' => ['colaboradores', 'pagos_nomina', 'gastos'],
    'colaborador_guardar' => ['colaboradores'], 'colaborador_actualizar' => ['colaboradores'], 'colaborador_firma' => ['colaboradores'],
    'colaborador_honorarios_guardar' => ['colaboradores'], 'colaborador_salario' => ['colaboradores'], 'colaborador_cuotas_info' => ['colaboradores', 'pagos_nomina'],
    'colaborador_pago_guardar' => ['colaboradores', 'pagos_nomina'], 'pagos_nomina_exportar' => ['pagos_nomina'], 'nomina_pago_accion' => ['pagos_nomina', 'colaboradores', 'gastos'],
    'prestamo_guardar' => ['colaboradores'], 'prestamo_editar' => ['colaboradores'], 'prestamo_cancelar' => ['colaboradores'], 'prestamo_eliminar' => ['colaboradores'],
    'prestamo_cuota_pagar' => ['colaboradores'], 'prestamo_cuota_editar' => ['colaboradores'],
    'correo_accion' => ['configuracion_correo', 'pagos_nomina', 'colaboradores', 'gastos'],
    // Catálogo
    'crear_cliente' => ['clientes'], 'editar_cliente' => ['clientes'], 'guardar_cliente' => ['clientes'], 'actualizar_cliente' => ['clientes'], 'eliminar_cliente' => ['clientes'],
    'cliente_contactos' => ['clientes'],
    'prod_guardar' => ['productos'], 'productos_editar' => ['productos'], 'productos_borrar' => ['productos'],
    'productos_clientes_agregar' => ['productos_clientes'], 'productos_clientes_editar' => ['productos_clientes'], 'productos_clientes_eliminar' => ['productos_clientes'],
    // Ventas e inventario
    'pos_accion' => ['pos', 'pos_turnos'], 'inventario_kardex' => ['inventario'], 'inventario_reportes' => ['inventario'], 'inventario_accion' => ['inventario', 'inventario_traslados'],
    // Usuarios
    'usuario_guardar' => ['usuarios'], 'usuario_actualizar' => ['usuarios'], 'usuario_eliminar' => ['usuarios'], 'usuario_cambiar_clave' => ['usuarios'],
    'usuario_establecimientos_get' => ['usuarios'], 'usuario_establecimientos_guardar' => ['usuarios'],
];

/** Páginas que se abren en el navegador (al bloquearlas se redirige); lo demás son acciones (responden 403). */
const PERMISOS_VISTAS = ['ver_factura', 'editar_factura', 'editar_contrato', 'facturas_contrato', 'generar_recibo', 'estado_cuenta', 'gasto_ver', 'banco_cuenta',
    'colaborador_ver', 'colaborador_reporte', 'crear_cliente', 'editar_cliente', 'inventario_kardex', 'inventario_reportes', 'crear_cai', 'editar_cai', 'nuevo_cobro'];

/** Páginas del menú lateral (cada una es su propia opción). */
const PERMISOS_PAGINAS = ['generar_factura', 'lista_facturas', 'contratos', 'crear_contrato', 'cuentas_cobrar', 'cobros_programados', 'cuentas_pagar', 'gastos',
    'licencias', 'bouchers', 'categorias_gastos', 'bancos', 'cheques', 'tarjetas', 'activos', 'socios', 'financiero', 'estados_financieros', 'balance_general',
    'proyeccion', 'colaboradores', 'pagos_nomina', 'clientes', 'productos', 'productos_clientes', 'pos', 'pos_turnos', 'inventario', 'inventario_traslados', 'usuarios'];

/** Lo de siempre (antes de los interruptores). */
function permisoDefecto(string $rol, string $pagina): bool
{
    $soloAdmin = ['configuracion_firmas', 'configuracion_documentos', 'configuracion_cai', 'configuracion_mensajes', 'configuracion_correo', 'usuarios', 'cobros_programados', 'bouchers', 'pagos_nomina', 'socios', 'licencias', 'configuracion', 'configuracion_tasa'];
    if ($rol === 'nomina') return defined('ROL_NOMINA_ARCHIVOS') && in_array($pagina, ROL_NOMINA_ARCHIVOS, true);
    if ($rol !== 'admin' && in_array($pagina, $soloAdmin, true)) return false;
    return true;
}

/** [rol][pagina] => bool, de la tabla (en caché por petición). */
function permisosMenu(PDO $pdo): array
{
    static $m = null;
    if ($m !== null) return $m;
    $m = [];
    try {
        foreach ($pdo->query("SELECT rol, pagina, permitido FROM permisos_menu") as $r) $m[$r['rol']][$r['pagina']] = (bool)$r['permitido'];
    } catch (Throwable $e) {
        $m = [];   // tabla no instalada: lo de siempre
    }
    return $m;
}

/** ¿La opción tiene interruptor? (las fijas y el Inicio no) */
function permisoConfigurable(string $pagina): bool
{
    return $pagina !== 'dashboard' && !in_array($pagina, PERMISOS_FIJAS, true);
}

/** ¿El rol ve y usa esta opción? El superadmin, siempre. */
function permisoMenu(PDO $pdo, string $rol, string $pagina): bool
{
    if ($rol === 'superadmin' || $pagina === 'dashboard') return true;
    if ($pagina === 'respaldos') return function_exists('respaldoPuede') && respaldoPuede();
    if (!permisoConfigurable($pagina)) return false;
    // «Configuración» del menú: se ve si alguna de sus pestañas está encendida
    if ($pagina === 'configuracion') {
        foreach (array_keys(PERMISOS_CONFIG) as $p) if (permisoMenu($pdo, $rol, $p)) return true;
        return function_exists('respaldoPuede') && respaldoPuede();
    }
    return permisosMenu($pdo)[$rol][$pagina] ?? permisoDefecto($rol, $pagina);
}

/** Para el usuario actual. */
function permisoPuede(PDO $pdo, string $pagina): bool
{
    return defined('USUARIO_ROL') && permisoMenu($pdo, USUARIO_ROL, $pagina);
}

/** Compatibilidad: antes había opciones que el código no dejaba encender; ahora todas las configurables se pueden. */
function permisoDisponible(string $rol, string $pagina): bool
{
    return permisoConfigurable($pagina);
}

/** ¿El script pertenece a alguna opción con interruptor? */
function permisoScriptConocido(string $script): bool
{
    if (isset(PERMISOS_ACCIONES[$script]) || in_array($script, PERMISOS_PAGINAS, true)) return true;
    foreach (PERMISOS_CONFIG as $p => [, , $hijas]) if ($script === $p || in_array($script, $hijas, true)) return true;
    return false;
}

/**
 * Guardia de páginas y acciones (la llama session.php en cada petición). Devuelve null si se puede,
 * o la opción que el rol tiene apagada. Lo que no pertenece a ninguna opción no se bloquea aquí.
 */
function permisoScriptBloqueado(PDO $pdo, string $script): ?string
{
    if (!defined('USUARIO_ROL') || USUARIO_ROL === 'superadmin') return null;
    $opciones = PERMISOS_ACCIONES[$script] ?? (in_array($script, PERMISOS_PAGINAS, true) ? [$script] : null);
    if ($opciones === null) foreach (PERMISOS_CONFIG as $p => [, , $hijas]) if ($script === $p || in_array($script, $hijas, true)) { $opciones = [$p]; break; }
    if ($opciones === null) return null;
    foreach ($opciones as $o) if (permisoMenu($pdo, USUARIO_ROL, $o)) return null;
    return $opciones[0];
}

/** Guarda un interruptor y lo deja en la bitácora (si cambió). Devuelve true si cambió. */
function permisoGuardar(PDO $pdo, string $rol, string $pagina, bool $permitido, int $usuario): bool
{
    if (!isset(PERMISOS_ROLES[$rol]) || !permisoConfigurable($pagina)) throw new Exception("Esa opción no se puede cambiar.");
    $antes = permisoMenu($pdo, $rol, $pagina);
    $pdo->prepare("INSERT INTO permisos_menu (rol, pagina, permitido, actualizado_por) VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE permitido = VALUES(permitido), actualizado_por = VALUES(actualizado_por)")
        ->execute([$rol, $pagina, $permitido ? 1 : 0, $usuario]);
    if ($antes === $permitido) return false;
    try {
        $pdo->prepare("INSERT INTO permisos_bitacora (rol, pagina, antes, despues, usuario_id, ip) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$rol, $pagina, $antes ? 1 : 0, $permitido ? 1 : 0, $usuario, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
    } catch (Throwable $e) {
        // sin la tabla de bitácora el cambio igual se guarda
    }
    return true;
}

/** Últimos cambios de permisos (con el nombre de quien los hizo). */
function permisosBitacora(PDO $pdo, int $limite = 100): array
{
    try {
        return $pdo->query("SELECT b.*, u.nombre AS usuario FROM permisos_bitacora b LEFT JOIN usuarios u ON u.id = b.usuario_id ORDER BY b.id DESC LIMIT " . max(1, $limite))->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}
