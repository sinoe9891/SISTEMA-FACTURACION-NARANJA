# Entorno de desarrollo y pruebas

El XAMPP normal (`http://localhost:8282/...`) sigue usando la BD de **producción**.
Para probar cambios que escriben datos se usa un entorno separado que **nunca toca producción**.

## Piezas

| Pieza | Valor |
|---|---|
| BD de pruebas | `facturacion_saas_dev` en el MySQL de XAMPP (`127.0.0.1:3309`, usuario `root`, sin clave) — copia de producción |
| Servidor de pruebas | `http://localhost:8383` (servidor integrado de PHP + `dev/router.php`) |
| Interruptor | variable de entorno `APP_DB=dev` → `includes/config.php` usa la BD de pruebas |
| Usuarios de prueba (solo en la BD dev) | `qa.admin@local.test` (admin Naranja), `qa.facturador@local.test`, `qa.super@local.test` (superadmin), `qa.ccic@local.test` (admin de la empresa CCIC). Contraseña común en `dev/.qa_password` (no se sube a git) |

## Comandos

Arrancar el servidor de pruebas:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs && APP_DB=dev php -S localhost:8383 -t . proyectos/NARANJA/sistemafacturacion/dev/router.php
```

Abrir: `http://localhost:8383/proyectos/NARANJA/sistemafacturacion/clientes/naranjaymedia/`

Correr todas las pruebas (con el servidor arrancado):

```bash
APP_DB=dev php dev/tests/run.php
```

Correr solo una parte: `APP_DB=dev php dev/tests/run.php gastos`

Refrescar la BD de pruebas con una copia nueva de producción (solo lectura en producción):

```bash
mysqldump -h <host-prod> -u <usuario> -p --single-transaction --skip-lock-tables --no-tablespaces facturacion_saas > /tmp/prod.sql
mysql -h127.0.0.1 -P3309 -uroot -e "DROP DATABASE IF EXISTS facturacion_saas_dev; CREATE DATABASE facturacion_saas_dev CHARACTER SET utf8mb4"
mysql -h127.0.0.1 -P3309 -uroot facturacion_saas_dev < /tmp/prod.sql
```

(Después hay que volver a crear los usuarios de prueba.)

## Reglas

- Las pruebas se niegan a correr si la BD no termina en `_dev`.
- Lo que crean (facturas, gastos, productos, archivos) lo borran al terminar.
- Las pruebas de aislamiento crean un enlace temporal `clientes/ccic` → `naranjaymedia` y lo eliminan al final.

## Migraciones (ejecutar en orden en producción antes de subir el código nuevo)

1. `sql/migraciones/2026-10-03_login_intentos.sql`
2. `sql/migraciones/2026-10-03_bancos.sql`
3. `sql/migraciones/2026-10-03_cuentas_cobrar.sql`
4. `sql/migraciones/2026-10-03_inventario.sql`
5. `sql/migraciones/2026-10-03_pos.sql`

Todas son idempotentes (se pueden ejecutar dos veces sin error). Sin ellas, los módulos nuevos
muestran un aviso «no está instalado» y el resto del sistema sigue funcionando.
