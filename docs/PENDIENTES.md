# Pendientes — Sistema de Facturación

Actualizado: 2026-10-03 (tarde). Orden = prioridad sugerida.

## 🔴 Urgente (seguridad)

- [ ] Cambiar la contraseña de la BD de producción y sacar las credenciales de git (`includes/config.php`, `test.php`) → `.env` / `config.local.php` en `.gitignore`.
- [x] Borrar `test.php` y `clientes/naranjaymedia/debug_contrato13.php`.
- [ ] Sacar de git el volcado `clientes/facturacion_saas (3).sql` y los comprobantes de `includes/uploads/` (datos personales).
- [x] BD local de pruebas `facturacion_saas_dev` + servidor de pruebas en :8383 + 229 pruebas automáticas (ver [DESARROLLO.md](DESARROLLO.md)). El XAMPP :8282 sigue contra producción.
- [ ] Restringir en cPanel el acceso remoto a MySQL (hoy el usuario de la BD acepta conexiones desde cualquier IP `%`).
- [ ] Borrar del servidor `clientes/facturacion_saas (3).sql` (ya bloqueado por `clientes/.htaccess`, pero no debe estar ahí).

## 🟠 Alto

- [x] Protección CSRF en todos los formularios y endpoints.
- [x] Factura: cantidades/precios negativos, total > 0, `fecha_emision`/`estado` los pone el servidor, superadmin con el cliente seleccionado. (`precio_fijo` NO se fuerza: en la práctica es precio sugerido.)
- [x] Editar factura: un admin de otra empresa ya no puede abrir/editar facturas ajenas; monto en letras incluye ISV 18 %.
- [x] CAI: formato, sin traslapes, bloqueo de campos si ya tiene facturas.
- [x] Sesión: cookies seguras, `session_regenerate_id()`, límite de intentos (login y clave autorizadora; requiere `sql/migraciones/2026-10-03_login_intentos.sql`), usuarios inactivos bloqueados, logout completo.
- [x] Superadmin: facturación, CAI y selección de establecimientos con la empresa seleccionada.

## 🟡 Interfaz

- [x] **Login mejorado** (ojo, Bloq Mayús, responsive) y pantallas de selección de empresa/establecimiento.
- [ ] Migrar cada página a `app.css` (quitar `<style>` propios de 400–1 200 líneas); espaciados y mejoras visuales.
- [x] `index` y `seleccionar_*` con el estilo común. `gasto_ver`, `ver_factura`, `colaborador_reporte` quedan como documentos imprimibles independientes (a propósito).
- [x] Corregir textos con codificación rota (mojibake) en `includes/dashboard.php`.
- [ ] Dashboard: agrupar sus ~26 consultas.

## 🧩 Plataforma

- [x] **Multitenant real**: `/clientes/<subdominio>/` usa la misma app (`clientes/.htaccess`), marca desde `clientes_saas`, empresas inactivas bloqueadas.
- [ ] Migrar los archivos viejos a `cliente_actual()` (hoy repiten la expresión).
- [x] **Panel superadmin** (`empresas.php`): crear/editar/activar empresas, primer establecimiento + admin, sucursales y puntos de emisión. Usuarios: `usuarios.php` (ya era global).

## 💰 Finanzas

- [x] **Bancos** (`bancos`, `banco_cuenta`, `cheques`): cuentas de ahorro/cheques HNL/USD, movimientos, transferencias con tasa, conciliación, chequera (emitir/cobrar/anular). Migración `sql/migraciones/2026-10-03_bancos.sql`.
- [x] **Cuentas por cobrar** (abonos parciales, antigüedad 0-30/31-60/61-90/90+, por cliente, cobro a cuenta bancaria; migración `2026-10-03_cuentas_cobrar.sql`) y **cuentas por pagar** (gastos pendientes por proveedor y vencimiento, pago desde cuenta bancaria).
- [x] Gastos recurrentes (mensual/anual) programan el siguiente período al pagarse; aparecen en cuentas por pagar.
- [x] Función única `cliente_actual()` en `session.php` (usada por los módulos nuevos).

## 📦 Inventario

- [x] Productos tipo **servicio** o **bien** (SKU, código de barras, unidad, costo promedio, stock mínimo). Migración `sql/migraciones/2026-10-03_inventario.sql`.
- [x] Existencias por tienda, kardex, entradas con costo, ajustes por conteo, traslados (en tránsito → recibido / anulado).
- [x] Facturar descuenta automáticamente; anular / eliminar / editar devuelve o recalcula. Sin existencia, la factura no se emite (y no consume correlativo). Ventas simultáneas de la última unidad: solo pasa una.
- [x] Alertas: aviso en el menú con productos por reponer; filtro «bajo mínimo / agotados».
- [x] Reportes (`inventario_reportes`): valor por tienda, por reponer, más vendidos, sin ventas.
- [ ] Apartados (reservas con anticipo): la columna `reservado` ya existe; la pantalla va con el POS.

## 🛒 Punto de Venta (POS)

Especificación completa: [ESPECIFICACION_POS.md](ESPECIFICACION_POS.md). Migración `sql/migraciones/2026-10-03_pos.sql`.

- [x] **Fase 1:** turnos de caja (apertura con fondo, una caja = un punto de emisión, un turno por caja y por cajero), venta con buscador y lector de código de barras, cobro en efectivo con cambio / tarjeta (autorización) / transferencia / mixto, factura con el CAI de la caja a CONSUMIDOR FINAL o a un cliente, descuento de inventario, envío repetido sin doble cobro (idempotencia), entradas/retiros de efectivo (el cajero necesita autorización de un admin), Corte X, cierre con arqueo ciego y justificación de diferencias, Corte Z imprimible, historial de turnos. Responsive.
- [ ] **Fase 2 (control):** notas de crédito y devoluciones parciales, descuentos con PIN de supervisor, bitácora POS, reportes de ventas por cajero/caja/hora.
- [ ] **Fase 3:** reservas temporales del carrito entre cajas (usa `inv_existencias.reservado`) y refresco de existencias cada pocos segundos; buscador de catálogo por API para catálogos grandes.
- [ ] **Fase 4:** apartados con abonos, ventas en espera, USD, venta a crédito (cuentas por cobrar), envío por WhatsApp/correo, impresión térmica 80 mm.
- [ ] Confirmar con el contador los puntos fiscales marcados en la especificación (§8).

---

## ✅ Hecho (sin commit, 2026-10-03)

- Borrado de facturas: solo la última del CAI (evita correlativos duplicados).
- Aislamiento por empresa en productos y en las APIs de contratos y puntos de emisión.
- Layout nuevo: sidebar izquierdo responsive con hamburguesa (`menu.php` + `sidebar.php`), `app.css`, footer por empresa, márgenes globales.
- Gastos: comprobantes opcionales validados y protegidos (`gasto_archivo.php` + `uploads/.htaccess`), botón "Pagar" con fecha/método/comprobante, corrección de "actualizar ambas quincenas", validación de categoría/tarjeta/método.
- Gastos recurrentes: al pagar uno mensual/anual se programa el del período siguiente.
- Velocidad: conexión persistente solo con BD remota, menos consultas por página, gzip y caché, librerías sin duplicar, proyección sin N+1 ni escrituras por visita.
