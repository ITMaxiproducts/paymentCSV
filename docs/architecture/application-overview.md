# 🎯 Arquitectura de Shopify Payment CSV

## 💡 Convención

La aplicación es una herramienta web pequeña y sin dependencias de runtime que genera informes CSV de pagos de Shopify. Su arquitectura mantiene la interfaz, el transporte HTTP, la integración con Shopify y la transformación del informe separados mediante clases PHP sencillas.

### Stack

- PHP 8.1 o superior con la extensión cURL.
- HTML5 y JavaScript nativo.
- Bootstrap 5.3.8 cargado desde CDN.
- Shopify Admin GraphQL API.
- CSV generado en memoria y enviado como descarga HTTP.
- Sin framework PHP, Composer, framework JavaScript, proceso de compilación o base de datos.

### Flujo principal

```text
index.php
  -> src/js/main.js valida tienda y fechas
  -> POST /export.php
  -> EnvironmentLoader carga .env sin sobrescribir el entorno del proceso
  -> ExportRequestValidator valida transporte y forma de la petición
  -> ExportController valida y coordina la petición
  -> StoreRegistry carga la tienda desde variables de entorno
  -> PaymentReportService pagina pedidos desde Shopify
  -> PaymentReportRowFactory selecciona la transacción válida
  -> CsvEncoder genera el contenido
  -> CsvResponse devuelve la descarga
```

### Contrato HTTP

La exportación se solicita mediante:

```http
POST /export.php
Content-Type: multipart/form-data
```

Campos:

```text
shop: ohyeah | horeca
date_from: YYYY-MM-DD
date_to: YYYY-MM-DD
```

Una respuesta correcta utiliza `text/csv; charset=UTF-8` y un nombre con el formato:

```text
pagos-shopify-{shop}-{from}-{to}.csv
```

### Contrato CSV

```csv
Fecha del pedido,Fecha de la transacción,Referencia del pedido,Estado del pago,Método de pago,Pasarela de pago,Tipo de transacción,Estado de la transacción,Importe de la transacción,Moneda
```

El separador es una coma, los decimales usan punto, las fechas se convierten a `Europe/Madrid` y los importes se expresan en EUR con dos decimales.
Los registros terminan en CRLF, los campos siguen el escape CSV estándar y las celdas de texto que podrían interpretarse como fórmulas se neutralizan con un apóstrofo inicial.

### Alcance implementado

Las Fases 1, 2 y 3 permiten:

- Elegir OHYEAH o HORECA.
- Seleccionar un intervalo inclusivo de hasta 92 días.
- Consultar pedidos con paginación por cursor.
- Filtrar transacciones `SALE` o `CAPTURE`, con estado `SUCCESS` y no marcadas como prueba.
- Normalizar tarjeta, wallets basados en tarjeta y PayPal.
- Agregar todas las ventas y capturas válidas en una sola fila por pedido.
- Restar los reembolsos correctos vinculados, incluso si son posteriores al intervalo.
- Mantener los pedidos totalmente reembolsados con importe neto `0.00 EUR`.
- Excluir pedidos cancelados y pedidos cuyo canal no sea Online Store.
- Combinar de forma determinista métodos, pasarelas y tipos distintos.
- Convertir las fechas a `Europe/Madrid` y ordenar el resultado de forma estable.
- Mostrar un error seguro y accionable cuando faltan permisos para pedidos históricos.
- Rechazar métodos, tipos de contenido, tamaños y campos de petición no admitidos con códigos HTTP específicos.
- Reintentar hasta tres veces throttling y fallos transitorios, respetando `Retry-After` y el coste GraphQL disponible.
- Diferenciar de forma segura errores de configuración, autenticación, permisos, throttling e indisponibilidad.
- Generar un CSV de solo cabeceras cuando no existen coincidencias e informar del resultado al navegador.
- Registrar diagnósticos operativos sin secretos, variables GraphQL, datos personales o respuestas completas.
- Recuperar la interfaz tras fallos y avisar cuando una exportación tarda más de lo habitual.
- Cargar las cinco variables Shopify desde un `.env` ignorado, manteniendo prioridad para la configuración del proceso.

## 🏆 Beneficios

- Mantiene la herramienta pequeña y desplegable en cualquier servidor PHP convencional.
- Evita exponer tokens de Shopify al navegador.
- Permite probar la lógica de negocio sin acceder a tiendas reales.
- Separa el contrato de exportación de los detalles de transporte de Shopify.
- Facilita ampliar la exactitud contable sin cambiar el endpoint o las columnas del CSV.

## 👀 Ejemplos

### ✅ Correcto: coordinar la exportación mediante servicios separados

```php
$store = StoreRegistry::get($storeKey);
$range = DateRange::fromInput($from, $to);
$rows = iterator_to_array($service->generate($store, $range), false);
```

### ❌ Incorrecto: concentrar credenciales, GraphQL y CSV en la página HTML

```php
// No incluir tokens, llamadas cURL y escritura CSV directamente en index.php.
```

## 🧐 Ejemplos reales

- [`index.php`](../../index.php): Pantalla y formulario de exportación.
- [`export.php`](../../export.php): Adaptador HTTP público.
- [`src/php/EnvironmentLoader.php`](../../src/php/EnvironmentLoader.php): Carga permitida y no destructiva de `.env`.
- [`src/php/ExportController.php`](../../src/php/ExportController.php): Coordinación del caso de uso.
- [`src/php/PaymentReportService.php`](../../src/php/PaymentReportService.php): Paginación de pedidos.
- [`src/php/PaymentReportRowFactory.php`](../../src/php/PaymentReportRowFactory.php): Selección y transformación de pagos.
- [`src/php/CsvEncoder.php`](../../src/php/CsvEncoder.php): Contrato y codificación del CSV.

## 🔗 Acuerdos relacionados

- [Reglas contables del informe de pagos](../domain/payment-report-accounting.md)
- [Integración con Shopify Admin GraphQL](../integrations/shopify-admin-graphql.md)
- [Desarrollo local y verificación](../operations/local-development.md)
- [Despliegue protegido y seguridad operativa](../operations/protected-deployment.md)
- [Plan de implementación](../../.agents/plans/2026_09_18-shopify-payment-csv-export/2026_09_18-shopify-payment-csv-export-plan.md)

Architecture mapped by Turbotuga™ (🐢 💨), [Codely](https://codely.com)'s boundary-spotting mascot.
