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

## 🟡 Interfaz (revisión UX/UI del 2026-10-03, en producción)

Hay **tres estilos de pantalla** conviviendo: (a) cabecera tipo «banner» con degradado, (b) cabecera simple de los módulos nuevos (`app-page-header`), (c) página sin diseño (Productos). Unificar todo al estilo (b) + componentes de `app.css`:

- [ ] **Cabeceras:** cada módulo usa un color distinto (azul: dashboard, facturas, historial, tarjetas, estado de resultados · naranja: gastos · morado: categorías, CAI, clientes · verde: contratos, colaboradores, productos por cliente, proyección · turquesa: mensajes · rojo: usuarios). Además repiten «Sucursal · Rol · nombre de la empresa» y el logo, que ya están en el menú lateral.
- [ ] **Botón principal de color distinto en cada pantalla:** naranja (Nuevo gasto), morado (Nueva categoría, Nuevo rango CAI, Nuevo cliente), verde (Asignar producto, Nuevo colaborador), **rojo** (Nuevo usuario: el rojo debería ser solo para eliminar), azul (módulos nuevos). Unificar a azul.
- [ ] **Tarjetas de indicadores:** montos partidos en dos líneas («L» arriba y la cifra abajo) en dashboard, historial, gastos, colaboradores y proyección. Pasar a `app-kpi`.
- [ ] **Tablas:** montos partidos («L / 9,000.00») en contratos, productos y gastos; columnas cortadas a la derecha en clientes y usuarios; en usuarios el nombre largo de la empresa ocupa 6 líneas.
- [ ] **Productos (catálogo base):** es la pantalla más vieja (tabla DataTables cruda, título gigante, botones celeste/rojo). Rediseñar.
- [ ] **Iconos:** emojis en títulos (📄 👥 💸) mezclados con Bootstrap Icons → solo Bootstrap Icons.
- [ ] **Fechas:** formatos mezclados (2026-10-10 · 15/09/2026 · 03 de octubre de 2026) → dd/mm/aaaa en tablas.
- [ ] **Paginación y búsqueda:** DataTables en productos, paginación propia «10/pág» en el resto → un solo componente.
- [ ] Quitar los `<style>` propios de cada página (400–1 200 líneas) a medida que se migra.
- [ ] Documentos imprimibles: `gasto_ver` usa cabecera roja y la factura naranja → mismo color de marca.
- [ ] Dashboard: agrupar sus ~26 consultas.
- [x] Login, selección de empresa/establecimiento, menú lateral, márgenes globales, módulos nuevos (bancos, cuentas, inventario, POS, empresas) ya con el estilo común.

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

- [ ] **Modo táctil:** botón «Modo táctil» (recordado por navegador): pantalla completa sin menú, tarjetas de producto más grandes con categorías y foto, botones + / − para cantidad, teclado numérico en pantalla para el efectivo, más botones rápidos (L 50/100/200/500), botones de quitar grandes, sin zoom accidental. Probar en tablet y celular.

Especificación completa: [ESPECIFICACION_POS.md](ESPECIFICACION_POS.md). Migración `sql/migraciones/2026-10-03_pos.sql`.

- [x] **Fase 1:** turnos de caja (apertura con fondo, una caja = un punto de emisión, un turno por caja y por cajero), venta con buscador y lector de código de barras, cobro en efectivo con cambio / tarjeta (autorización) / transferencia / mixto, factura con el CAI de la caja a CONSUMIDOR FINAL o a un cliente, descuento de inventario, envío repetido sin doble cobro (idempotencia), entradas/retiros de efectivo (el cajero necesita autorización de un admin), Corte X, cierre con arqueo ciego y justificación de diferencias, Corte Z imprimible, historial de turnos. Responsive.
- [ ] **Fase 2 (control):** notas de crédito y devoluciones parciales, descuentos con PIN de supervisor, bitácora POS, reportes de ventas por cajero/caja/hora.
- [ ] **Fase 3:** reservas temporales del carrito entre cajas (usa `inv_existencias.reservado`) y refresco de existencias cada pocos segundos; buscador de catálogo por API para catálogos grandes.
- [ ] **Fase 4:** apartados con abonos, ventas en espera, USD, venta a crédito (cuentas por cobrar), envío por WhatsApp/correo, impresión térmica 80 mm.
- [ ] Confirmar con el contador los puntos fiscales marcados en la especificación (§8).

---

## ✅ Hecho (2026-10-03)

- Migraciones aplicadas en producción (con respaldo verificado) y código desplegado.
- Descarga de varias facturas en ZIP: PDF corregido (error de dompdf) y ajustado para verse igual que «Imprimir / PDF», con texto real.

- Borrado de facturas: solo la última del CAI (evita correlativos duplicados).
- Aislamiento por empresa en productos y en las APIs de contratos y puntos de emisión.
- Layout nuevo: sidebar izquierdo responsive con hamburguesa (`menu.php` + `sidebar.php`), `app.css`, footer por empresa, márgenes globales.
- Gastos: comprobantes opcionales validados y protegidos (`gasto_archivo.php` + `uploads/.htaccess`), botón "Pagar" con fecha/método/comprobante, corrección de "actualizar ambas quincenas", validación de categoría/tarjeta/método.
- Gastos recurrentes: al pagar uno mensual/anual se programa el del período siguiente.
- Velocidad: conexión persistente solo con BD remota, menos consultas por página, gzip y caché, librerías sin duplicar, proyección sin N+1 ni escrituras por visita.
