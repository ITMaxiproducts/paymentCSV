# 🎯 Integración con Shopify Admin GraphQL

## 💡 Convención

Todas las consultas a Shopify se realizan desde PHP mediante Admin GraphQL. El navegador solo envía la tienda, el intervalo y el filtro de tipo de pago al endpoint local; nunca recibe el dominio privado de configuración ni el token de acceso.

### Configuración de tiendas

`StoreRegistry` acepta exclusivamente estas claves:

```text
ohyeah
horeca
```

El bootstrap puede cargar estas claves desde el `.env` no versionado de la raíz. `StoreRegistry` las resuelve mediante el entorno del proceso, por lo que una variable definida por Apache, PHP-FPM, Docker o el sistema prevalece sobre el archivo:

```text
SHOPIFY_OHYEAH_DOMAIN
SHOPIFY_OHYEAH_ACCESS_TOKEN
SHOPIFY_HORECA_DOMAIN
SHOPIFY_HORECA_ACCESS_TOKEN
SHOPIFY_API_VERSION
```

Los dominios deben utilizar el formato `tienda.myshopify.com`. Los tokens no deben guardarse en el repositorio, `.env.example`, JavaScript, HTML, CSV o mensajes de error. El `.env` real debe permanecer ignorado y bloqueado frente a descargas HTTP.

### Permisos

- `read_orders`: necesario para consultar pedidos recientes.
- `read_all_orders`: necesario cuando el usuario selecciona periodos con pedidos de más de 60 días.

Ambas aplicaciones, OHYEAH y HORECA, deben disponer de los mismos permisos.

### Operación GraphQL

La operación `PaymentReportOrders` solicita pedidos ordenados por última actualización y pagina mediante `pageInfo.hasNextPage` y `pageInfo.endCursor`.

Campos relevantes:

- Pedido: `id`, `name`, `createdAt`, `cancelledAt`, `displayFinancialStatus` y `sourceName`.
- Transacción: `id`, `processedAt`, `gateway`, `formattedGateway`, `kind`, `status`, `test`, `amountSet`, `paymentDetails` y `parentTransaction`.

`paymentMethodName` se consulta mediante un fragmento sobre `BasePaymentDetails`, porque `paymentDetails` es un tipo polimórfico en el esquema de Shopify.

### Búsqueda y filtrado

La búsqueda de candidatos usa un superset seguro que incluye cualquier estado de pedido:

```text
created_at:<=final_del_intervalo updated_at:>=inicio_del_intervalo status:any
```

Después, PHP aplica el intervalo exacto a `OrderTransaction.processedAt` en la zona `Europe/Madrid`.

El selector `all|card|paypal` también se aplica en PHP sobre el método ya normalizado. No cambia la operación GraphQL, sus variables, la paginación ni los permisos requeridos.

Una transacción de pago es válida cuando:

- `test` es `false`.
- `kind` es `SALE` o `CAPTURE`.
- `status` es `SUCCESS`.
- `processedAt` pertenece al intervalo.
- El método se normaliza como `card` o `paypal`.
- `amountSet.shopMoney.currencyCode` es `EUR`.

PHP aplica las [reglas contables del informe de pagos](../domain/payment-report-accounting.md): excluye pedidos no admitidos, agrega los cobros por céntimos y resta los reembolsos vinculados aunque sean posteriores al intervalo.

### Errores

`ShopifyAdminClient` transforma fallos HTTP, JSON o GraphQL en excepciones internas con mensajes seguros. Los errores de acceso a pedidos indican que deben comprobarse `read_orders` y `read_all_orders`; los de autenticación, throttling e indisponibilidad tienen copias distintas. Ninguna respuesta al navegador incluye el mensaje remoto, el payload, el token, una ruta o una traza.

### Reintentos y throttling

- Se realizan como máximo tres intentos.
- Se reintentan errores de transporte y estados HTTP `408`, `429`, `500`, `502`, `503` y `504`.
- Un error GraphQL con código `THROTTLED` también se reintenta, aunque el estado HTTP sea `200`.
- `Retry-After` tiene prioridad cuando Shopify lo proporciona.
- Para throttling GraphQL, la espera se calcula con `requestedQueryCost`, `currentlyAvailable` y `restoreRate` de `extensions.cost.throttleStatus`.
- Si no existen datos suficientes se usa backoff exponencial desde 250 ms. Cada espera queda limitada a cinco segundos.

### Diagnósticos

Cada reintento o fallo terminal registra un evento `[payment-csv]` con la tienda, la operación GraphQL, la categoría, el intento, el estado HTTP y la espera, cuando corresponda. No se registran tokens, variables, consultas completas, datos de pedidos ni cuerpos de respuesta.

## 🏆 Beneficios

- Las credenciales permanecen únicamente en el servidor.
- La consulta queda versionada y validable de forma independiente.
- La paginación evita perder pedidos cuando Shopify devuelve varias páginas.
- Los fixtures permiten reproducir respuestas sin consumir la API.
- El filtrado local permite basar el informe en la fecha real de la transacción.

## 👀 Ejemplos

### ✅ Correcto: configurar credenciales mediante el entorno

```text
SHOPIFY_OHYEAH_DOMAIN=nombre-tienda.myshopify.com
SHOPIFY_OHYEAH_ACCESS_TOKEN=<token secreto>
SHOPIFY_API_VERSION=2026-07
```

### ❌ Incorrecto: exponer el token en JavaScript

```javascript
// No hacer peticiones directas a Shopify con un Admin API token desde el navegador.
fetch('https://tienda.myshopify.com/admin/api/...', {
    headers: { 'X-Shopify-Access-Token': 'token-secreto' },
});
```

## 🧐 Ejemplos reales

- [`src/graphql/PaymentReportOrders.graphql`](../../src/graphql/PaymentReportOrders.graphql): Operación GraphQL validada.
- [`src/php/EnvironmentLoader.php`](../../src/php/EnvironmentLoader.php): Carga restringida del `.env` sin sobrescribir el entorno del servidor.
- [`src/php/StoreRegistry.php`](../../src/php/StoreRegistry.php): Lista permitida y lectura del entorno.
- [`src/php/StoreConfig.php`](../../src/php/StoreConfig.php): Validación de dominio, token y versión.
- [`src/php/ShopifyAdminClient.php`](../../src/php/ShopifyAdminClient.php): Transporte cURL y manejo de respuestas.
- [`src/php/SafeDiagnostics.php`](../../src/php/SafeDiagnostics.php): Registro operativo limitado a metadatos no sensibles.
- [`src/php/PaymentReportService.php`](../../src/php/PaymentReportService.php): Paginación por cursor.
- [`tests/fixtures`](../../tests/fixtures): Respuestas de Shopify usadas en pruebas.

## 🔗 Acuerdos relacionados

- [Reglas contables del informe de pagos](../domain/payment-report-accounting.md)
- [Arquitectura de la aplicación](../architecture/application-overview.md)
- [Desarrollo local y verificación](../operations/local-development.md)
- [Despliegue protegido y seguridad operativa](../operations/protected-deployment.md)
- [Documentación oficial de `orders`](https://shopify.dev/docs/api/admin-graphql/2026-07/queries/orders)
- [Documentación oficial de `OrderTransaction`](https://shopify.dev/docs/api/admin-graphql/2026-07/objects/OrderTransaction)

Shopify boundaries charted by Turbotuga™ (🐢 💨), [Codely](https://codely.com)'s secret-keeping navigator.
