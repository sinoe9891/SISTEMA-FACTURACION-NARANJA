# Mapa de rutas y endpoints (clientes/naranjaymedia)

> Base típica: `/clientes/naranjaymedia/` (local por carpeta) o por subdominio (prod).

## Páginas (UI)
- `/` → `clientes/naranjaymedia/index.php` (Login)
- `/dashboard` → `clientes/naranjaymedia/dashboard.php`
- `/seleccionar_cliente` → `clientes/naranjaymedia/seleccionar_cliente.php` (solo superadmin)
- `/seleccionar_establecimiento` → `clientes/naranjaymedia/seleccionar_establecimiento.php`
- `/clientes` → `clientes/naranjaymedia/clientes.php`
- `/crear_cliente` → `clientes/naranjaymedia/crear_cliente.php`
- `/editar_cliente?id=ID` → `clientes/naranjaymedia/editar_cliente.php`
- `/guardar_cliente` → `clientes/naranjaymedia/guardar_cliente.php` (POST)
- `/actualizar_cliente` → `clientes/naranjaymedia/actualizar_cliente.php` (POST)
- `/eliminar_cliente` → `clientes/naranjaymedia/eliminar_cliente.php` (POST)
- `/productos` → `clientes/naranjaymedia/productos.php`
- `/productos_clientes` → `clientes/naranjaymedia/productos_clientes.php`
- `/configuracion_cai` → `clientes/naranjaymedia/configuracion_cai.php`
- `/generar_factura` → `clientes/naranjaymedia/generar_factura.php`
- `/lista_facturas` → `clientes/naranjaymedia/lista_facturas.php`
- `/ver_factura?id=ID` → `clientes/naranjaymedia/ver_factura.php`
- `/editar_factura?id=ID` → `clientes/naranjaymedia/editar_factura.php`
- `/guardar_factura_editada` → `clientes/naranjaymedia/guardar_factura_editada.php` (POST)
- `/logout` → `clientes/naranjaymedia/logout.php`

## Endpoints JSON (API interna)
- `/guardar_factura` → `clientes/naranjaymedia/guardar_factura.php`
  - Responde JSON `{ success, factura_id, redirect_url }`
- `/procesar_accion_factura` → `clientes/naranjaymedia/procesar_accion_factura.php`
  - Responde JSON, acciones soportadas: `anular`, `eliminar`, `restaurar`

## API “shared” (carpeta raíz)
- `/includes/api/productos_por_receptor.php?receptor_id=ID`
  - Devuelve JSON de productos filtrados por receptor (según `cliente_id` en sesión)

## Scripts internos (POST) — NO pasan por rewrite
- `/clientes/naranjaymedia/includes/prod_guardar.php`
- `/clientes/naranjaymedia/includes/productos_editar.php`
- `/clientes/naranjaymedia/includes/productos_borrar.php`
- `/clientes/naranjaymedia/includes/productos_clientes_agregar.php`
- `/clientes/naranjaymedia/includes/productos_clientes_editar.php`
- `/clientes/naranjaymedia/includes/productos_clientes_eliminar.php`
