# PROMPT MAESTRO — Sistema de Gestión Empresarial (SaaS Multi-tenant)

## Honduras · PHP 8+ · MySQL · Bootstrap 5 · Chart.js · SweetAlert2

---

## 1. DESCRIPCIÓN GENERAL DEL PROYECTO

Sistema web multi-tenant de gestión empresarial para empresas hondureñas. Cada empresa ("cliente") tiene su propio espacio aislado con facturación electrónica (CAI/SAR Honduras), nómina, contratos de servicio, gastos y estado de resultados.

**Stack técnico:**

- Backend: PHP 8+ puro (sin frameworks), PDO/MySQL
- Frontend: Bootstrap 5 + Bootstrap Icons + Font Awesome 6 + SweetAlert2 + Chart.js 4
- Fuente global personalizada: Plus Jakarta Sans (páginas modernas)
- Patrón visual: Cards con `border-radius:14px`, gradientes en headers, KPIs tipo "stat card"
- AJAX en todas las acciones (fetch API o jQuery.ajax), respuestas JSON
- No hay SPA — PHP genera HTML, JS maneja interacciones
- Uploads: `/includes/uploads/` (comprobantes, gastos)

**Ruta base del proyecto:** `clientes/[empresa]/` (ej: `clientes/naranjaymedia/`)
**Includes compartidos:** `../../includes/` (db.php, session.php, functions.php, templates/)

---

## 2. ESTRUCTURA DE ARCHIVOS

```
clientes/naranjaymedia/
├── dashboard.php          — Dashboard principal con KPIs
├── contratos.php          — Lista de contratos (próximos cobros, tabla)
├── crear_contrato.php     — Form nuevo contrato (multi-servicio)
├── editar_contrato.php    — Editar contrato existente
├── facturas_contrato.php  — Facturas generadas de un contrato
├── generar_factura.php    — Crear factura (con CAI/SAR Honduras)
├── lista_facturas.php     — Historial de facturas
├── ver_factura.php        — Vista de factura individual
├── colaboradores.php      — Gestión de nómina
├── colaborador_ver.php    — Perfil de colaborador (tabs: pagos, préstamos, bonos, viáticos)
├── gastos.php             — Registro de egresos
├── financiero.php         — Estado de Resultados (ingresos vs egresos)
├── productos_clientes.php — Catálogo de servicios/productos del cliente
├── clientes.php           — Lista de clientes de facturación
├── crear_cliente.php      — Nuevo cliente
├── configuracion_cai.php  — Configuración CAI (Honduras SAR)
├── includes/
│   ├── contrato_guardar.php
│   ├── contrato_actualizar.php
│   ├── contrato_cancelar.php
│   ├── contrato_verificar.php
│   ├── colaborador_guardar.php
│   ├── colaborador_actualizar.php
│   ├── colaborador_pago_guardar.php    — Registrar pago nómina + bonos/viáticos
│   ├── colaborador_cuotas_info.php     — AJAX: cuotas pendientes para modal pago
│   ├── gasto_guardar.php / actualizar / eliminar
│   ├── prestamo_guardar.php            — Préstamos/bonos/viáticos de colaboradores
│   ├── prestamo_editar.php / cancelar / eliminar
│   └── api/
│       ├── productos_por_receptor.php  — GET: servicios filtrados por cliente
│       └── contratos_por_receptor.php  — GET: contratos activos de un cliente

includes/ (compartido entre todos los clientes)
├── db.php                 — Conexión PDO
├── session.php            — Autenticación, constantes CLIENTE_ID, USUARIO_ROL
├── functions.php          — Helpers: CAI, número a letras (es-HN)
├── config.php             — DB_HOST, DB_NAME, DB_USER, DB_PASS
└── templates/
    ├── header.php         — Navbar, sidebar, Bootstrap CDN
    └── footer.php         — Cierre HTML
```

---

## 3. AUTENTICACIÓN Y MULTI-TENANT

```php
// session.php define estas constantes tras verificar sesión:
CLIENTE_ID          // int  — ID de la empresa activa
USUARIO_ID          // int  — ID del usuario logueado
USUARIO_ROL         // string — 'superadmin' | 'admin' | 'usuario'

// Patrón estándar en cada archivo PHP:
$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);
```

Cada query SIEMPRE filtra por `cliente_id` — nunca hay datos cruzados entre empresas.

---

## 4. BASE DE DATOS — TABLAS PRINCIPALES

### `clientes_saas` — Empresas registradas en el SaaS

```sql
id, nombre, email, activo, created_at
```

### `usuarios` — Usuarios del sistema

```sql
id, cliente_id(FK), nombre, email, password, rol ENUM('superadmin','admin','usuario'), activo
```

### `establecimientos` — Establecimientos fiscales (SAR Honduras)

```sql
id, cliente_id(FK), nombre, direccion, codigo_establecimiento
```

### `puntos_emision` — Puntos de emisión de facturas

```sql
id, cliente_id(FK), establecimiento_id(FK), nombre, codigo_punto
```

### `cai_rangos` — Control de correlativo CAI (Honduras SAR)

```sql
id, cliente_id(FK), establecimiento_id(FK), punto_emision_id(FK),
cai VARCHAR -- código CAI alfanumérico
rango_cai_inicio, rango_cai_fin, rango_inicio INT, rango_fin INT,
correlativo_actual INT DEFAULT 0, -- desplazamiento sobre rango_inicio
fecha_limite_emision DATE, activo TINYINT
```

### `clientes_factura` — Clientes a quienes se factura

```sql
id, cliente_id(FK), nombre, rtn VARCHAR, email, telefono, direccion,
departamento, municipio, exento_isv TINYINT DEFAULT 0
```

### `productos_clientes` — Catálogo de servicios/productos

```sql
id, cliente_id(FK), nombre, descripcion, precio DECIMAL(12,2),
isv TINYINT DEFAULT 15, -- % de ISV (0, 15, 18)
activo TINYINT DEFAULT 1
```

### `contratos` — Contratos de servicio

```sql
id, cliente_id(FK), receptor_id(FK→clientes_factura),
nombre_contrato VARCHAR(200),
producto_id INT(FK→productos_clientes), -- referencia al primer producto (legacy)
monto DECIMAL(12,2),                    -- monto total del contrato
fecha_inicio DATE, fecha_fin DATE NULL,
dia_pago TINYINT,                       -- día del mes para cobrar (1-31)
estado ENUM('activo','pausado','cancelado','vencido'),
notas TEXT NULL,
created_at TIMESTAMP
```

### `contratos_servicios` — Servicios dentro de un contrato (multi-servicio)

```sql
id, contrato_id(FK), producto_id(FK→productos_clientes), monto DECIMAL(12,2)
```

### `facturas` — Facturas emitidas (formato SAR Honduras)

```sql
id, cliente_id(FK), establecimiento_id(FK), punto_emision_id(FK),
cai_id(FK→cai_rangos), contrato_id(FK NULL),
receptor_id(FK→clientes_factura),
correlativo VARCHAR,    -- número CAI formateado
fecha_emision DATE,
subtotal DECIMAL(12,2), isv_15 DECIMAL(12,2), isv_18 DECIMAL(12,2),
total DECIMAL(12,2),
estado ENUM('emitida','anulada'),
notas TEXT NULL
```

### `factura_items` — Líneas de detalle de factura

```sql
id, factura_id(FK), producto_id(FK), descripcion VARCHAR,
cantidad DECIMAL(10,2), precio_unitario DECIMAL(12,2),
subtotal DECIMAL(12,2), isv TINYINT
```

### `gastos` — Registro de egresos

```sql
id, cliente_id(FK), categoria_id(FK NULL→categorias_gastos),
descripcion VARCHAR, monto DECIMAL(12,2),
fecha DATE, fecha_vencimiento DATE NULL,
frecuencia ENUM('unico','fijo_mensual','fijo_quincenal'),
dia_pago TINYINT NULL, dia_pago_2 TINYINT NULL,
tipo ENUM('fijo','variable','extraordinario','viaticos'),
metodo_pago ENUM('efectivo','transferencia','cheque','tarjeta','otro'),
estado ENUM('pagado','pendiente','anulado'),
quincena_num TINYINT NULL,  -- 1 o 2 para pagos quincenal de nómina
gasto_grupo_id INT NULL,    -- agrupa gastos relacionados
proveedor VARCHAR NULL,
factura_ref VARCHAR NULL,    -- referencia de factura del proveedor
archivo_adjunto VARCHAR NULL, archivo_nombre VARCHAR NULL,
comprobante_url VARCHAR NULL,
notas TEXT NULL, usuario_id INT NULL
```

### `categorias_gastos` — Categorías de egresos

```sql
id, cliente_id(FK), nombre, color VARCHAR(7), icono VARCHAR(50),
activa TINYINT DEFAULT 1
```

### `colaboradores` — Empleados en nómina

```sql
id, cliente_id(FK), nombre, apellido, puesto, departamento,
dpi VARCHAR NULL, telefono NULL, email NULL,
fecha_ingreso DATE, fecha_baja DATE NULL, activo TINYINT DEFAULT 1,
salario_base DECIMAL(12,2),
tipo_pago ENUM('quincenal','mensual'),
dia_pago TINYINT NULL,   -- 1er día de pago (o único si mensual)
dia_pago_2 TINYINT NULL, -- 2do día (solo quincenal)
aplica_ihss TINYINT DEFAULT 1,  -- descuento IHSS empleado (3.5%)
aplica_rap  TINYINT DEFAULT 1,  -- descuento RAP empleado (1.5%)
categoria_gasto_id INT NULL,    -- categoría para el gasto de nómina
banco VARCHAR NULL,
firma VARCHAR NULL, url_firma VARCHAR NULL,
notas TEXT NULL, usuario_id INT NULL
```

**Constantes de nómina Honduras:**

- IHSS_EMP = 3.5%, IHSS_PAT = 7%, tope = L10,294.10/mes
- RAP_EMP = 1.5%, RAP_PAT = 1.5% (sin tope)

### `colaborador_prestamos` — Movimientos financieros de empleados

```sql
id, cliente_id(FK), colaborador_id(FK),
tipo ENUM('prestamo','adelanto','bono','viatico','multa'),
descripcion VARCHAR, monto_total DECIMAL(12,2),
saldo_pendiente DECIMAL(12,2),
fecha DATE,
num_cuotas INT DEFAULT 1,
monto_cuota DECIMAL(12,2) NULL,
frecuencia_cuota ENUM('mensual','quincenal') NULL,
descuento_auto TINYINT DEFAULT 0, -- se descuenta automáticamente en nómina
estado ENUM('activo','pagado','cancelado'),
notas TEXT NULL
```

### `colaborador_prestamo_cuotas` — Cuotas de préstamos

```sql
id, prestamo_id(FK), cliente_id, colaborador_id,
numero_cuota INT, monto DECIMAL(12,2),
fecha_esperada DATE, fecha_pago DATE NULL,
estado ENUM('pendiente','pagado','cancelado'),
metodo_pago ENUM(...) NULL,
notas VARCHAR NULL
```

---

## 5. LÓGICA DE NÓMINA

```php
// calcNeto(): calcula desglose salarial
// Input: salario_base, aplica_ihss, aplica_rap, tipo_pago ('quincenal'|'mensual')
// Output: bruto_pago, ihss_emp, rap_emp, neto_pago, ihss_pat, rap_pat, costo_total
// Para quincenal: todos los valores divididos entre 2

// Al registrar pago de nómina (colaborador_pago_guardar.php):
// 1. Se crea registro en `gastos` con descripción "Sueldo {nombre} [— 1ª/2ª Quincena]"
// 2. Se aplican cuotas automáticas (descuento_auto=1): se marcan como 'pagado'
// 3. Se aplican bonos pendientes (tipo='bono'): se marcan como 'pagado', registro extra en gastos
// 4. Se liquidan viáticos pendientes (tipo='viatico'): igual que bonos
// 5. Monto final = neto_pago - descuentos_cuotas + bonos + viáticos
```

---

## 6. FACTURACIÓN HONDURAS (CAI/SAR)

- Cada factura necesita: establecimiento + punto de emisión + CAI activo
- Correlativo: formato `001-001-01-00000001` (generado por `generarCorrelativoFactura()`)
- ISV: 15% o 18% dependiendo del producto (algunos exentos 0%)
- La tabla `facturas` guarda subtotal (sin ISV), isv_15, isv_18, total
- `factura_items` guarda el detalle línea por línea

---

## 7. CONTRATOS — LÓGICA ACTUAL

```php
// Flujo actual:
// 1. crear_contrato.php → POST → includes/contrato_guardar.php → JSON
// 2. editar_contrato.php → POST → includes/contrato_actualizar.php → JSON
// 3. La tabla contratos tiene producto_id y monto (campos legacy para 1 solo servicio)
// 4. La tabla contratos_servicios soporta múltiples servicios por contrato
// 5. Al crear factura: generar_factura.php?receptor_id=X&producto_id=Y&monto=Z&contrato_id=N

// Detección de nóminas vencidas (colaborador_ver.php):
// - Se compara día_pago con fecha actual
// - Si la quincena/mensualidad no tiene registro en `gastos` → alerta roja

// Contratos "próximos cobros":
// - La query SQL calcula proxima_fecha_pago y dias_para_pago dinámicamente
// - facturado_este_mes: COUNT(*) de facturas del contrato en el mes actual
```

---

## 8. ESTADO DE RESULTADOS (financiero.php)

- **Ingresos**: suma de `facturas.subtotal` donde estado='emitida' (sin ISV)
- **Egresos**: suma de `gastos.monto` donde estado!='anulado'
- **Utilidad**: ingresos - egresos
- Vista anual: tabla mes a mes (12 columnas)
- Vista mensual: filtra a un mes específico
- KPIs adicionales: contratos activos (ingreso recurrente), nómina mensual proyectada
- Chart.js: barras Ingresos+Egresos + línea Utilidad

---

## 9. NUEVAS FUNCIONALIDADES A IMPLEMENTAR

### 9.1. TIPOS DE CONTRATO AMPLIADOS

**Tipo A: Estándar (existente)** — cobro mensual, día fijo, 1+ clientes
**Tipo B: Periódico** — cobro cada N meses (2, 3, 4, 6, 12), día fijo
**Tipo C: Rotativo Multi-cliente** — se cobra a diferentes clientes en meses alternos

- Ej: Mes 1 → Cliente A, Mes 2 → Cliente B, Mes 3 → Cliente A...
- Puede ser periódico también (cada 2 meses rota entre 2 clientes)
  **Tipo D: Sin facturación (Recibo)** — no genera factura CAI, genera recibo interno

Cada tipo necesita campos adicionales en `contratos`.

### 9.2. ESTADO DE RESULTADOS MEJORADO

- Toggle "Incluir contratos sin facturación": suma ingresos proyectados de contratos Tipo D
- Nueva página `proyeccion.php`: tabla de flujo de caja proyectado a 12 meses
    - Columnas: mes, ingresos proyectados, egresos estimados, flujo neto
    - Alertas visuales: meses con flujo negativo (rojo), meses críticos (amarillo)
    - Recomendaciones automáticas basadas en tendencias

---

## 10. PATRONES DE CÓDIGO

### PHP — endpoint AJAX (include)

```php
<?php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $cliente_id = (int)(USUARIO_ROL === 'superadmin'
        ? ($_SESSION['cliente_seleccionado'] ?? 0)
        : CLIENTE_ID);
    // ... lógica ...
    echo json_encode(['success' => true, 'message' => '...']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
```

### JS — llamada AJAX con fetch

```javascript
fetch("includes/mi_endpoint.php", {
    method: "POST",
    body: new FormData(document.getElementById("miForm")),
})
    .then((r) => r.json())
    .then((d) => {
        if (d.success)
            Swal.fire({ icon: "success", title: "¡Listo!", timer: 1500 }).then(
                () => location.reload(),
            );
        else Swal.fire("Error", d.error, "error");
    });
```

### CSS — Design System (variables :root estándar)

```css
:root {
    --brand: #0f766e; /* teal principal */
    --brand-dark: #065f46;
    --brand-lt: #ccfbf1;
    --surface: #fff;
    --surface-2: #f8fafc;
    --border: #e2e8f0;
    --text: #0f172a;
    --muted: #64748b;
    --radius: 16px;
    --radius-sm: 10px;
    --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06);
    --shadow-md: 0 6px 24px rgba(0, 0, 0, 0.08);
    --tr: 0.18s cubic-bezier(0.4, 0, 0.2, 1);
    font-family: "Plus Jakarta Sans", sans-serif;
}
```

### HTML — Estructura de página estándar

```php
<?php
$titulo = 'Nombre Página';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/templates/header.php';
// ... lógica PHP ...
?>
<!-- HTML de la página -->
<script>/* JS de la página */</script>
<?php require_once '../../includes/templates/footer.php'; ?>
```

---

## 11. DISEÑO VISUAL — COMPONENTES FRECUENTES

### Header de página

```html
<div class="ct-header">
    <!-- gradiente teal -->
    <div>
        <h4>📄 Título</h4>
        <p class="sub">Subtítulo</p>
    </div>
    <a href="..." class="btn-new-ct">+ Nuevo</a>
</div>
```

### Stat card (KPI)

```html
<div class="cv-kpi">
    <div class="cv-kpi-icon ki-teal"><i class="bi bi-..."></i></div>
    <div>
        <div class="cv-kpi-val">L 12,500</div>
        <div class="cv-kpi-lbl">Descripción</div>
    </div>
</div>
```

### Alert banner (avisos importantes)

```html
<div class="cv-alert-banner warn">
    <!-- o: error, success -->
    <i class="bi bi-exclamation-triangle-fill"></i>
    <div>Mensaje</div>
    <button class="btn btn-sm ms-auto">Acción</button>
</div>
```

### Tipo card selector (selección visual)

```html
<div class="tipo-selector">
    <div class="tipo-card tc-bono tc-selected" data-val="bono" data-group="new">
        <input
            type="radio"
            class="tipo-radio"
            name="tipo"
            value="bono"
            checked
        />
        <div class="tc-icon-wrap"><i class="bi bi-gift-fill"></i></div>
        <div class="tc-label">Bono</div>
        <div class="tc-desc">Sin descuento</div>
        <div class="tc-check"><i class="bi bi-check-circle-fill"></i></div>
    </div>
</div>
```

---

## 12. REGLAS DE NEGOCIO IMPORTANTES

1. **Multi-tenant absoluto**: TODA query lleva `AND cliente_id = ?`
2. **Nómina Honduras**: IHSS se aplica sobre min(salario, 10294.10). RAP sin tope.
3. **Facturas**: solo se emiten con CAI activo. El correlativo es secuencial por punto de emisión.
4. **Gastos de nómina**: se registran en tabla `gastos` con descripción "Sueldo {nombre}" para que aparezcan en el Estado de Resultados
5. **ISV**: no forma parte de ingresos ni egresos operativos. Solo se reporta informativo.
6. **Contratos**: el campo `dia_pago` es el día del mes (1-31). Si el mes tiene menos días, se usa el último día del mes.
7. **Detección de quincena vencida**: se busca registro en `gastos` con descripción LIKE 'Sueldo {nombre}%' del mes/año actual con quincena_num = 1 o 2
