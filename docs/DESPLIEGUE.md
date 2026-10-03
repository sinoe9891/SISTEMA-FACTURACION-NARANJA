# Despliegue a producción — 2026-10-03

**Base de datos:** las 5 migraciones ya están aplicadas en producción (2026-10-03).
Respaldo previo: `~/Respaldos_facturacion/facturacion_saas_ANTES_migraciones_20261003_163223.sql` (verificado: se restaura y coincide tabla por tabla).

Las rutas son relativas a la carpeta del sistema en el servidor (la que contiene `includes/` y `clientes/`).

---

## Opción A — Carpetas completas (más simple)

Sube estas carpetas **sobrescribiendo**, con estas excepciones:

| Subir carpeta | ⚠️ NO sobrescribir / NO subir |
|---|---|
| `includes/` | `includes/config.php` (deja el del servidor: tiene sus credenciales) |
| `clientes/naranjaymedia/` | `clientes/naranjaymedia/includes/uploads/` (comprobantes reales del servidor) — **sí** sube el archivo `uploads/.htaccess` |
| `clientes/css/` | — |
| `clientes/js/` (carpeta nueva) | — |
| `clientes/.htaccess` (archivo nuevo) | — |

**No subir nunca:** `dev/`, `docs/`, `sql/`, `.git/`, `.gitignore`, `GRUPO-VELMEZ`, `clientes/GRUPO-VELMEZ`, `clientes/facturacion_saas (3).sql`, `sistema_facturacion_saas.sql`, `vendor/` (no cambió).

**Borrar en el servidor:**
- `test.php` (raíz) — tiene la contraseña de la base de datos
- `clientes/naranjaymedia/debug_contrato13.php`
- `clientes/facturacion_saas (3).sql` — respaldo con datos personales

FileZilla: al subir carpetas, elige «Sobrescribir si el origen es más reciente» o «Sobrescribir». FileZilla **no borra** archivos del servidor que no existan en tu PC, así que los comprobantes del servidor se conservan.

---

## Opción B — Archivo por archivo

### `includes/`
Modificados: `dashboard.php`, `db.php`, `functions.php`, `session.php`, `api/contratos_por_receptor.php`, `api/puntos_por_establecimiento.php`, `templates/header.php`, `templates/footer.php`
Nuevos: `bancos.php`, `csrf.php`, `cuentas.php`, `facturacion.php`, `intentos.php`, `inventario.php`, `pos.php`, `sesion_inicio.php`, `templates/menu.php`, `templates/sidebar.php`
⚠️ `config.php`: **no** subir (ver nota abajo).

### `clientes/`
Nuevos: `.htaccess`, `css/.htaccess`, `css/app.css`, `js/app-acciones.js`, `js/bancos.js`

### `clientes/naranjaymedia/`
Modificados: `.htaccess`, `categorias_gastos.php`, `clientes.php`, `colaborador_reporte.php`, `colaborador_ver.php`, `colaboradores.php`, `configuracion_cai.php`, `configuracion_mensajes.php`, `contratos.php`, `crear_cai.php`, `crear_cliente.php`, `crear_contrato.php`, `dashboard.php`, `editar_cai.php`, `editar_cliente.php`, `editar_contrato.php`, `editar_factura.php`, `facturas_contrato.php`, `financiero.php`, `gasto_ver.php`, `gastos.php`, `generar_factura.php`, `generar_recibo.php`, `guardar_factura.php`, `guardar_factura_editada.php`, `index.php`, `lista_facturas.php`, `logout.php`, `procesar_accion_factura.php`, `productos.php`, `productos_clientes.php`, `proyeccion.php`, `seleccionar_cliente.php`, `seleccionar_establecimiento.php`, `tarjetas.php`, `usuarios.php`, `ver_factura.php`
Nuevos: `banco_cuenta.php`, `bancos.php`, `cheques.php`, `cuentas_cobrar.php`, `cuentas_pagar.php`, `empresas.php`, `gasto_archivo.php`, `guardar_cai.php`, `guardar_edicion_cai.php`, `inventario.php`, `inventario_kardex.php`, `inventario_reportes.php`, `inventario_traslados.php`, `pos.php`, `pos_turnos.php`

### `clientes/naranjaymedia/includes/`
Modificados: `gasto_actualizar.php`, `gasto_eliminar.php`, `gasto_guardar.php`, `gasto_marcar_pagado.php`, `prod_guardar.php`, `productos_borrar.php`, `productos_clientes_agregar.php`, `productos_clientes_editar.php`, `productos_clientes_eliminar.php`, `productos_editar.php`
Nuevos: `_gasto_adjuntos.php`, `_gasto_recurrencia.php`, `banco_accion.php`, `cxc_accion.php`, `empresa_estado.php`, `empresa_guardar.php`, `empresa_sucursales.php`, `inventario_accion.php`, `pos_accion.php`, `uploads/.htaccess`

---

## Nota sobre `includes/config.php`

El `config.php` local tiene un bloque nuevo al inicio (modo de pruebas `APP_DB=dev`) y las credenciales de producción con la IP remota. En el servidor **deja el `config.php` que ya tiene** (normalmente apunta a `localhost`). El código nuevo funciona con el `config.php` actual del servidor: `db.php` usa `DB_PORT` solo si existe.

## Después de subir

1. Abre `https://<tu-dominio>/clientes/naranjaymedia/` y entra.
2. Los usuarios con la página abierta desde antes deben **recargar** (los formularios ahora llevan protección CSRF).
3. Prueba: dashboard, nueva factura (en modo prueba no; solo navega), Gastos, Bancos, Inventario, Punto de venta (pantalla de apertura).
4. Comprueba que `https://<tu-dominio>/clientes/facturacion_saas%20(3).sql` y `…/clientes/naranjaymedia/includes/uploads/…` respondan **403**.

## Si algo falla

- Código: vuelve a subir la versión anterior de los archivos (desde git: `git stash` o el último commit).
- Base de datos: las migraciones solo **agregaron** tablas y columnas; el sistema anterior funciona igual con ellas. Restaurar el respaldo solo sería necesario si se dañaran datos.
