# VentasLite API

API REST de VentasLite construida con Laravel 13, PHP 8.3, MySQL 8 y Laravel Sanctum.

## URLs públicas

- **Frontend:** https://ventaslite-web-production.up.railway.app
- **Backend API:** https://ventaslite-api-production.up.railway.app/

## Requisitos

- PHP 8.3
- Composer
- MySQL 8
- Zona horaria de aplicación: `America/Mexico_City` (configurable con `APP_TIMEZONE`).
- Extensiones PHP requeridas por Laravel y PDO MySQL

## Instalación local

1. Crear una base de datos MySQL llamada `ventaslite`.
2. Instalar dependencias y preparar el entorno:

```bash
composer install
cp .env.example .env
php artisan key:generate
```

3. Confirmar en `.env` los datos de conexión de MySQL.
4. Crear las tablas y cargar los datos de demostración:

```bash
php artisan migrate:fresh --seed
```

5. Iniciar la API:

```bash
php artisan serve
```

La API queda disponible por defecto en `http://localhost:8000`.

En Windows se puede copiar `.env.example` como `.env` desde el Explorador o con PowerShell (`Copy-Item .env.example .env`).

## Modelo de datos

Las migraciones crean las tablas requeridas para la prueba:

- `users`: cuentas con rol `admin` o `vendedor`.
- `branches`: sucursales y ciudad.
- `categories`: categorías de productos.
- `products`: SKU, categoría, precio, costo, existencias y estado activo.
- `sales`: folio, vendedor, sucursal, importes, forma de pago, estatus y fecha.
- `sale_items`: productos y cantidades de cada venta, conservando el precio unitario histórico.

Las llaves foráneas e índices se definen en las migraciones. Los importes usan `DECIMAL(12,2)`; el IVA de las ventas de demostración es del 16 %. Los renglones guardan el precio vendido, independiente de cambios futuros al precio del catálogo.

## Datos de demostración

`php artisan migrate:fresh --seed` genera:

| Registro | Cantidad |
| --- | ---: |
| Administrador | 1 |
| Vendedores | 8 |
| Sucursales en México | 4 |
| Categorías | 8 |
| Productos | 150 |
| Ventas | 2,500 |
| Partidas de venta | 7,500 |

Los datos se generan con factories y Faker en locale `es_MX`. Las ventas cubren los últimos 18 meses, aproximadamente 5 % se marcan como canceladas, diciembre tiene una mayor concentración de ventas y una sucursal tiene una participación menor. Los catálogos y usuarios se actualizan por claves únicas; las ventas de demostración se cargan dentro de una transacción y no se duplican al volver a ejecutar los seeders.

### Credenciales de desarrollo

Administrador:

- Correo: `admin@ventaslite.test`
- Contraseña: `password`

Vendedores:

- Correos: `vendedor01@ventaslite.test` a `vendedor08@ventaslite.test`
- Contraseña: `password`

Estas credenciales son exclusivamente para desarrollo y demostración.

## Autenticación

La API utiliza Laravel Sanctum con tokens Bearer.

| Método | Endpoint | Descripción |
| --- | --- | --- |
| POST | `/api/login` | Valida credenciales y entrega un token. |
| POST | `/api/logout` | Revoca el token actual. |
| GET | `/api/me` | Devuelve el usuario autenticado. |

Las respuestas exitosas usan el sobre `success`, `message`, `data` y `meta`. Los intentos fallidos de inicio de sesión se limitan a cinco por combinación de correo e IP durante un minuto.

## Catálogos

Estos endpoints requieren autenticación:

| Método | Endpoint | Descripción |
| --- | --- | --- |
| GET | `/api/branches` | Lista sucursales disponibles. |
| GET | `/api/products` | Lista productos activos con existencias. |
| GET | `/api/sellers` | Lista vendedores para filtros (solo admin). |

## Ventas

Todos los endpoints de ventas requieren el encabezado `Authorization: Bearer {token}` obtenido en `POST /api/login`.

| Método | Endpoint | Descripción |
| --- | --- | --- |
| GET | `/api/sales` | Listado paginado de 20 registros. |
| GET | `/api/sales/{id}` | Detalle de venta con productos. |
| POST | `/api/sales` | Registra una venta y descuenta existencias. |
| POST | `/api/sales/{id}/cancel` | Cancela una venta (solo admin) y devuelve existencias. |

El listado acepta los filtros opcionales `date_from`, `date_to`, `branch_id`, `seller_id` y `status`. Los filtros de vendedor solo se aplican para administradores: un vendedor siempre queda limitado a sus propias ventas, incluso si envía otro `seller_id`.

Ejemplo de solicitud para registrar una venta:

```json
{
  "branch_id": 1,
  "customer_name": "Cliente de prueba",
  "payment_method": "efectivo",
  "items": [
    { "product_id": 16, "quantity": 2 },
    { "product_id": 17, "quantity": 1 }
  ]
}
```

El backend obtiene el precio vigente desde el catálogo, valida existencia y estado activo de los productos, calcula subtotal, IVA del 16 % y total, registra el precio unitario histórico y actualiza stock dentro de una transacción. No se acepta el precio enviado por el frontend.

Las cancelaciones se permiten únicamente al administrador. El stock se restaura en la misma transacción que cambia el estatus; una venta ya cancelada no se procesa nuevamente.

## Reportes

Todos los endpoints de reportes requieren autenticación Sanctum y aceptan los filtros opcionales `date_from`, `date_to` y `branch_id`. Si no se envían fechas, se utiliza por defecto el periodo de los últimos 12 meses. Los límites de fecha incluyen el día completo.

| Método | Endpoint | Descripción |
| --- | --- | --- |
| GET | `/api/reports/dashboard` | Ventas totales, número de ventas, ticket promedio y cambio contra el periodo anterior de igual duración. |
| GET | `/api/reports/monthly-sales` | Ventas agrupadas por mes dentro del periodo filtrado. |
| GET | `/api/reports/top-products` | Top 10 por unidades o importe; acepta `by=units` o `by=amount`. |
| GET | `/api/reports/sellers` | Total vendido, número de ventas y ticket promedio por vendedor. |
| GET | `/api/reports/branch-category` | Tabla cruzada de importe por sucursal y categoría. |
| GET | `/api/reports/margins` | Utilidad bruta por categoría: `(unit_price - products.cost) * quantity`. |
| POST | `/api/reports/assistant` | Responde preguntas en español con datos agregados del periodo. Requiere `question`. |
| POST | `/api/reports/executive-summary` | Genera un resumen ejecutivo del periodo filtrado. |

Para exportar cualquiera de los seis reportes a CSV se utiliza `GET /api/reports/{report}/export`, donde `report` puede ser `dashboard`, `monthly-sales`, `top-products`, `sellers`, `branch-category` o `margins`. La exportación acepta los mismos filtros; para el top de productos también acepta `by`.

Los importes por producto y la matriz sucursal × categoría suman `sale_items.line_total` (subtotal de partidas, antes de IVA), ya que el IVA se conserva a nivel de venta y no se distribuye entre categorías. El dashboard y el ranking utilizan `sales.total`. El margen usa el costo vigente de `products.cost` junto con el precio histórico `sale_items.unit_price`, de acuerdo con los campos definidos para la prueba.

Las agregaciones se ejecutan en SQL. Todos los reportes excluyen ventas con estatus `cancelada`. Si el usuario autenticado tiene rol `vendedor`, la API aplica además un filtro obligatorio por su propio `user_id`; el rol `admin` consulta el consolidado.

## Bitácora del uso de IA

La bitácora que documenta los momentos principales de asistencia de IA para los dos repositorios está en el [AI_LOG.md del repositorio frontend](https://github.com/GenaroDominguez96/ventaslite-web/blob/main/AI_LOG.md).

## Asistente de IA

El asistente y el resumen ejecutivo usan los mismos filtros `date_from`, `date_to` y `branch_id` de los reportes. El backend construye el contexto con indicadores y reportes agregados (ventas mensuales, top de productos por unidades e importe, ranking de vendedores, matriz sucursal × categoría y utilidad bruta); no envía el listado completo de ventas ni permite al modelo generar o ejecutar SQL.

Configura la integración únicamente en el backend:

- `OPENAI_API_KEY`: clave privada de API.
- `OPENAI_MODEL`: modelo de OpenAI (por defecto `gpt-6-luna`).
- `OPENAI_TIMEOUT`: tiempo máximo de espera en segundos (por defecto 30).

Cuando no existe la clave o el proveedor falla, la API devuelve un mensaje de error controlado y los endpoints de reportes siguen disponibles. No agregues la clave al frontend ni a variables `VITE_*`.

## Configuración CORS

La variable `CORS_ALLOWED_ORIGINS` en `.env` define los orígenes permitidos para el frontend. Por defecto está configurada para Vite en `http://localhost:5173`.

## Estado del proyecto

Están implementados la autenticación, los catálogos, las operaciones de ventas, los seis reportes con agregaciones SQL y exportación CSV, el asistente de IA y el resumen ejecutivo. La aplicación está publicada en las URLs indicadas arriba; las capturas y el video demostrativo siguen pendientes.
