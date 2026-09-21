# 🎯 Despliegue protegido y seguridad operativa

## 💡 Convención

Shopify Payment CSV debe desplegarse detrás de la protección de acceso, TLS y límites del servidor de la organización. La aplicación no implementa autenticación propia: su responsabilidad empieza al recibir una petición ya autorizada y termina al devolver un CSV o un error seguro.

### Superficie pública

Solo `index.php`, `export.php` y los recursos necesarios de `src` deben quedar accesibles desde el servidor web. `.env`, `.git`, `.agents`, `tests`, `scripts` y `docs` deben permanecer fuera del document root o bloquearse en la configuración del servidor. `.htaccess` bloquea `.env` en Apache; otros servidores requieren una regla equivalente.

El bootstrap carga el `.env` de la raíz cuando existe y pesa como máximo 64 KiB. Solo admite las cinco claves Shopify documentadas. Una variable ya definida por el proceso nunca se reemplaza con el valor del archivo.

`POST /export.php` acepta exclusivamente:

- `Content-Type: multipart/form-data`.
- Un cuerpo de hasta 16 KiB.
- Los campos escalares `shop`, `date_from` y `date_to`.
- Las tiendas `ohyeah` y `horeca`.
- Fechas de calendario `YYYY-MM-DD` en orden y con un máximo de 92 días inclusivos.

Los métodos, tipos de contenido, tamaños, campos, tiendas o fechas no admitidos se rechazan antes de consultar Shopify.

### Estados y mensajes seguros

| Situación | Estado | Comportamiento |
| --- | ---: | --- |
| Método distinto de `POST` | `405` | Devuelve `Allow: POST`. |
| Petición mal formada | `400` | No repite datos recibidos. |
| Cuerpo superior a 16 KiB | `413` | Rechaza la petición antes de procesarla. |
| Tipo distinto de `multipart/form-data` | `415` | Indica el formato admitido. |
| Campos, tienda o fechas inválidos | `422` | Devuelve una corrección accionable en castellano. |
| Configuración ausente | `503` | No identifica variables de entorno ni rutas. |
| Autenticación o permisos de Shopify | `502` | Distingue credenciales de permisos históricos sin copiar la respuesta remota. |
| Throttling agotado | `503` | Incluye `Retry-After` sin revelar datos internos. |
| Shopify no disponible | `502` | Devuelve un mensaje temporal y general. |
| Fallo interno inesperado | `500` | No devuelve excepciones, trazas ni rutas. |

Las respuestas de error usan JSON UTF-8, `Cache-Control: no-store` y `X-Content-Type-Options: nosniff`. Nunca deben incluir tokens, consultas o variables GraphQL, cuerpos remotos, datos de pedidos, trazas o rutas del sistema.

### Resiliencia de Shopify

`ShopifyAdminClient` realiza como máximo tres intentos. Solo reintenta errores de transporte, estados `408`, `429`, `500`, `502`, `503` y `504`, y errores GraphQL `THROTTLED`.

La espera sigue este orden:

1. `Retry-After`, si Shopify lo proporciona.
2. El déficit entre `requestedQueryCost` y `currentlyAvailable`, dividido por `restoreRate`.
3. Backoff exponencial desde 250 ms cuando no hay información de límite.

Cada espera está limitada a cinco segundos. Los errores de autenticación, permisos y fallos no transitorios no se reintentan.

### CSV y resultados vacíos

El CSV mantiene sus diez columnas públicas, usa UTF-8, coma, comillas dobles con escape estándar y terminadores CRLF. Las celdas de texto que comienzan con `=`, `+`, `-` o `@`, incluso después de controles o espacios ASCII, reciben un apóstrofo inicial para impedir que una hoja de cálculo las ejecute como fórmulas.

Un informe sin coincidencias sigue siendo correcto: devuelve `200`, las diez cabeceras, ninguna fila de datos y `X-Export-Row-Count: 0`. La interfaz descarga el archivo e informa expresamente que está vacío.

### Diagnóstico y verificación

Los eventos `[payment-csv]` pueden registrar únicamente tienda, operación, categoría, intento, estado HTTP y espera aplicada. El registro de errores de PHP debe almacenarse fuera de la superficie pública.

Antes de desplegar se ejecuta `scripts/verify.php`. El smoke test local comprueba la interfaz y las respuestas `405`, `415` y `503`; una descarga `200` puede comprobarse con fixtures no secretos. Autenticación, permisos y conectividad reales se validan únicamente en el entorno protegido con una tienda de prueba o un intervalo controlado.

## 🏆 Beneficios

- Conserva los secretos de Shopify exclusivamente en el proceso del servidor.
- Reduce la superficie de ataque antes de ejecutar lógica de negocio o consumir la API.
- Evita que mensajes y registros conviertan fallos remotos en filtraciones de datos.
- Tolera interrupciones transitorias sin crear bucles de reintento ni esperas indefinidas.
- Impide que referencias de pedidos o pasarelas se ejecuten como fórmulas al abrir el CSV.
- Hace que los resultados vacíos y los fallos operativos sean distinguibles y verificables.
- Mantiene la autenticación en la infraestructura que ya protege la herramienta, sin duplicarla en la aplicación.

## 👀 Ejemplos

### ✅ Correcto: validar antes de coordinar la exportación

```php
$input = ExportRequestValidator::validate($_SERVER, $_POST, $_FILES);
$result = (new ExportController())->export($input);
CsvResponse::stream($result->rows, $result->filename);
```

### ✅ Correcto: registrar solo metadatos operativos

```php
SafeDiagnostics::record('shopify_request', [
    'store' => $store->key,
    'operation' => 'PaymentReportOrders',
    'category' => 'throttling_graphql',
    'attempt' => 2,
]);
```

### ❌ Incorrecto: devolver o registrar la excepción remota completa

```php
// Puede contener tokens, variables, datos personales, rutas o el cuerpo de Shopify.
error_log($exception->getTraceAsString());
echo json_encode(['error' => $exception->getMessage(), 'payload' => $payload]);
```

### ❌ Incorrecto: añadir un modo fixture a la aplicación desplegada

```php
// Los fixtures solo pertenecen a pruebas y smoke tests temporales.
if (getenv('USE_FIXTURE') === '1') {
    return file_get_contents(__DIR__ . '/tests/fixtures/orders-page-1.json');
}
```

## 🧐 Ejemplos reales

- [`export.php`](../../export.php): Mapeo de errores y adaptador HTTP público.
- [`src/php/EnvironmentLoader.php`](../../src/php/EnvironmentLoader.php): Carga restringida de `.env` con prioridad para el entorno del servidor.
- [`src/php/ExportRequestValidator.php`](../../src/php/ExportRequestValidator.php): Método, contenido, tamaño y forma de la petición.
- [`src/php/ShopifyAdminClient.php`](../../src/php/ShopifyAdminClient.php): Reintentos acotados y clasificación de fallos.
- [`src/php/SafeDiagnostics.php`](../../src/php/SafeDiagnostics.php): Diagnósticos sin secretos ni datos personales.
- [`src/php/CsvEncoder.php`](../../src/php/CsvEncoder.php): Escape CSV y neutralización de fórmulas.
- [`src/php/CsvResponse.php`](../../src/php/CsvResponse.php): Cabeceras de descarga y conteo de filas.
- [`src/js/main.js`](../../src/js/main.js): Recuperación de errores, exportaciones lentas e informes vacíos.
- [`tests/run.php`](../../tests/run.php): Casos de seguridad, resiliencia, codificación y filtración de secretos.

## 🔗 Acuerdos relacionados

- [Arquitectura de la aplicación](../architecture/application-overview.md)
- [Reglas contables del informe](../domain/payment-report-accounting.md)
- [Integración con Shopify Admin GraphQL](../integrations/shopify-admin-graphql.md)
- [Desarrollo local y verificación](local-development.md)
- [Guía de despliegue y uso](../../README.md)

Protected exports guarded by Turbotuga™ (🐢 💨), [Codely](https://codely.com)'s deployment-safety navigator.
