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

Unificación hecha con una «capa» en `clientes/css/app.css` (sin reescribir cada página) + ajustes puntuales:

- [x] **Cabeceras:** todas con el mismo estilo (tarjeta clara con acento de marca); sin logo ni «Sucursal · Rol · empresa» repetidos; subtítulo descriptivo.
- [x] **Botón principal:** un solo color (acento de la empresa) en todos los módulos; rojo solo para eliminar.
- [x] **Cifras:** montos sin partir en tarjetas y tablas; en celular las cifras grandes se achican.
- [x] **Productos (catálogo base):** rediseñado con los componentes comunes y búsqueda rápida (sin DataTables).
- [x] **Iconos:** emojis de los títulos → Bootstrap Icons.
- [x] Espacio sobrante arriba de las páginas viejas eliminado.
- [x] **Mismo ancho en todas las páginas** (antes 960 / 1100 / 1200 / 1320 px según la página) y mismo encabezado en módulos viejos y nuevos.
- [x] **Menú lateral:** al cambiar de página conserva su posición y centra el ítem activo (antes volvía arriba y el activo quedaba fuera de la vista); el activo lleva una barra de color.
- [x] **Estado de resultados:** los meses futuros ya no se grafican como cero y el mes actual se marca «(en curso)».
- [ ] **Estado de resultados:** la nómina solo está registrada como gasto de enero a marzo 2026; de abril en adelante los egresos no incluyen sueldos (margen irreal de ~98 %). Registrar la nómina mensual o calcularla desde colaboradores.
- [ ] **Proyección de flujo de caja:** revisar al final (pedido del usuario).
- [ ] **Cuentas por cobrar y Cuentas por pagar:** igualar paginación, tipografía, colores de texto, espacios y enlaces al resto de páginas.
- [ ] **Fechas:** formatos mezclados (2026-10-10 · 15/09/2026) → dd/mm/aaaa en tablas (CAI, contratos).
- [ ] **Tablas anchas:** clientes/usuarios/CAI tienen muchas columnas; en pantallas medianas hay que desplazar a la derecha. Evaluar ocultar columnas secundarias.
- [ ] **Paginación:** cada página vieja tiene su propia paginación «10/pág» → un solo componente.
- [ ] Quitar los `<style>` propios de cada página (400–1 200 líneas) a medida que se migra.
- [ ] Documentos imprimibles: `gasto_ver` usa cabecera roja y la factura naranja → mismo color de marca.
- [ ] Dashboard: agrupar sus ~26 consultas.
- [x] Login, selección de empresa/establecimiento, menú lateral, márgenes globales, módulos nuevos (bancos, cuentas, inventario, POS, empresas) ya con el estilo común.

## 📤 Exportaciones

- [x] **Historial → Descargar XLSX** (facturas seleccionadas): una fila por factura con cliente, RTN, sucursal, contrato, detalle enumerado en una celda, importes exento/exonerado/gravados, subtotal, ISV 15/18, total, total en letras, CAI y rango; fila final de totales (sin anuladas). Generador propio `includes/xlsx.php` (no requiere librerías en el servidor).

## 📄 Contratos

- [x] Número de contrato visible, botones Ver / Editar siempre, contratos rotativos con sus empresas y última factura.
- [x] Cobertura por contrato: «Facturado hasta …», meses de atraso y facturas sin pagar (con monto).
- [x] Abonos desde «Facturas del contrato» (registrar, ver y anular), con cobrado y saldo por cobrar.
- [ ] Campo «Se factura con atraso de N meses» (Texaco Valeriano factura con 2 meses de atraso) para no marcar alertas falsas.
- [ ] Amarrar cada línea de una factura a su propio contrato (facturas que cobran dos contratos a la vez).
- [ ] Anticipos sin factura (p. ej. Etapa 1 de Aldea Global): registrarlos y aplicarlos a la factura cuando se emita.

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
