# Changelog

Todos los cambios relevantes de Shopify Payment CSV se documentan en este archivo.

## [1.1.0] - 2026-09-24

Nueva selección de tipo de pago para generar informes más específicos sin modificar el formato estable del CSV.

### Añadido

- Selector exclusivo **TODOS**, **CARD** o **PAYPAL** en el formulario, con **TODOS** seleccionado por defecto.
- Contrato `payment_method=all|card|paypal` en `POST /export.php`, compatible con clientes anteriores que omiten el campo.
- Filtro local por método normalizado antes de construir cada fila del informe.

### Compatibilidad

- Sin cambios en la consulta GraphQL, los permisos de Shopify, las doce columnas del CSV ni el nombre del archivo descargado.
- Los pedidos continúan aplicando las reglas existentes de importes, estados y reembolsos.
- Una selección sin coincidencias devuelve el CSV válido de solo cabeceras.

### Verificación

- Suite ampliada a 26 pruebas automatizadas para cubrir TODOS, CARD, PAYPAL, compatibilidad y valores inválidos.
- Smoke test local de la pantalla y del contrato HTTP para los tres filtros.

## [1.0.0] - 2026-09-21

Primera versión estable preparada para despliegue en un servidor protegido.

### Añadido

- Interfaz en castellano para exportar una tienda OHYEAH o HORECA por solicitud.
- Intervalos inclusivos de hasta 92 días en la zona horaria `Europe/Madrid`.
- Integración con Shopify Admin GraphQL, paginación por cursor y acceso histórico.
- Configuración mediante `.env` con plantilla versionable y prioridad para variables del proceso.
- CSV UTF-8 descargable con doce columnas, fechas `YYYY-MM-DD` y horas `HH:mm` separadas.
- Reintentos acotados para throttling, timeouts y fallos transitorios de Shopify.
- Validación HTTP estricta, diagnósticos seguros y protección contra fórmulas de hojas de cálculo.

### Reglas del informe

- Incluye pagos de tarjeta y PayPal mediante transacciones `SALE` o `CAPTURE` satisfactorias.
- Suma múltiples cobros válidos y descuenta reembolsos vinculados, incluidos los posteriores al intervalo.
- Normaliza `PARTIALLY_REFUNDED` como `PAID` y conserva el importe neto efectivamente cobrado.
- Excluye pedidos `REFUNDED`, cancelados, de prueba, fallidos, pendientes o ajenos a Online Store.
- Ordena las filas cronológicamente y mantiene resultados deterministas.

### Verificación

- Suite sin dependencias con 24 pruebas automatizadas.
- Comprobación de sintaxis PHP y JavaScript mediante `scripts/verify.php`.
- Smoke tests locales y consultas reales validadas para ambas tiendas sin exponer secretos.

### Compatibilidad

- Requiere PHP 8.1 o superior y la extensión cURL.
- Requiere los permisos Shopify `read_orders` y `read_all_orders` para periodos históricos.
- El CSV estable de esta versión contiene doce columnas; consumidores de versiones preliminares deben admitir las nuevas columnas de hora.
