# Shopify Payment CSV

Aplicación PHP sin dependencias de runtime que descarga un CSV de pagos de Shopify para las tiendas OHYEAH y HORECA. El informe usa una fila por pedido, filtra por la fecha de la transacción en `Europe/Madrid` y aplica las reglas de cobros y reembolsos documentadas en [`docs/domain/payment-report-accounting.md`](docs/domain/payment-report-accounting.md).

Versión estable actual: `v1.1.0`. Consulta las [notas de versión](CHANGELOG.md).

## Requisitos

- PHP 8.1 o superior.
- Extensión PHP cURL.
- Acceso saliente HTTPS a `*.myshopify.com`.
- Una aplicación de Shopify por tienda con los permisos `read_orders` y `read_all_orders`.
- Un servidor web que proteja esta ruta con el mecanismo de acceso de la organización.

La aplicación no incluye autenticación propia. La restricción de acceso, TLS, límites del servidor y rotación de credenciales son responsabilidad del entorno donde se despliega.

## Configuración

El proyecto incluye `.env.example`. Cópialo como `.env` y completa estas cinco variables:

```powershell
Copy-Item .env.example .env
```

```text
SHOPIFY_OHYEAH_DOMAIN=ohyeah.myshopify.com
SHOPIFY_OHYEAH_ACCESS_TOKEN=<token secreto>
SHOPIFY_HORECA_DOMAIN=horeca.myshopify.com
SHOPIFY_HORECA_ACCESS_TOKEN=<token secreto>
SHOPIFY_API_VERSION=2026-07
```

El cargador admite comentarios, valores entre comillas y el prefijo `export`. Solo carga las cinco claves aprobadas y no sobrescribe variables que Apache, PHP-FPM, Docker o el sistema ya hayan definido; la configuración del proceso tiene prioridad sobre `.env`.

`.env` está ignorado por Git y Apache lo bloquea mediante `.htaccess`. No guardes tokens en el repositorio, `.env.example`, JavaScript o registros. En servidores que no usen Apache debes aplicar una regla equivalente para impedir cualquier descarga de `.env`.

## Despliegue protegido

La convención completa de seguridad, respuestas, reintentos y diagnóstico está en [`docs/operations/protected-deployment.md`](docs/operations/protected-deployment.md).

1. Copia la aplicación a una ruta servida por PHP 8.1+ y habilita cURL.
2. Evita publicar `.git`, `.agents`, `tests`, `scripts` y `docs` desde el servidor web; el punto de entrada público solo necesita `index.php`, `export.php` y `src`.
3. Completa un `.env` protegido o inyecta las cinco variables desde la configuración del servicio PHP. Las variables del proceso prevalecen.
4. Confirma `read_orders` y `read_all_orders` en ambas aplicaciones de Shopify.
5. Activa HTTPS y la protección de acceso externa antes de habilitar la herramienta para usuarios.
6. Ejecuta la verificación y después una exportación de prueba con una tienda no productiva o un intervalo controlado.

## Uso

Abre `index.php`, elige la tienda, selecciona un intervalo inclusivo de hasta 92 días y escoge el tipo de pago: **TODOS**, **CARD** o **PAYPAL**. **TODOS** está seleccionado por defecto y mantiene el comportamiento original. Después, pulsa **Generar CSV**; el navegador descarga `pagos-shopify-{tienda}-{desde}-{hasta}.csv`.

El formulario envía `payment_method` con uno de los valores `all`, `card` o `paypal`. Por compatibilidad con clientes anteriores, el endpoint interpreta un campo ausente como `all`. El filtro se aplica localmente después de normalizar cada pedido como CARD o PAYPAL, por lo que no modifica la consulta GraphQL, los permisos de Shopify, las columnas del CSV ni el nombre del archivo.

Cuando no existen pedidos coincidentes se descarga un CSV válido que contiene solo las doce cabeceras y la interfaz lo indica expresamente. Durante limitaciones temporales o fallos 408/429/5xx, el cliente de Shopify realiza hasta tres intentos con esperas acotadas.

Las fechas y horas ocupan columnas separadas: `YYYY-MM-DD` para la fecha y `HH:mm` en formato de 24 horas para la hora. Ambos valores se convierten previamente a `Europe/Madrid`, sin incluir el desplazamiento `+01:00` o `+02:00` en el CSV.

Los pedidos `PARTIALLY_REFUNDED` se incluyen con el reembolso descontado y se normalizan como `PAID`, porque conservan un cobro neto positivo. Los pedidos con estado financiero `REFUNDED` se excluyen del informe.

## Verificación

En la instalación Windows usada por el proyecto:

```powershell
C:\xampp\php\php.exe scripts\verify.php
```

En cualquier entorno con PHP en `PATH`:

```shell
php scripts/verify.php
```

El comando revisa la sintaxis PHP, la sintaxis JavaScript cuando Node.js está disponible y toda la suite de fixtures sin acceder a Shopify.

## Smoke test local

Inicia el servidor integrado:

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8765 -t C:\public_html\tools\paymentCSV
```

Comprueba `http://127.0.0.1:8765/` y estos casos:

- `GET /export.php` devuelve `405` y `Allow: POST`.
- Un `POST` que no sea `multipart/form-data` devuelve `415`.
- Un formulario válido sin configuración devuelve `503` sin nombres de variables, tokens, rutas ni trazas.
- Con una tienda de prueba configurada, una exportación válida devuelve `200`, `text/csv`, el nombre estable y `X-Export-Row-Count`.
- Las opciones **TODOS**, **CARD** y **PAYPAL** descargan únicamente los pedidos esperados; una selección sin coincidencias devuelve un CSV solo con cabeceras.

Los fixtures automatizados cubren reintentos, errores, CSV vacío, escape, UTF-8 y protección frente a fórmulas. La autenticación real, los permisos, la conectividad saliente y la descarga contra Shopify solo pueden comprobarse en el entorno protegido con credenciales de prueba.

## Diagnóstico seguro

Los fallos de Shopify escriben una línea `[payment-csv]` mediante el registro de errores de PHP. La línea incluye únicamente el identificador de tienda, el nombre de la operación, la categoría, el intento, el estado HTTP y la espera aplicada. No incluye tokens, variables GraphQL, datos personales ni respuestas completas.

Consulta también:

- [`docs/architecture/application-overview.md`](docs/architecture/application-overview.md)
- [`docs/integrations/shopify-admin-graphql.md`](docs/integrations/shopify-admin-graphql.md)
- [`docs/operations/local-development.md`](docs/operations/local-development.md)
- [`docs/operations/protected-deployment.md`](docs/operations/protected-deployment.md)
