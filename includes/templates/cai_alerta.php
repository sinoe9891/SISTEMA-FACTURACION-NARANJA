<?php
// Alerta del CAI (rango agotado / vencido / por agotarse o vencer). Recibe $caiAlerta (caiAlerta()) y, opcional, $caiAlertaClase.
if (!empty($caiAlerta)):
    $__rojo = $caiAlerta['nivel'] === 'rojo';
?>
<div class="alert <?= $__rojo ? 'alert-danger' : 'alert-warning' ?> d-flex align-items-start gap-3 mb-3 rounded-3 <?= $caiAlertaClase ?? '' ?>" role="alert" id="alertaCai">
    <i class="bi <?= !empty($caiAlerta['bloquea']) ? 'bi-slash-circle-fill' : 'bi-exclamation-triangle-fill' ?> fs-4"></i>
    <div class="flex-grow-1">
        <div class="fw-bold"><?= htmlspecialchars($caiAlerta['titulo']) ?></div>
        <div class="small"><?= $caiAlerta['detalle'] /* ya viene escapado: solo lleva etiquetas <strong> propias */ ?></div>
    </div>
    <?php if (permisoPuede($pdo, 'configuracion_cai')): ?><a href="configuracion_cai" class="btn btn-sm <?= $__rojo ? 'btn-danger' : 'btn-warning' ?> text-nowrap align-self-center"><i class="bi bi-key me-1"></i>Registrar CAI</a><?php endif; ?>
</div>
<?php endif; ?>
