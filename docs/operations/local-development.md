# 🎯 Desarrollo local y verificación

## 💡 Convención

El proyecto se ejecuta directamente con PHP y no necesita instalar dependencias. Antes de probar una tienda real deben configurarse las variables de entorno en el proceso que inicia el servidor.

### Requisitos

- PHP 8.1 o superior.
- Extensión PHP cURL.
- Node.js opcional para comprobar la sintaxis de JavaScript.
- Tokens de Shopify con `read_orders` y, para históricos, `read_all_orders`.

### Variables de entorno

Ejemplo de una sesión de PowerShell local:

```powershell
$env:SHOPIFY_OHYEAH_DOMAIN = "ohyeah.myshopify.com"
$env:SHOPIFY_OHYEAH_ACCESS_TOKEN = "<token>"
$env:SHOPIFY_HORECA_DOMAIN = "horeca.myshopify.com"
$env:SHOPIFY_HORECA_ACCESS_TOKEN = "<token>"
$env:SHOPIFY_API_VERSION = "2026-07"
```

No añadir valores reales a archivos versionados.

### Servidor local

Desde la raíz del proyecto:

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8765 -t C:\public_html\tools\paymentCSV
```

Abrir:

```text
http://localhost:8765/
```

En un entorno donde `php` esté en `PATH` se puede usar:

```shell
php -S 127.0.0.1:8765 -t .
```

### Verificación

En Windows con la instalación actual:

```powershell
C:\xampp\php\php.exe scripts\verify.php
```

Comando portátil:

```shell
php scripts/verify.php
```

El verificador:

1. Ejecuta `php -l` sobre todos los archivos PHP fuera de `.agents`.
2. Ejecuta `node --check src/js/main.js` si Node.js está disponible.
3. Ejecuta la suite sin dependencias de `tests/run.php`.

### Despliegue

- Ejecutar con PHP 8.1+ y cURL sobre HTTPS.
- Configurar las cinco variables en el proceso del servidor o en su gestor de secretos.
- Conceder `read_orders` y `read_all_orders` a las aplicaciones de ambas tiendas.
- Proteger externamente la ruta antes de publicarla; la aplicación no implementa autenticación.
- Impedir que el servidor publique `.git`, `.agents`, `tests`, `scripts` y `docs`.
- Conservar el registro de errores de PHP en una ubicación no pública para recibir los diagnósticos seguros `[payment-csv]`.

La guía completa y la lista de smoke test están en [`README.md`](../../README.md).

### Pruebas

La suite cubre intervalos de fechas, límite de 92 días, tiendas permitidas, normalización de métodos, paginación, históricos, agregación de cobros, reembolsos parciales y totales, filtros de canal y estado, permisos históricos, orden estable, contrato CSV, interfaz y creación del resultado de exportación. Las respuestas de Shopify proceden de fixtures y no requieren red ni credenciales reales.

### Diagnóstico básico

- Error de configuración: comprobar que las cinco variables de entorno existen en el mismo proceso que ejecuta PHP.
- Error de dominio: utilizar exclusivamente el hostname `*.myshopify.com`, sin rutas adicionales.
- Error de cURL: habilitar la extensión en el `php.ini` utilizado por el servidor.
- Pedidos históricos ausentes: comprobar el permiso `read_all_orders` en ambas aplicaciones.
- El navegador no descarga: revisar la respuesta de `POST /export.php` en las herramientas de desarrollo.
- Respuesta `413`: comprobar el tamaño de la petición; el formulario admite como máximo 16 KiB.
- Respuesta `415`: enviar el formulario como `multipart/form-data`.
- Respuesta `503` por throttling: esperar y reintentar después de `Retry-After`; el servidor ya ha agotado sus reintentos acotados.
- CSV vacío: es un resultado válido con solo cabeceras; comprobar filtros, tienda y periodo.

## 🏆 Beneficios

- El proyecto puede ponerse en marcha sin Composer ni npm.
- El mismo comando comprueba sintaxis y comportamiento.
- Los fixtures hacen que las pruebas sean deterministas.
- Los secretos permanecen fuera del repositorio.
- Los errores de entorno se detectan antes de probar con usuarios.

## 👀 Ejemplos

### ✅ Correcto: verificar antes de probar manualmente

```powershell
C:\xampp\php\php.exe scripts\verify.php
C:\xampp\php\php.exe -S 127.0.0.1:8765 -t C:\public_html\tools\paymentCSV
```

### ❌ Incorrecto: guardar credenciales reales en el código

```php
// No incluir tokens reales en StoreRegistry, fixtures o archivos de configuración versionados.
$token = 'shpat_valor_real';
```

## 🧐 Ejemplos reales

- [`scripts/verify.php`](../../scripts/verify.php): Verificador único del proyecto.
- [`tests/run.php`](../../tests/run.php): Suite de pruebas sin dependencias.
- [`tests/fixtures`](../../tests/fixtures): Datos GraphQL locales.
- [`src/bootstrap.php`](../../src/bootstrap.php): Autocarga mínima y zona horaria.

## 🔗 Acuerdos relacionados

- [Reglas contables del informe de pagos](../domain/payment-report-accounting.md)
- [Arquitectura de la aplicación](../architecture/application-overview.md)
- [Integración con Shopify Admin GraphQL](../integrations/shopify-admin-graphql.md)
- [Plan de implementación](../../.agents/plans/2026_09_18-shopify-payment-csv-export/2026_09_18-shopify-payment-csv-export-plan.md)

Local setup cleared by Turbotuga™ (🐢 💨), [Codely](https://codely.com)'s dependency-light trail guide.
