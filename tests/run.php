<?php

declare(strict_types=1);

use PaymentCsv\CsvEncoder;
use PaymentCsv\DateRange;
use PaymentCsv\ExportController;
use PaymentCsv\PaymentMethodNormalizer;
use PaymentCsv\PaymentReportOrders;
use PaymentCsv\PaymentReportService;
use PaymentCsv\ShopifyAdminClient;
use PaymentCsv\ShopifyPermissionException;
use PaymentCsv\StoreConfig;
use PaymentCsv\StoreRegistry;

require_once __DIR__ . '/../src/bootstrap.php';

final class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;

    public function test(string $name, Closure $test): void
    {
        try {
            $test();
            $this->passed++;
            fwrite(STDOUT, "PASS {$name}\n");
        } catch (Throwable $exception) {
            $this->failed++;
            fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
        }
    }

    public function finish(): never
    {
        fwrite(STDOUT, sprintf("\n%d passed, %d failed\n", $this->passed, $this->failed));
        exit($this->failed === 0 ? 0 : 1);
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message : sprintf(
            'Expected %s, got %s.',
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function assertContainsText(string $needle, string $haystack): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(sprintf('Expected output to contain %s.', $needle));
    }
}

function assertThrows(Closure $callback, string $exceptionClass = Throwable::class): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if (!$exception instanceof $exceptionClass) {
            throw new RuntimeException(sprintf('Expected %s, got %s.', $exceptionClass, $exception::class));
        }

        return;
    }

    throw new RuntimeException(sprintf('Expected %s to be thrown.', $exceptionClass));
}

/**
 * @return array<string, mixed>
 */
function fixture(string $name): array
{
    $contents = file_get_contents(__DIR__ . '/fixtures/' . $name . '.json');

    if ($contents === false) {
        throw new RuntimeException('Fixture could not be loaded.');
    }

    $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($data)) {
        throw new RuntimeException('Fixture is invalid.');
    }

    return $data;
}

/**
 * @return array<string, mixed>
 */
function singlePageFixture(): array
{
    $data = fixture('orders-page-1');
    $data['data']['orders']['pageInfo'] = [
        'hasNextPage' => false,
        'endCursor' => null,
    ];

    return $data;
}

function configureTestStore(): void
{
    putenv('SHOPIFY_OHYEAH_DOMAIN=ohyeah-test.myshopify.com');
    putenv('SHOPIFY_OHYEAH_ACCESS_TOKEN=test-token');
    putenv('SHOPIFY_HORECA_DOMAIN=horeca-test.myshopify.com');
    putenv('SHOPIFY_HORECA_ACCESS_TOKEN=test-token');
    putenv('SHOPIFY_API_VERSION=2026-07');
}

$runner = new TestRunner();

$runner->test('accepts a 92-day inclusive range', static function (): void {
    $range = DateRange::fromInput('2026-01-01', '2026-04-02');
    assertSameValue('2026-01-01', $range->fromInputValue());
    assertSameValue('2026-04-02', $range->toInputValue());
});

$runner->test('rejects a range longer than 92 days', static function (): void {
    assertThrows(
        static fn (): DateRange => DateRange::fromInput('2026-01-01', '2026-04-03'),
        InvalidArgumentException::class,
    );
});

$runner->test('rejects reversed and invalid calendar dates', static function (): void {
    assertThrows(
        static fn (): DateRange => DateRange::fromInput('2026-02-02', '2026-02-01'),
        InvalidArgumentException::class,
    );
    assertThrows(
        static fn (): DateRange => DateRange::fromInput('2026-02-30', '2026-03-01'),
        InvalidArgumentException::class,
    );
});

$runner->test('rejects unknown store keys', static function (): void {
    assertThrows(
        static fn (): StoreConfig => StoreRegistry::get('unknown'),
        InvalidArgumentException::class,
    );
});

$runner->test('normalizes card wallets and PayPal', static function (): void {
    $normalizer = new PaymentMethodNormalizer();
    assertSameValue('card', $normalizer->normalize('visa', 'shopify_payments'));
    assertSameValue('card', $normalizer->normalize('Apple Pay', 'shopify_payments'));
    assertSameValue('paypal', $normalizer->normalize(null, 'paypal_express'));
    assertSameValue(null, $normalizer->normalize('bank transfer', 'manual'));
});

$runner->test('paginates Shopify orders and emits qualifying rows', static function (): void {
    $calls = [];
    $client = new ShopifyAdminClient(static function (StoreConfig $store, array $payload) use (&$calls): array {
        $calls[] = $payload['variables'];

        return ($payload['variables']['after'] ?? null) === null
            ? fixture('orders-page-1')
            : fixture('orders-page-2');
    });
    $store = new StoreConfig('ohyeah', 'ohyeah-test.myshopify.com', 'test-token', '2026-07');
    $range = DateRange::fromInput('2026-01-01', '2026-01-31');
    $rows = iterator_to_array((new PaymentReportService($client))->generate($store, $range), false);

    assertSameValue(2, count($calls));
    assertSameValue(null, $calls[0]['after']);
    assertSameValue('page-2', $calls[1]['after']);
    assertContainsText('created_at:<=2026-01-31T22:59:59Z', $calls[0]['query']);
    assertContainsText('updated_at:>=2025-12-31T23:00:00Z', $calls[0]['query']);
    assertContainsText('status:any', $calls[0]['query']);
    assertContainsText('sortKey: UPDATED_AT', PaymentReportOrders::query());
    assertSameValue(2, count($rows));
    assertSameValue('card', $rows[0]->paymentMethod);
    assertSameValue('paypal', $rows[1]->paymentMethod);
});

$runner->test('aggregates captures and subtracts later successful refunds', static function (): void {
    $client = new ShopifyAdminClient(static fn (): array => fixture('orders-accounting'));
    $store = new StoreConfig('ohyeah', 'ohyeah-test.myshopify.com', 'test-token', '2026-07');
    $range = DateRange::fromInput('2026-01-01', '2026-01-31');
    $rows = iterator_to_array((new PaymentReportService($client))->generate($store, $range), false);

    assertSameValue(['#2001', '#2002', '#2003'], array_map(
        static fn ($row): string => $row->orderName,
        $rows,
    ));
    assertSameValue('75.00', $rows[0]->amount);
    assertSameValue('card', $rows[0]->paymentMethod);
    assertSameValue('Shopify Payments', $rows[0]->gateway);
    assertSameValue('CAPTURE', $rows[0]->kind);
    assertSameValue('SUCCESS', $rows[0]->transactionStatus);
    assertSameValue('PARTIALLY_REFUNDED', $rows[0]->paymentStatus);
    assertSameValue('2025-09-01T23:30:00+02:00', $rows[0]->orderDate);
    assertSameValue('2026-01-10T10:00:00+01:00', $rows[0]->transactionDate);
});

$runner->test('retains full refunds at zero and combines distinct transaction values', static function (): void {
    $client = new ShopifyAdminClient(static fn (): array => fixture('orders-accounting'));
    $store = new StoreConfig('ohyeah', 'ohyeah-test.myshopify.com', 'test-token', '2026-07');
    $range = DateRange::fromInput('2026-01-01', '2026-01-31');
    $rows = iterator_to_array((new PaymentReportService($client))->generate($store, $range), false);

    assertSameValue('0.00', $rows[1]->amount);
    assertSameValue('REFUNDED', $rows[1]->paymentStatus);
    assertSameValue('15.00', $rows[2]->amount);
    assertSameValue('card', $rows[2]->paymentMethod);
    assertSameValue('Stripe + Shopify Payments', $rows[2]->gateway);
    assertSameValue('SALE + CAPTURE', $rows[2]->kind);
});

$runner->test('excludes cancelled, POS, test, failed, pending and unsupported payments', static function (): void {
    $client = new ShopifyAdminClient(static fn (): array => fixture('orders-accounting'));
    $store = new StoreConfig('ohyeah', 'ohyeah-test.myshopify.com', 'test-token', '2026-07');
    $range = DateRange::fromInput('2026-01-01', '2026-01-31');
    $rows = iterator_to_array((new PaymentReportService($client))->generate($store, $range), false);
    $names = array_map(static fn ($row): string => $row->orderName, $rows);

    assertSameValue(false, in_array('#3001', $names, true));
    assertSameValue(false, in_array('#3002', $names, true));
    assertSameValue(false, in_array('#3003', $names, true));
    assertSameValue(false, in_array('#3004', $names, true));
});

$runner->test('reports historical order permission failures safely', static function (): void {
    $client = new ShopifyAdminClient(static fn (): array => [
        'errors' => [[
            'message' => 'Access denied for orders field with sensitive-token.',
            'extensions' => ['code' => 'ACCESS_DENIED'],
        ]],
    ]);
    $store = new StoreConfig('ohyeah', 'ohyeah-test.myshopify.com', 'test-token', '2026-07');

    try {
        $client->query($store, PaymentReportOrders::query(), []);
    } catch (ShopifyPermissionException $exception) {
        assertContainsText('read_orders y read_all_orders', $exception->getMessage());
        assertSameValue(false, str_contains($exception->getMessage(), 'sensitive-token'));

        return;
    }

    throw new RuntimeException('Expected a ShopifyPermissionException to be thrown.');
});

$runner->test('writes the exact CSV schema and international amounts', static function (): void {
    $client = new ShopifyAdminClient(static fn (): array => singlePageFixture());
    $store = new StoreConfig('ohyeah', 'ohyeah-test.myshopify.com', 'test-token', '2026-07');
    $range = DateRange::fromInput('2026-01-01', '2026-01-31');
    $rows = iterator_to_array((new PaymentReportService($client))->generate($store, $range), false);
    $csv = CsvEncoder::encode($rows);
    $lines = preg_split('/\r\n|\n|\r/', trim($csv));

    assertSameValue(CsvEncoder::HEADERS, str_getcsv($lines[0] ?? '', ',', '"', ''));
    assertContainsText(',49.95,EUR', $lines[1] ?? '');
});

$runner->test('keeps the interface and CSV headers in Spanish', static function (): void {
    $html = file_get_contents(__DIR__ . '/../index.php');
    $javascript = file_get_contents(__DIR__ . '/../src/js/main.js');

    if ($html === false || $javascript === false) {
        throw new RuntimeException('No se han podido cargar los archivos de la interfaz.');
    }

    assertContainsText('<html lang="es">', $html);
    assertContainsText('Tienda Shopify', $html);
    assertContainsText('Generar CSV', $html);
    assertContainsText('Consultando los pedidos en Shopify...', $javascript);
    assertSameValue([
        'Fecha del pedido',
        'Fecha de la transacción',
        'Referencia del pedido',
        'Estado del pago',
        'Método de pago',
        'Pasarela de pago',
        'Tipo de transacción',
        'Estado de la transacción',
        'Importe de la transacción',
        'Moneda',
    ], CsvEncoder::HEADERS);
});

$runner->test('builds a successful export result with a deterministic filename', static function (): void {
    configureTestStore();
    $controller = new ExportController(static function (): ShopifyAdminClient {
        return new ShopifyAdminClient(static fn (): array => singlePageFixture());
    });
    $result = $controller->export([
        'shop' => 'ohyeah',
        'date_from' => '2026-01-01',
        'date_to' => '2026-01-31',
    ]);

    assertSameValue('pagos-shopify-ohyeah-2026-01-01-2026-01-31.csv', $result->filename);
    assertSameValue(1, count($result->rows));
});

$runner->finish();
