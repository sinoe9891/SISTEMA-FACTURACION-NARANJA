# Sistema de Facturación SaaS (PHP + MySQL) — Naranja y Media

Este repositorio (ZIP) contiene un sistema de facturación multi‑cliente (multi‑tenant) construido en **PHP** con **MySQL (PDO)**, con autenticación por sesión y URLs “bonitas” vía **mod_rewrite**.

> Nota: `includes.zip` contiene prácticamente la carpeta `/includes/` (misma base de helpers, sesión, templates, etc.). El proyecto completo está en `Archivo.zip`.

---

## 1) Estructura del proyecto (carpetas y responsabilidades)

```
/
├─ includes/
│  ├─ config.php                  # Config DB + BASE_URL
│  ├─ db.php                      # Conexión PDO
│  ├─ session.php                 # “middleware” de sesión + detección de cliente + roles
│  ├─ functions.php               # Utilidades: CAI/correlativos, números a letras, fechas, etc.
│  ├─ dashboard.php               # Consultas/constantes para métricas y alertas del dashboard
│  ├─ api/
│  │  └─ productos_por_receptor.php  # Endpoint JSON (GET) para productos filtrados por receptor
│  └─ templates/
│     ├─ header.php               # Navbar/layout superior
│     └─ footer.php               # Footer/layout inferior
│
└─ clientes/
   ├─ css/
   │  └─ global.css               # Estilos globales (front)
   └─ naranjaymedia/
      ├─ .htaccess                # Rewrite: URLs sin .php (excepto /includes)
      ├─ index.php                # Login (pública)
      ├─ seleccionar_cliente.php  # Solo superadmin: selecciona tenant (cliente)
      ├─ seleccionar_establecimiento.php # Selección de establecimiento
      ├─ dashboard.php            # Panel principal
      ├─ clientes.php             # CRUD de “clientes de factura” (receptores)
      ├─ crear_cliente.php
      ├─ editar_cliente.php
      ├─ guardar_cliente.php
      ├─ actualizar_cliente.php
      ├─ eliminar_cliente.php
      ├─ productos.php            # CRUD productos base (tabla productos)
      ├─ productos_clientes.php   # CRUD productos por receptor (tabla productos_clientes)
      ├─ configuracion_cai.php    # CRUD CAI / rangos / correlativos
      ├─ generar_factura.php      # UI creación factura
      ├─ guardar_factura.php      # Endpoint JSON: crea factura + items
      ├─ lista_facturas.php       # Listado + acciones
      ├─ ver_factura.php          # Vista/impresión de factura (GET id)
      ├─ editar_factura.php       # Editar factura existente (GET id)
      ├─ guardar_factura_editada.php # Persistir edición factura
      ├─ procesar_accion_factura.php # Endpoint JSON: anular / eliminar / restaurar
      ├─ logout.php               # Cerrar sesión
      └─ includes/                # Acciones internas (POST) para productos (NO rewrite)
         ├─ prod_guardar.php
         ├─ productos_editar.php
         ├─ productos_borrar.php
         ├─ productos_clientes_agregar.php
         ├─ productos_clientes_editar.php
         └─ productos_clientes_eliminar.php
```

---

## 2) Cómo funciona el sistema (visión general)

### Multi‑tenant (cliente por subdominio o por ruta)
- El sistema intenta **detectar el cliente** por:
  - **Subdominio** (ej: `cliente.midominio.com`), o
  - **Ruta local** tipo `/clientes/<cliente>/...`
- La detección y validación de acceso está centralizada en:  
  **`/includes/session.php`** (define constantes como `USUARIO_ID`, `USUARIO_NOMBRE`, `USUARIO_ROL`, `CLIENTE_ID`, etc.).

### Roles
- `superadmin`: puede entrar a **todos los clientes**, y debe pasar por:
  1) `/seleccionar_cliente` → setea `$_SESSION['cliente_seleccionado']`
  2) `/seleccionar_establecimiento` → setea `$_SESSION['establecimiento_activo']`
- `admin`: opera dentro de su cliente asignado.

### Flujo de login (alto nivel)
1) `index.php` valida correo/clave contra tabla `usuarios` (usa `password_verify`)
2) Crea `$_SESSION['usuario_id']`
3) Según rol/establecimientos:
   - superadmin → `/seleccionar_cliente`
   - si tiene 1 establecimiento → setea en sesión y va a `/dashboard`
   - si tiene varios → `/seleccionar_establecimiento`

---

## 3) Base de datos (tablas detectadas en el código)

El código hace consultas/updates a estas tablas (nombres inferidos por SQL):
- `usuarios`
- `clientes_saas`
- `establecimientos`
- `usuario_establecimientos`
- `clientes_factura` (receptores / clientes finales a facturar)
- `productos` (catálogo base)
- `productos_clientes` (productos/servicios configurables por receptor)
- `precios_especiales`
- `cai_rangos`
- `facturas`
- `factura_items_receptor`
- `bitacora_facturas`
- `configuracion_sistema`

---

## 4) Requisitos técnicos

- PHP con extensiones:
  - `pdo_mysql`
  - `intl` (se usa `NumberFormatter` en `functions.php`)
- Apache/Nginx con soporte para:
  - **mod_rewrite** (Apache) según `.htaccess` en `/clientes/naranjaymedia/`
- MySQL/MariaDB

---

## 5) Configuración rápida

1) Edita **`/includes/config.php`**:
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
   - `BASE_URL` (si aplica)
2) Asegura que el VirtualHost permita `.htaccess` (AllowOverride).
3) Abre la app en:
   - Local: `http://localhost/.../clientes/naranjaymedia/`
   - Producción: `https://<subdominio>.tu-dominio.com/` (si se habilita por subdominios)

---

## 6) Endpoints y rutas (resumen)
Ver el archivo **ROUTES.md** para el mapa completo de rutas y endpoints.

---

## 7) Dónde tocar cuando quieras cambiar algo (guía rápida)

- Conexión DB / credenciales: `includes/config.php`, `includes/db.php`
- Reglas de acceso, rol, detección de cliente: `includes/session.php`
- Navbar / menú / links: `includes/templates/header.php`
- Lógica CAI y correlativos: `includes/functions.php` + `clientes/naranjaymedia/configuracion_cai.php`
- Creación de facturas (backend): `clientes/naranjaymedia/guardar_factura.php`
- Acciones sobre facturas (anular/eliminar/restaurar): `clientes/naranjaymedia/procesar_accion_factura.php`
- Productos:
  - Base: `clientes/naranjaymedia/productos.php` + `clientes/naranjaymedia/includes/prod_guardar.php`
  - Por receptor: `clientes/naranjaymedia/productos_clientes.php` + scripts en `clientes/naranjaymedia/includes/`
  - API para UI: `includes/api/productos_por_receptor.php`

---

## 8) Notas importantes de URLs “bonitas”

- El `.htaccess` convierte rutas como `/dashboard` → `dashboard.php`
- **No aplica rewrite** para `/includes/` dentro del cliente (scripts de acciones).

