# Especificación — Punto de Venta (POS)

> Módulo planificado **después de Inventario** (ver [PENDIENTES.md](PENDIENTES.md)).
> Depende de: inventario (existencias por tienda, kardex, apartados), layout común (sidebar + `app.css`) y multitenant.
> Fecha: 2026-10-03.

---

## 1. Objetivo

Vender en mostrador de forma rápida y sin errores, en **varias tiendas y varias cajas a la vez**, con:

- control de efectivo por turno de caja (apertura, movimientos, arqueo, cierre),
- cobro en efectivo (con cambio), tarjeta, transferencia, crédito o pago mixto,
- descuento de inventario en el momento de la venta y sin vender lo que no hay,
- anulaciones, devoluciones y descuentos **con autorización y bitácora**,
- documentos fiscales válidos ante el SAR (factura con CAI, nota de crédito),
- reportes de caja, ventas e inventario.

---

## 2. Conceptos y cómo encajan con lo que ya existe

| Concepto POS | Qué es | Tabla actual / nueva |
|---|---|---|
| Empresa (tenant) | Cliente del SaaS | `clientes_saas` (existe) |
| Tienda / sucursal | Lugar físico con inventario propio | `establecimientos` (existe) |
| Caja / terminal | Punto de emisión fiscal; cada una tiene su CAI y correlativo | `puntos_emision` + `cai_rangos` (existen) |
| Turno de caja | Período entre apertura y cierre de una caja por un cajero | `pos_turnos` (nueva) |
| Venta | Ticket en curso o cobrado | `pos_ventas` + `pos_venta_items` (nuevas) |
| Pago | Una o más formas de pago de una venta | `pos_venta_pagos` (nueva) |
| Documento fiscal | Factura con CAI generada al cobrar | `facturas` + `factura_items_receptor` (existen) |
| Cliente | Consumidor final o cliente con RTN | `clientes_factura` (existe) + "Consumidor Final" por empresa |
| Producto | Servicio o bien; los bienes llevan stock | `productos_clientes` + campos de inventario (ver módulo Inventario) |
| Existencias | Cantidad y reservado por producto y tienda | `inv_existencias` (módulo Inventario) |
| Kardex | Cada entrada/salida/ajuste/traslado/reserva | `inv_movimientos` (módulo Inventario) |

---

## 3. Pantallas

### 3.1 Pantalla de venta (la principal)

Diseñada para pantalla táctil y teclado/lector, usable en tablet y en celular.

- **Barra superior:** tienda, caja, cajero, turno abierto (hora de apertura), indicador de conexión, botón de bloqueo.
- **Buscador:** por nombre, SKU o **código de barras** (el lector escribe y envía Enter → se agrega solo).
- **Catálogo:** cuadrícula con categorías, favoritos/más vendidos, foto, precio y **disponible en esta tienda** (badge rojo si 0, amarillo si bajo mínimo).
- **Carrito (ticket):**
  - líneas con cantidad (+/−, editar), precio, descuento de línea, subtotal, ISV;
  - quitar línea (antes de cobrar no requiere autorización; queda en bitácora);
  - nota por línea (p. ej. talla, color, número de serie);
  - cliente: "Consumidor Final" por defecto, buscar/crear cliente con RTN;
  - totales: subtotal, descuentos, exento, gravado 15 %, gravado 18 %, ISV 15 %, ISV 18 %, **total**.
- **Acciones:** Cobrar · Poner en espera · Recuperar en espera · Apartar · Descuento global · Cancelar venta · Reimprimir último.
- **Atajos de teclado:** F2 buscar, F4 cliente, F8 en espera, F10 cobrar, Esc cancelar diálogo.

### 3.2 Cobro

- Total a pagar grande y claro.
- **Efectivo:** monto recibido, botones rápidos (exacto, L 100, L 200, L 500, L 1 000), **cambio calculado**; no permite cerrar si recibido < total (salvo pago mixto).
- **Tarjeta:** tipo (débito/crédito), marca, últimos 4 dígitos, **número de autorización/voucher** del POS bancario (obligatorio), comisión opcional.
- **Transferencia:** banco, referencia, comprobante opcional (imagen/PDF).
- **Crédito:** solo clientes con crédito autorizado; genera cuenta por cobrar.
- **Mixto:** varias formas de pago en la misma venta; el sistema muestra lo pendiente hasta cubrir el total.
- **Moneda:** HNL por defecto; si se acepta USD, tasa de cambio del día configurable y el cambio se entrega en HNL.
- Al confirmar: genera la factura con CAI de la caja, descuenta inventario, registra pagos, imprime/envía el documento.

### 3.3 Caja (turnos)

- **Apertura:** monto inicial (fondo de caja) contado por denominación; un cajero solo puede tener un turno abierto por caja.
- **Movimientos de efectivo:** entradas (p. ej. fondo adicional) y salidas (retiros a bóveda, pagos menores) con motivo y autorización.
- **Corte X (parcial):** resumen del turno sin cerrar (ventas por forma de pago, anulaciones, devoluciones, efectivo esperado).
- **Cierre / Corte Z:** el cajero cuenta el efectivo por denominación **sin ver el esperado** (arqueo ciego), el sistema calcula la **diferencia** (sobrante/faltante), se requiere justificación si supera un umbral; el turno queda cerrado e inmutable.
- Lista de turnos con estado, cajero, diferencias e impresión del corte.

### 3.4 Ventas e historial

- Buscar por fecha, caja, cajero, cliente, número de factura, forma de pago, estado.
- Ver detalle, **reimprimir** (marcado como "COPIA"), enviar por correo/WhatsApp.
- Acciones post-venta (con autorización): **devolución** total o parcial y **anulación** (ver §5).

### 3.5 Apartados (layaway)

- Reservar productos para un cliente con **anticipo**; el stock pasa a "reservado" (no disponible para otras cajas).
- Abonos parciales, fecha de vencimiento, liberación automática del stock al vencer (con aviso).
- Al completar el pago se factura y se entrega.

### 3.6 Configuración del POS (por empresa / tienda / caja)

- Cajas y su CAI asignado; impresora por caja (térmica 80 mm o carta/PDF); cajón de dinero.
- Formas de pago habilitadas, comisión por tarjeta, tasa de cambio USD.
- Denominaciones de billetes y monedas.
- Umbrales: descuento máximo sin autorización, diferencia de caja tolerada, tiempo de vencimiento de apartados y de ventas en espera.
- Permitir o no **venta sin stock** (por defecto **no**).

---

## 4. Roles y permisos

| Acción | Cajero | Supervisor | Admin |
|---|:-:|:-:|:-:|
| Abrir/cerrar su turno, vender, cobrar | ✔ | ✔ | ✔ |
| Quitar línea antes de cobrar | ✔ (bitácora) | ✔ | ✔ |
| Descuento hasta el umbral | ✔ | ✔ | ✔ |
| Descuento sobre el umbral, cambiar precio | ✖ (pide PIN supervisor) | ✔ | ✔ |
| Retiros/entradas de efectivo | ✖ (PIN) | ✔ | ✔ |
| Devoluciones y anulaciones | ✖ (PIN) | ✔ | ✔ |
| Reabrir / ver turnos de otros | ✖ | ✔ (su tienda) | ✔ |
| Configuración POS, precios, CAI | ✖ | ✖ | ✔ |

- La autorización se hace con **PIN del supervisor** en la misma pantalla (no se comparte la contraseña), con límite de intentos.
- Todo queda en **bitácora**: quién, qué, cuándo, desde qué caja, motivo y quién autorizó.

---

## 5. Anulaciones, devoluciones y eliminaciones (reglas)

| Caso | Momento | Cómo se resuelve | Inventario | Fiscal |
|---|---|---|---|---|
| Quitar línea / vaciar carrito | Antes de cobrar | Se elimina del ticket | Libera la reserva temporal | Sin documento |
| Cancelar venta en espera | Antes de cobrar | Venta en estado `cancelada` | Libera reservas | Sin documento |
| Error al cobrar, **mismo turno y mismo día**, sin entregar | Después de cobrar | **Anular** la factura (estado `anulada`, motivo, autorización); **nunca se borra** ni se reutiliza el correlativo | Reingresa al stock | Factura anulada conservada con su correlativo |
| Devolución total o parcial | Días después | **Nota de crédito** vinculada a la factura original (con su propio CAI/correlativo de notas de crédito) | Reingresa (o va a "dañado" si no es vendible) | Nota de crédito |
| Ajuste de precio después de facturar | Días después | Nota de crédito (rebaja) o nota de débito (aumento) | Sin cambio | NC / ND |
| Reembolso | Con la devolución | Efectivo de la caja abierta, a la tarjeta o saldo a favor | — | Registrado en el turno |

Reglas generales:

- **Nunca se elimina** una venta cobrada ni una factura: solo se anula o se compensa con nota de crédito.
- Las anulaciones y notas de crédito **requieren motivo y autorización**, y se registran en `bitacora_facturas`.
- Si la venta se pagó con tarjeta, la anulación/devolución registra también la reversión del voucher.

---

## 6. Inventario en tiempo real con varias cajas

- Al agregar un producto al carrito se crea una **reserva temporal** (p. ej. 15 min) para que otra caja no venda la misma última unidad; se renueva mientras el ticket está activo y se libera al quitarlo, cancelar o expirar.
- La reserva y la venta usan una **operación atómica** en MySQL:
  `UPDATE inv_existencias SET reservado = reservado + :n WHERE producto_id = :p AND establecimiento_id = :t AND (cantidad - reservado) >= :n`
  — si afecta 0 filas, no hay stock suficiente.
- Al cobrar, en una sola transacción: se convierte la reserva en salida (`cantidad -= n`, `reservado -= n`), se registra el kardex, la factura, los pagos y el movimiento de caja.
- **Actualización de pantallas:** cada caja consulta cada 5–10 s las existencias de los productos visibles y del carrito (el hosting actual no admite WebSockets). La protección contra sobreventa no depende de ese refresco: la garantiza la operación atómica.
- **Disponibilidad en otras tiendas:** desde la ficha del producto se ve el stock por tienda; se puede pedir un **traslado** (sale de una tienda "en tránsito" y entra al recibirse).

---

## 7. Documentos e impresión

- **Factura** con todos los datos fiscales (ver §8). Tamaño ticket 80 mm o carta/PDF según la caja.
- **Copia / reimpresión** marcada "COPIA".
- **Nota de crédito** y, si se usa, nota de débito.
- **Recibo de apartado y de abonos.**
- **Corte X y Corte Z** imprimibles.
- **Comprobante de retiro/entrada de efectivo.**
- Envío por correo o WhatsApp (PDF) además de impreso.

---

## 8. Cumplimiento fiscal (Honduras — SAR)

Base: Reglamento del Régimen de Facturación, Acuerdo 481-2017.

Confirmado en fuentes:

- Documentos reconocidos incluyen factura, ticket de máquina registradora, notas de crédito y de débito, comprobantes de retención y guías de remisión ([grupoasesoreshn.com](https://grupoasesoreshn.com/regimen-de-facturacion-en-honduras/)).
- La **nota de crédito** es un documento complementario **vinculado a un comprobante fiscal**, que permite anular operaciones, aceptar devoluciones y conceder descuentos posteriores a la emisión ([Acuerdo 481-2017, RSM](https://www.rsm.global/honduras/sites/default/files/media/acuerdo-481-2017-facturacion.pdf)).
- El **ticket** de máquina registradora lleva RTN, nombre o razón social, nombre comercial y dirección del establecimiento, con **numeración correlativa** (mínimo 4 dígitos) y copia en **cinta de auditoría** (mismo acuerdo).
- Honduras sigue con el sistema de **CAI** administrado por el SAR; la factura electrónica avanza por etapas ([koddix.com](https://www.koddix.com/blog/facturacion-electronica-honduras)).

**A confirmar con el contador antes de implementar** (fuentes no oficiales o no verificadas):

- Si el POS emitirá **factura con CAI por caja** (autoimpresor, lo que ya hace el sistema) o **ticket de máquina registradora** (requiere equipo autorizado).
- Monto a partir del cual el **RTN del adquirente** es obligatorio (una fuente no oficial menciona L 100 — [koddix.com](https://koddix.com/blog/requisitos-sar-facturacion-honduras)).
- **CAI separado** para notas de crédito y su rango.
- Plazo de **conservación** de documentos (una fuente menciona 5 años; el reglamento remite al Código Tributario).
- Requisitos de anulación (conservar original y copias, registro del motivo).

---

## 9. Reportes

- Ventas por día / hora / tienda / caja / cajero / forma de pago / categoría / producto.
- Productos más vendidos, sin movimiento, margen (precio vs. costo).
- Turnos: aperturas, cierres, diferencias, retiros.
- Anulaciones, devoluciones, notas de crédito y descuentos autorizados (quién autorizó).
- Libro de ventas para la declaración de ISV (gravado 15 %, gravado 18 %, exento, exonerado).
- Inventario: existencias por tienda, valorización, kardex por producto, apartados vigentes y vencidos.

---

## 10. Alertas

- Stock bajo mínimo / agotado (por tienda).
- Apartados por vencer o vencidos.
- Turnos abiertos al final del día o con diferencia de caja.
- Muchas anulaciones/descuentos de un mismo cajero.
- CAI de la caja por vencer o con pocos números restantes (ya existe para facturación).
- Ventas en espera abandonadas (reservas por liberar).

---

## 11. Modelo de datos (nuevas tablas, propuesta)

Todas con `cliente_id` (multitenant) e índices por `(cliente_id, establecimiento_id, fecha)`.

- `pos_turnos` — id, cliente_id, establecimiento_id, punto_emision_id, usuario_id, abierto_en, monto_inicial, cerrado_en, efectivo_contado, efectivo_esperado, diferencia, justificacion, estado (`abierto`/`cerrado`).
- `pos_turno_denominaciones` — turno_id, momento (`apertura`/`cierre`), denominacion, cantidad.
- `pos_movimientos_caja` — id, turno_id, tipo (`entrada`/`retiro`/`reembolso`), monto, motivo, usuario_id, autorizado_por, creado_en.
- `pos_ventas` — id, cliente_id, establecimiento_id, punto_emision_id, turno_id, usuario_id, receptor_id, estado (`abierta`/`en_espera`/`cobrada`/`anulada`/`cancelada`), subtotal, descuento, isv_15, isv_18, total, factura_id, creado_en, cobrado_en.
- `pos_venta_items` — id, venta_id, producto_id, descripcion, cantidad, precio_unitario, descuento, tipo_isv, subtotal, nota, reserva_id.
- `pos_venta_pagos` — id, venta_id, forma (`efectivo`/`tarjeta`/`transferencia`/`credito`), moneda, monto, tasa_cambio, recibido, cambio, referencia, ultimos4, autorizacion, comprobante.
- `pos_apartados` + `pos_apartado_abonos` — cliente, productos reservados, anticipo, abonos, vence_en, estado.
- `notas_credito` + `notas_credito_items` — factura_id original, cai_id, correlativo, motivo, totales, estado, autorizado_por.
- `pos_bitacora` — evento, venta/turno, usuario, autorizado_por, detalle JSON, ip, creado_en.

---

## 12. Requisitos técnicos

- **Transacciones** en todo cobro, anulación, devolución y cierre de caja; bloqueo `FOR UPDATE` del CAI al generar el correlativo (ya existe en `generarCorrelativoFactura`).
- **Idempotencia del cobro:** cada venta lleva un identificador único generado en el navegador; si la red falla y se reintenta, no se cobra ni se factura dos veces.
- **Conexión inestable:** si se cae la red, la pantalla avisa y no permite cobrar hasta reconectar (la venta en curso se guarda localmente para no perderla). Venta offline con sincronización posterior: **fase futura** (complica el correlativo fiscal).
- Hardware: lector de código de barras (modo teclado), impresora térmica 80 mm (impresión desde el navegador o puente local), cajón de dinero conectado a la impresora, báscula opcional.
- CSRF, permisos por rol en cada endpoint, PIN de supervisor con límite de intentos.
- Interfaz responsive con el layout común (`app.css`), táctil, botones grandes, alto contraste.

---

## 13. Fases sugeridas

1. **Base:** turnos de caja (apertura, movimientos, Corte X/Z), venta con efectivo y tarjeta, factura con CAI por caja, descuento de inventario, reimpresión.
2. **Control:** anulación en el día con autorización, notas de crédito y devoluciones, descuentos con PIN, bitácora, reportes de caja y ventas.
3. **Multi-caja en tiempo real:** reservas temporales, refresco de existencias, stock por tienda, traslados.
4. **Extras:** apartados, ventas en espera, pago mixto, USD, crédito a clientes (cuentas por cobrar), envío por WhatsApp/correo, alertas.
5. **Futuro:** modo offline, factura electrónica SAR cuando sea obligatoria.
