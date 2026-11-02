<?php require_once __DIR__ . '/../version.php'; ?>
<footer class="app-footer">
  © <?= date('Y') ?> <?= htmlspecialchars($clienteNombre ?? APP_NOMBRE) ?>
  · <?= APP_NOMBRE ?> v<?= APP_VERSION ?> · Desarrollado por
  <a href="https://naranjaymediahn.com" target="_blank" rel="noopener">Naranja &amp; Media</a>
</footer>
<!-- Tablas con data-paginar: 10 por página (ver clientes/js/app-tabla.js) -->
<!-- <select data-buscar>: buscador con autocompletado (clientes y productos en facturas, cobros) -->
<script src="../../clientes/js/select-buscar.js?v=<?= @filemtime(__DIR__ . '/../../clientes/js/select-buscar.js') ?>"></script>
<script src="../../clientes/js/app-tabla.js?v=<?= @filemtime(__DIR__ . '/../../clientes/js/app-tabla.js') ?>"></script>
