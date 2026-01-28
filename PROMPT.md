# Prompt maestro para entender/modificar este sistema

Actúa como un **ingeniero senior PHP (Apache + MySQL/PDO)**. Estoy trabajando con un proyecto de facturación multi‑tenant.

## Contexto del proyecto
- La app vive en: `clientes/naranjaymedia/`
- Los helpers comunes están en: `includes/`
- Hay URLs bonitas por `.htaccess` (rutas sin `.php`) y se excluyen scripts dentro de `/includes/`.
- Autenticación/sesión y detección de cliente están en: `includes/session.php`
- Facturas:
  - Crear: `clientes/naranjaymedia/generar_factura.php` (UI) → `clientes/naranjaymedia/guardar_factura.php` (JSON)
  - Acciones: `clientes/naranjaymedia/procesar_accion_factura.php` (JSON)
  - Ver: `clientes/naranjaymedia/ver_factura.php`
- Productos:
  - Base: `clientes/naranjaymedia/productos.php`
  - Por receptor: `clientes/naranjaymedia/productos_clientes.php`
  - API: `includes/api/productos_por_receptor.php`

## Lo que necesito en cada respuesta
1) **Dime exactamente qué archivos modificar** (rutas completas).
2) Explica **cómo se conecta la ruta con el módulo/archivo** (por `.htaccess` y/o formularios/action).
3) Si propones nuevos endpoints, incluye:
   - Ruta sugerida
   - Archivo sugerido
   - Método (GET/POST)
   - Parámetros de entrada (GET/POST/JSON)
   - Respuesta esperada (HTML/JSON)
4) Mantén los cambios compatibles con:
   - `includes/session.php` (roles/cliente/establecimiento)
   - PDO con prepared statements
   - Estilo actual (Bootstrap + SweetAlert)

## Pregunta/objetivo actual
[PEGA AQUÍ TU CAMBIO O DUDA]
