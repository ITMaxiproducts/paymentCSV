# 🎯 Integración con Shopify Admin GraphQL

## 💡 Convención

Todas las consultas a Shopify se realizan desde PHP mediante Admin GraphQL. El navegador solo envía la tienda y el intervalo al endpoint local; nunca recibe el dominio privado de configuración ni el token de acceso.

### Configuración de tiendas

`StoreRegistry` acepta exclusivamente estas claves:

```text
ohyeah
horeca
```

Cada clave se resuelve con variables de entorno del servidor:

```text
SHOPIFY_OHYEAH_DOMAIN
SHOPIFY_OHYEAH_ACCESS_TOKEN
SHOPIFY_HORECA_DOMAIN
SHOPIFY_HORECA_ACCESS_TOKEN
SHOPIFY_API_VERSION
```

Los dominios deben utilizar el formato `tienda.myshopify.com`. Los tokens no deben guardarse en el repositorio, JavaScript, HTML, CSV o mensajes de error.

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

Una transacción de pago es válida cuando:

- `test` es `false`.
- `kind` es `SALE` o `CAPTURE`.
- `status` es `SUCCESS`.
- `processedAt` pertenece al intervalo.
- El método se normaliza como `card` o `paypal`.
- `amountSet.shopMoney.currencyCode` es `EUR`.

PHP excluye pedidos cancelados y aquellos cuyo `sourceName` no es `web`. Agrega todas las ventas y capturas válidas por céntimos, conserva la fecha de la primera, combina valores distintos en orden cronológico y resta cada transacción `REFUND` satisfactoria cuyo `parentTransaction.id` apunta a uno de esos cobros. Los reembolsos se aplican aunque su `processedAt` sea posterior al intervalo, y el neto nunca baja de `0.00 EUR`.

### Errores

`ShopifyAdminClient` transforma fallos HTTP, JSON o GraphQL en excepciones internas. Los errores de acceso a pedidos indican de forma segura que deben comprobarse `read_orders` y `read_all_orders`; no incluyen el mensaje remoto, el payload, el token ni una traza. `export.php` conserva esa indicación y generaliza el resto de fallos.

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
- [`src/php/StoreRegistry.php`](../../src/php/StoreRegistry.php): Lista permitida y lectura del entorno.
- [`src/php/StoreConfig.php`](../../src/php/StoreConfig.php): Validación de dominio, token y versión.
- [`src/php/ShopifyAdminClient.php`](../../src/php/ShopifyAdminClient.php): Transporte cURL y manejo de respuestas.
- [`src/php/PaymentReportService.php`](../../src/php/PaymentReportService.php): Paginación por cursor.
- [`tests/fixtures`](../../tests/fixtures): Respuestas de Shopify usadas en pruebas.

## 🔗 Acuerdos relacionados

- [Arquitectura de la aplicación](../architecture/application-overview.md)
- [Desarrollo local y verificación](../operations/local-development.md)
- [Documentación oficial de `orders`](https://shopify.dev/docs/api/admin-graphql/2026-07/queries/orders)
- [Documentación oficial de `OrderTransaction`](https://shopify.dev/docs/api/admin-graphql/2026-07/objects/OrderTransaction)

Shopify boundaries charted by Turbotuga™ (🐢 💨), [Codely](https://codely.com)'s secret-keeping navigator.
