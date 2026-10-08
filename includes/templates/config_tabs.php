<?php
/**
 * config_tabs.php — Pestañas horizontales de Configuración (se incluye después de header.php en cada página de configuración).
 * Cada pestaña es su página; al guardar se queda en la misma. Las pestañas internas de una página se recuerdan al recargar.
 */
$__cfgTabs = array_values(array_filter([
    $__menuEsAdmin ? ['configuracion_cai', 'bi-key', 'CAI', ['crear_cai', 'editar_cai']] : null,
    $__menuEsAdmin ? ['configuracion_mensajes', 'bi-envelope', 'Mensajes y pagos', []] : null,
    $__menuEsAdmin ? ['configuracion_correo', 'bi-envelope-at', 'Correo', []] : null,
    $__menuEsAdmin ? ['configuracion_firmas', 'bi-pen', 'Firmas', []] : null,
    $__menuEsAdmin ? ['configuracion_documentos', 'bi-folder-check', 'Documentos', []] : null,
    $__menuEsAdmin ? ['configuracion_tasa', 'bi-currency-exchange', 'Tasa del dólar', []] : null,
    function_exists('respaldoPuede') && respaldoPuede() ? ['respaldos', 'bi-database-check', 'Respaldos', []] : null,
    !empty($es_superadmin) ? ['configuracion_permisos', 'bi-shield-lock', 'Permisos', []] : null,
]));
?>
<div class="app-card app-tabs-card app-config-tabs mb-3">
    <nav class="nav flex-nowrap app-tabs-linea" aria-label="Secciones de configuración">
        <?php foreach ($__cfgTabs as [$__p, $__ic, $__t, $__sub]): $__act = $paginaActual === $__p || in_array($paginaActual, $__sub, true); ?>
            <a class="nav-link text-nowrap <?= $__act ? 'active' : '' ?>" href="<?= $__p ?>"<?= $__act ? ' aria-current="page"' : '' ?>><i class="bi <?= $__ic ?> me-2" aria-hidden="true"></i><?= htmlspecialchars($__t) ?></a>
        <?php endforeach; ?>
    </nav>
</div>
<script>
// Pestañas internas de la página (p. ej. en Correo): al guardar y recargar se vuelve a la misma
document.addEventListener('DOMContentLoaded', () => {
    const k = 'cfgTab:' + location.pathname;
    // Si la dirección ya dice qué pestaña abrir (?tab=…), manda la dirección
    if (!new URLSearchParams(location.search).has('tab')) try { const t = sessionStorage.getItem(k), el = t && document.querySelector('[data-bs-toggle="tab"][data-bs-target="' + t + '"], [data-bs-toggle="pill"][data-bs-target="' + t + '"]'); if (el && window.bootstrap) bootstrap.Tab.getOrCreateInstance(el).show(); } catch (e) {}
    document.querySelectorAll('[data-bs-toggle="tab"], [data-bs-toggle="pill"]').forEach(el => el.addEventListener('shown.bs.tab', () => { try { sessionStorage.setItem(k, el.dataset.bsTarget); } catch (e) {} }));
});
</script>
<?php unset($__cfgTabs, $__p, $__ic, $__t, $__sub, $__act); ?>
