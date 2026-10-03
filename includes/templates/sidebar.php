<?php
/**
 * sidebar.php — Menú lateral (offcanvas en móvil, fijo en escritorio).
 * Se incluye desde header.php; usa $menuLateral y $paginaActual de menu.php.
 * Las variables de los bucles llevan prefijo __sb para no pisar las de la página.
 */
?>
	<!-- ══════════════ SIDEBAR (offcanvas en móvil, fijo en escritorio) ══════════════ -->
	<aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="appSidebar" aria-labelledby="appSidebarLabel">

		<div class="d-flex align-items-center">
			<a class="app-sidebar-brand flex-grow-1" href="./dashboard" id="appSidebarLabel">
				<img src="<?= htmlspecialchars($navbarLogo) ?>" alt="Logo">
				<span>
					<span class="app-brand-name"><?= htmlspecialchars($alias) ?></span>
					<span class="app-brand-sub">Sistema de Facturación</span>
				</span>
			</a>
			<button type="button" class="btn-close d-lg-none me-3" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar"
				aria-label="Cerrar menú"></button>
		</div>

		<a class="app-sidebar-estab" href="./seleccionar_establecimiento" title="Cambiar establecimiento">
			<i class="bi bi-shop"></i>
			<span class="text-truncate"><?= htmlspecialchars($nombre_establecimiento) ?></span>
			<small>Cambiar</small>
		</a>

		<nav class="app-nav" aria-label="Menú principal">
			<?php foreach ($menuLateral as $__sbSeccion => $__sbItems): ?>
				<?php if (!$__sbItems) continue; ?>
				<?php if ($__sbSeccion !== ''): ?>
					<div class="app-nav-label"><?= htmlspecialchars($__sbSeccion) ?></div>
				<?php endif; ?>
				<?php foreach ($__sbItems as $__sbItem): [$__sbHref, $__sbIcono, $__sbTexto, $__sbHijas] = $__sbItem;
					$__sbActivo = ($__sbHref === $paginaActual || in_array($paginaActual, $__sbHijas, true)); ?>
					<a class="app-nav-link<?= $__sbActivo ? ' active' : '' ?>" href="<?= $__sbHref ?>"
						<?= $__sbActivo ? 'aria-current="page"' : '' ?>>
						<i class="bi <?= $__sbIcono ?>"></i><span><?= htmlspecialchars($__sbTexto) ?></span>
						<?php if (!empty($__sbItem[4])): ?><span class="badge rounded-pill bg-warning text-dark ms-auto" title="Productos por reponer"><?= (int)$__sbItem[4] ?></span><?php endif; ?>
					</a>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</nav>

		<div class="app-sidebar-user">
			<span class="app-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr(USUARIO_NOMBRE, 0, 1))) ?></span>
			<div class="flex-grow-1 overflow-hidden">
				<div class="app-user-name"><?= htmlspecialchars(USUARIO_NOMBRE) ?></div>
				<span class="app-role-badge"><?= htmlspecialchars(USUARIO_ROL) ?></span>
			</div>
			<a class="app-nav-link app-nav-danger mb-0" href="logout" title="Cerrar sesión" aria-label="Cerrar sesión">
				<i class="bi bi-box-arrow-right"></i>
			</a>
		</div>
	</aside>
<?php unset($__sbSeccion, $__sbItems, $__sbItem, $__sbHref, $__sbIcono, $__sbTexto, $__sbHijas, $__sbActivo); ?>
