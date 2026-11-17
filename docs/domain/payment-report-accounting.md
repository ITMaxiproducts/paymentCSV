# 🎯 Reglas contables del informe de pagos

## 💡 Convención

El informe produce como máximo una fila por pedido y calcula su importe neto a partir de las transacciones de Shopify. La selección se basa en la fecha de la transacción, no en la fecha de creación del pedido.

El intervalo y todas las fechas y horas del CSV usan `UTC`, para coincidir con el día del cobro en las liquidaciones de Shopify Payments. Shopify [registra los momentos de captura en UTC](https://help.shopify.com/en/manual/payments/shopify-payments/payouts/payout-timing). No se convierten a la hora local de Madrid ni se usa la fecha del ingreso bancario. Esta convención se aplica a ambas tiendas y a los tres filtros de pago, incluido PayPal.

### Pedidos admitidos

Un pedido solo puede generar una fila cuando:

- `cancelledAt` es `null`.
- `sourceName` es `web` (Online Store) o `shopify_draft_order` (pedido creado a partir de un borrador).
- `displayFinancialStatus` no es `REFUNDED`.
- Contiene al menos un pago válido dentro del intervalo seleccionado.

Cada pedido admitido pertenece a una sola categoría de pago normalizada, `card` o `paypal`; los pedidos con métodos mixtos quedan fuera del dominio soportado. El usuario puede solicitar `all`, `card` o `paypal`. `all` conserva el comportamiento original, mientras que los otros valores incluyen el pedido completo solo cuando su categoría coincide.

Los pedidos procedentes de borradores siguen las mismas reglas de cobros, fechas UTC, métodos, cancelaciones y reembolsos que los pedidos web. Un borrador sin un pedido con cobro válido no genera una fila. Los demás orígenes, incluido `pos`, quedan excluidos.

### Pagos válidos

Una transacción cuenta como pago cuando cumple todas estas condiciones:

- `test` es `false`.
- `kind` es `SALE` o `CAPTURE`.
- `status` es `SUCCESS`.
- `processedAt` pertenece al intervalo inclusivo solicitado, interpretado en `UTC`: desde las `00:00:00` del primer día hasta el instante anterior a las `00:00:00` del día siguiente al último.
- El método puede normalizarse como `card` o `paypal`.
- El método normalizado coincide con el filtro solicitado, salvo cuando el filtro es `all`.
- `amountSet.shopMoney.currencyCode` es `EUR`.

Las transacciones `AUTHORIZATION` no suman importe. En un flujo de autorización y captura, solo las capturas satisfactorias que cumplen las reglas anteriores forman parte del total.

El filtro se aplica en PHP después de normalizar el método. CARD incluye tarjetas y wallets basados en tarjeta reconocidos por `PaymentMethodNormalizer`; PAYPAL incluye transacciones normalizadas como PayPal. Shopify recibe la misma consulta para las tres opciones.

### Reembolsos e importe neto

El importe se calcula por céntimos para evitar errores de coma flotante:

```text
importe neto = máximo(0, suma de pagos válidos - suma de reembolsos vinculados)
```

Un reembolso se resta cuando:

- `test` es `false`.
- `kind` es `REFUND`.
- `status` es `SUCCESS`.
- Está denominado en EUR.
- Su `parentTransaction.id` coincide con el identificador de uno de los pagos válidos.

La fecha del reembolso no tiene que pertenecer al intervalo. Un reembolso posterior se resta para que el informe refleje el neto actual. Los pedidos cuyo estado financiero actual es `REFUNDED` se excluyen por completo del CSV; los `PARTIALLY_REFUNDED` permanecen con su importe neto y se presentan como `PAID`.

### Valores agregados

- `Fecha de la transacción` usa la fecha del primer pago válido en orden cronológico.
- `Hora del pedido` y `Hora de la transacción` usan formato de 24 horas `HH:mm`.
- `Estado del pago` usa el `displayFinancialStatus` actual del pedido, salvo `PARTIALLY_REFUNDED`, que se normaliza como `PAID` porque la fila representa el cobro neto conservado.
- `Estado de la transacción` es `SUCCESS` y no se confunde con el estado financiero del pedido.
- Métodos, pasarelas y tipos distintos se deduplican conservando el orden cronológico y se unen con ` + `.
- Los timestamps se expresan en `UTC`; las fechas se muestran como `YYYY-MM-DD` y las horas como `HH:mm` en columnas separadas, sin mostrar el offset. Se usa la misma zona para filtrar y representar los pagos.
- Las filas se ordenan por fecha del pedido ascendente y, como desempate, por referencia del pedido.

## 🏆 Beneficios

- Evita duplicar pedidos cuando existen varias capturas.
- Permite obtener todos los pedidos o solo la categoría CARD o PAYPAL sin alterar la contabilidad del pedido.
- Impide contar autorizaciones como dinero cobrado.
- Refleja reembolsos parciales y posteriores al periodo consultado sin conservar pedidos totalmente reembolsados.
- Mantiene separados el estado financiero del pedido y el estado técnico de las transacciones.
- Produce resultados deterministas y comparables entre exportaciones.
- Evita errores de precisión al realizar los cálculos monetarios por céntimos.

## 👀 Ejemplos

### ✅ Correcto: agregar capturas y restar el reembolso vinculado

```text
CAPTURE SUCCESS 40.00 EUR, id=payment-1, processedAt dentro del intervalo
CAPTURE SUCCESS 60.00 EUR, id=payment-2, processedAt dentro del intervalo
REFUND SUCCESS 25.00 EUR, parentTransaction.id=payment-2, processedAt posterior

Resultado: una fila con 75.00 EUR y la fecha de la primera captura.
```

### ✅ Correcto: conservar el día del cobro en Shopify Payments

```text
P86893: SALE SUCCESS 13.98 EUR, processedAt=2026-11-03T23:12:50Z
P86895: SALE SUCCESS 9.88 EUR, processedAt=2026-11-03T23:19:23Z

Informe del 3 de noviembre: incluye ambos pagos con horas 23:12 y 23:19.
Informe del 4 de noviembre: no vuelve a incluirlos.
```

### ✅ Correcto: excluir un pedido totalmente reembolsado

```text
SALE SUCCESS 20.00 EUR, id=payment-1
REFUND SUCCESS 25.00 EUR, parentTransaction.id=payment-1

Estado financiero actual: REFUNDED
Resultado: el pedido no genera ninguna fila.
```

### ❌ Incorrecto: elegir solo el primer cobro

```php
// Pierde capturas adicionales y no refleja el importe total del pedido.
$amount = $qualifyingTransactions[0]['amountSet']['shopMoney']['amount'];
```

### ❌ Incorrecto: limitar los reembolsos al intervalo solicitado

```php
// El informe dejaría de representar el neto actual si el reembolso ocurrió después.
if ($range->contains($refund['processedAt'])) {
    $total -= $refundAmount;
}
```

## 🧐 Ejemplos reales

- [`src/php/PaymentReportRowFactory.php`](../../src/php/PaymentReportRowFactory.php): Filtrado, agregación por céntimos, asociación de reembolsos y creación de la fila.
- [`src/php/PaymentMethodNormalizer.php`](../../src/php/PaymentMethodNormalizer.php): Normalización de tarjetas, wallets y PayPal.
- [`src/php/PaymentReportService.php`](../../src/php/PaymentReportService.php): Paginación y orden estable del resultado.
- [`src/php/DateRange.php`](../../src/php/DateRange.php): Intervalo inclusivo, búsqueda candidata y zona horaria.
- [`tests/fixtures/orders-accounting.json`](../../tests/fixtures/orders-accounting.json): Escenarios contables reproducibles.
- [`tests/run.php`](../../tests/run.php): Verificación de agregación, reembolsos, filtros y orden.

## 🔗 Acuerdos relacionados

- [Arquitectura de la aplicación](../architecture/application-overview.md)
- [Integración con Shopify Admin GraphQL](../integrations/shopify-admin-graphql.md)
- [Desarrollo local y verificación](../operations/local-development.md)
- [Plan de implementación](../../.agents/plans/2026_09_18-shopify-payment-csv-export/2026_09_18-shopify-payment-csv-export-plan.md)
