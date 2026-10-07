<?php if (!isset($cobros, $estados, $cid)) exit; ?>
                    <?php if (!$cobros): ?><tr><td colspan="8" class="text-center text-muted py-4">No hay correos que coincidan con los filtros.</td></tr><?php endif; ?>
                    <?php foreach ($cobros as $c): [$etq, $col] = $estados[$c['estado']] ?? [$c['estado'], 'muted']; ?>
                        <tr>
                            <td><?php if (cobroPuedeEliminar($c)): ?><input type="checkbox" class="form-check-input cb-seleccion" value="<?= (int)$c['id'] ?>" aria-label="Seleccionar correo #<?= (int)$c['id'] ?>"><?php endif; ?></td>
                            <td><?= (int)$c['id'] ?></td>
                            <td><a href="estado_cuenta?receptor_id=<?= (int)$c['receptor_id'] ?>"><?= htmlspecialchars($c['cliente']) ?></a>
                                <div class="small text-muted"><?= htmlspecialchars(mb_strimwidth($c['asunto'], 0, 60, '…')) ?></div></td>
                            <td class="small"><span class="font-monospace"><?= htmlspecialchars(trim(($c['facturas'] ?? '') . (($c['facturas'] ?? '') && ($c['recibos'] ?? '') ? ', ' : '') . ($c['recibos'] ?? ''))) ?></span>
                                <?php if (!empty($c['pagos_plan'])): ?><div class="text-muted"><i class="bi bi-calendar2-check"></i> Recordatorio de <?= (int)$c['pagos_plan'] ?> pago<?= $c['pagos_plan'] > 1 ? 's' : '' ?> del plan</div><?php endif; ?></td>
                            <td class="small"><?= htmlspecialchars($c['para']) ?><?= $c['cc'] ? '<div class="text-muted">CC: ' . htmlspecialchars($c['cc']) . '</div>' : '' ?></td>
                            <td class="small text-nowrap"><?= date('d/m/Y g:i a', strtotime($c['programado_para'])) ?>
                                <?= $c['enviado_en'] ? '<div class="text-success">Enviado ' . date('d/m g:i a', strtotime($c['enviado_en'])) . '</div>' : '' ?></td>
                            <td><span class="app-badge app-badge-<?= $col ?>"><?= $etq ?></span><?= (int)$c['prueba'] ? ' <span class="app-badge app-badge-warning">Prueba</span>' : '' ?>
                                <?= $c['error'] && $c['estado'] !== 'enviado' ? '<div class="small text-danger">' . htmlspecialchars(mb_strimwidth($c['error'], 0, 80, '…')) . '</div>' : '' ?></td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-sm btn-outline-secondary btn-ver" data-id="<?= (int)$c['id'] ?>" title="Ver lo que se envió: correo, PDF e intentos"><i class="bi bi-eye"></i></button>
                                <?php if (in_array($c['estado'], ['programado', 'error'], true)): ?>
                                    <button class="btn btn-sm btn-outline-primary btn-accion" data-accion="enviar_ya" data-id="<?= (int)$c['id'] ?>" title="Enviar ahora"><i class="bi bi-send"></i></button>
                                    <button class="btn btn-sm btn-outline-secondary btn-editar" data-id="<?= (int)$c['id'] ?>" title="Editar destinatarios, mensaje y fecha"><i class="bi bi-pencil"></i></button>
                                    <button class="btn btn-sm btn-outline-danger btn-accion" data-accion="cancelar" data-id="<?= (int)$c['id'] ?>" title="Cancelar"><i class="bi bi-x-lg"></i></button>
                                <?php endif; ?>
                                <?php if (in_array($c['estado'], ['enviado', 'cancelado', 'error'], true)): ?>
                                    <button class="btn btn-sm btn-outline-success btn-reenviar" data-id="<?= (int)$c['id'] ?>" data-para="<?= htmlspecialchars($c['para']) ?>" data-cc="<?= htmlspecialchars((string)$c['cc']) ?>" data-prueba="<?= (int)$c['prueba'] ?>" title="Reenviar (mismo mensaje y mismos PDF)"><i class="bi bi-arrow-repeat"></i></button>
                                <?php endif; ?>
                                <?php if (cobroPuedeEliminar($c)): ?>
                                    <button class="btn btn-sm btn-outline-danger btn-accion" data-accion="eliminar" data-id="<?= (int)$c['id'] ?>" title="Eliminar"><i class="bi bi-trash"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
