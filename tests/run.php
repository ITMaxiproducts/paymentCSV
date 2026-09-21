<?php

declare(strict_types=1);

use PaymentCsv\CsvEncoder;
use PaymentCsv\DateRange;
use PaymentCsv\EnvironmentLoader;
use PaymentCsv\ExportController;
use PaymentCsv\ExportRequestValidator;
use PaymentCsv\PaymentMethodNormalizer;
use PaymentCsv\PaymentReportOrders;
use PaymentCsv\PaymentReportRow;
use PaymentCsv\PaymentReportService;
use PaymentCsv\RequestValidationException;
use PaymentCsv\ShopifyAuthenticationException;
use PaymentCsv\ShopifyAdminClient;
use PaymentCsv\ShopifyHttpResponse;
use PaymentCsv\ShopifyPermissionException;
use PaymentCsv\ShopifyThrottleException;
use PaymentCsv\ShopifyTransportException;
use PaymentCsv\ShopifyUnavailableException;
use PaymentCsv\StoreConfig;
use PaymentCsv\StoreConfigurationException;
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

function assertNotContainsText(string $needle, string $haystack): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException(sprintf('Expected output not to contain %s.', $needle));
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

/**
 * @param array<string, mixed> $server
 * @param array<string, mixed> $input
 */
function assertRequestStatus(int $status, array $server, array $input, array $files = []): void
{
    try {
        ExportRequestValidator::validate($server, $input, $files);
    } catch (RequestValidationException $exception) {
        assertSameValue($status, $exception->status);
        return;
    }

    throw new RuntimeException(sprintf('Expected request status %d.', $status));
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

$runner->test('loads approved dotenv values without overriding server environment', static function (): void {
    $keys = [
        'SHOPIFY_OHYEAH_DOMAIN',
        'SHOPIFY_OHYEAH_ACCESS_TOKEN',
        'SHOPIFY_HORECA_DOMAIN',
        'SHOPIFY_HORECA_ACCESS_TOKEN',
        'SHOPIFY_API_VERSION',
        'PAYMENTCSV_UNKNOWN',
    ];
    $original = [];

    foreach ($keys as $key) {
        $original[$key] = getenv($key);
        putenv($key);
    }

    putenv('SHOPIFY_API_VERSION=2099-01');
    $path = tempnam(sys_get_temp_dir(), 'paymentcsv-env-');

    if ($path === false) {
        throw new RuntimeException('Could not create a temporary dotenv file.');
    }

    file_put_contents($path, implode("\n", [
        '# Configuración de prueba',
        'SHOPIFY_OHYEAH_DOMAIN=ohyeah-env.myshopify.com # comentario',
        'SHOPIFY_OHYEAH_ACCESS_TOKEN="token\\"quoted"',
        "export SHOPIFY_HORECA_DOMAIN='horeca-env.myshopify.com'",
        'SHOPIFY_HORECA_ACCESS_TOKEN=horeca-token',
        'SHOPIFY_API_VERSION=2026-07',
        'PAYMENTCSV_UNKNOWN=must-not-load',
        'MALFORMED_LINE',
    ]));

    try {
        EnvironmentLoader::load($path);
        assertSameValue('ohyeah-env.myshopify.com', getenv('SHOPIFY_OHYEAH_DOMAIN'));
        assertSameValue('token"quoted', getenv('SHOPIFY_OHYEAH_ACCESS_TOKEN'));
        assertSameValue('horeca-env.myshopify.com', getenv('SHOPIFY_HORECA_DOMAIN'));
        assertSameValue('horeca-token', getenv('SHOPIFY_HORECA_ACCESS_TOKEN'));
        assertSameValue('2099-01', getenv('SHOPIFY_API_VERSION'));
        assertSameValue(false, getenv('PAYMENTCSV_UNKNOWN'));

        putenv('SHOPIFY_OHYEAH_DOMAIN');
        file_put_contents($path, str_repeat('#', 65_537));
        EnvironmentLoader::load($path);
        assertSameValue(false, getenv('SHOPIFY_OHYEAH_DOMAIN'));
    } finally {
        unlink($path);

        foreach ($original as $key => $value) {
            $value === false ? putenv($key) : putenv($key . '=' . $value);
        }
    }
});

$runner->test('keeps dotenv secrets ignored and protected from Apache', static function (): void {
    $gitignore = file_get_contents(__DIR__ . '/../.gitignore');
    $example = file_get_contents(__DIR__ . '/../.env.example');
    $apache = file_get_contents(__DIR__ . '/../.htaccess');

    if ($gitignore === false || $example === false || $apache === false) {
        throw new RuntimeException('Could not inspect dotenv protection files.');
    }

    assertContainsText('/.env', $gitignore);
    assertContainsText('!/.env.example', $gitignore);
    assertContainsText('SHOPIFY_OHYEAH_ACCESS_TOKEN=', $example);
    assertNotContainsText('test-token', $example);
    assertContainsText('Require all denied', $apache);
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

$runner->test('validates HTTP method, multipart content type, request size and fields', static function (): void {
    $validServer = [
        'REQUEST_METHOD' => 'POST',
        'CONTENT_TYPE' => 'multipart/form-data; boundary=payment-csv',
        'CONTENT_LENGTH' => '256',
    ];
    $validInput = [
        'shop' => 'ohyeah',
        'date_from' => '2026-01-01',
        'date_to' => '2026-01-31',
    ];

    assertSameValue($validInput, ExportRequestValidator::validate($validServer, $validInput));
    assertRequestStatus(405, ['REQUEST_METHOD' => 'GET'], []);
    assertRequestStatus(415, [
        'REQUEST_METHOD' => 'POST',
        'CONTENT_TYPE' => 'application/json',
    ], $validInput);
    assertRequestStatus(413, [
        'REQUEST_METHOD' => 'POST',
        'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
        'CONTENT_LENGTH' => (string) (ExportRequestValidator::MAX_BODY_BYTES + 1),
    ], $validInput);
    assertRequestStatus(422, $validServer, $validInput + ['unexpected' => 'value']);
    assertRequestStatus(422, $validServer, [
        'shop' => ['ohyeah'],
        'date_from' => '2026-01-01',
        'date_to' => '2026-01-31',
    ]);
    assertRequestStatus(422, $validServer, $validInput, ['upload' => ['name' => 'secret.txt']]);
});

$runner->test('reports missing store configuration without naming environment variables', static function (): void {
    putenv('SHOPIFY_HORECA_ACCESS_TOKEN');

    try {
        StoreRegistry::get('horeca');
    } catch (StoreConfigurationException $exception) {
        assertContainsText('no está configurada', $exception->getMessage());
        assertNotContainsText('SHOPIFY_HORECA_ACCESS_TOKEN', $exception->getMessage());
        configureTestStore();
        return;
    }

    configureTestStore();
    throw new RuntimeException('Expected a StoreConfigurationException to be thrown.');
});

$runner->test('retries GraphQL throttling using Shopify throttle cost information', static function (): void {
    $attempts = 0;
    $delays = [];
    $diagnostics = [];
    $client = new ShopifyAdminClient(
        transport: static function () use (&$attempts): array {
            $attempts++;

            if ($attempts < 3) {
                return [
                    'errors' => [[
                        'message' => 'Throttled',
                        'extensions' => ['code' => 'THROTTLED'],
                    ]],
                    'extensions' => [
                        'cost' => [
                            'requestedQueryCost' => 100,
                            'throttleStatus' => [
                                'currentlyAvailable' => 0,
                                'restoreRate' => 50,
                            ],
                        ],
                    ],
                ];
            }

            return ['data' => ['shop' => ['name' => 'OHYEAH']]];
        },
        sleeper: static function (int $delay) use (&$delays): void {
            $delays[] = $delay;
        },
        diagnostics: static function (string $event, array $context) use (&$diagnostics): void {
            $diagnostics[] = [$event, $context];
        },
    );
    $store = new StoreConfig('ohyeah', 'ohyeah-test.myshopify.com', 'sensitive-token', '2026-07');
    $data = $client->query($store, 'query PaymentReportOrders { shop { name } }', []);

    assertSameValue(3, $attempts);
    assertSameValue([2000, 2000], $delays);
    assertSameValue('OHYEAH', $data['shop']['name']);
    assertSameValue('PaymentReportOrders', $diagnostics[0][1]['operation']);
    assertNotContainsText('sensitive-token', json_encode($diagnostics, JSON_THROW_ON_ERROR));
});

$runner->test('retries transient HTTP responses and honors Retry-After', static function (): void {
    $attempts = 0;
    $delays = [];
    $client = new ShopifyAdminClient(
        transport: static function () use (&$attempts): ShopifyHttpResponse {
            $attempts++;

            return $attempts === 1
                ? new ShopifyHttpResponse(503, 'temporary', ['retry-after' => '0.5'])
                : new ShopifyHttpResponse(200, '{"data":{"shop":{"name":"HORECA"}}}');
        },
        sleeper: static function (int $delay) use (&$delays): void {
            $delays[] = $delay;
        },
        diagnostics: static function (string $event, array $context): void {
        },
    );
    $store = new StoreConfig('horeca', 'horeca-test.myshopify.com', 'test-token', '2026-07');
    $data = $client->query($store, 'query PaymentReportOrders { shop { name } }', []);

    assertSameValue(2, $attempts);
    assertSameValue([500], $delays);
    assertSameValue('HORECA', $data['shop']['name']);
});

$runner->test('retries transport timeouts and then recovers', static function (): void {
    $attempts = 0;
    $delays = [];
    $client = new ShopifyAdminClient(
        transport: static function () use (&$attempts): array {
            $attempts++;

            if ($attempts === 1) {
                throw new ShopifyTransportException('timeout with sensitive-token');
            }

            return ['data' => ['shop' => []]];
        },
        maxAttempts: 2,
        sleeper: static function (int $delay) use (&$delays): void {
            $delays[] = $delay;
        },
        diagnostics: static function (string $event, array $context): void {
        },
    );
    $store = new StoreConfig('ohyeah', 'ohyeah-test.myshopify.com', 'sensitive-token', '2026-07');

    $client->query($store, 'query PaymentReportOrders { shop { name } }', []);
    assertSameValue(2, $attempts);
    assertSameValue([250], $delays);
});

$runner->test('maps authentication, exhausted throttling, malformed JSON and GraphQL failures safely', static function (): void {
    $store = new StoreConfig('ohyeah', 'ohyeah-test.myshopify.com', 'sensitive-token', '2026-07');
    $noopDiagnostics = static function (string $event, array $context): void {
    };

    $authenticationClient = new ShopifyAdminClient(
        transport: static fn (): ShopifyHttpResponse => new ShopifyHttpResponse(401, 'sensitive-token'),
        diagnostics: $noopDiagnostics,
    );
    assertThrows(
        static fn (): array => $authenticationClient->query($store, 'query PaymentReportOrders { shop { name } }', []),
        ShopifyAuthenticationException::class,
    );

    $throttleClient = new ShopifyAdminClient(
        transport: static fn (): ShopifyHttpResponse => new ShopifyHttpResponse(429, 'sensitive-token'),
        maxAttempts: 2,
        sleeper: static function (int $delay): void {
        },
        diagnostics: $noopDiagnostics,
    );
    assertThrows(
        static fn (): array => $throttleClient->query($store, 'query PaymentReportOrders { shop { name } }', []),
        ShopifyThrottleException::class,
    );

    $malformedClient = new ShopifyAdminClient(
        transport: static fn (): ShopifyHttpResponse => new ShopifyHttpResponse(200, '{not-json'),
        diagnostics: $noopDiagnostics,
    );
    assertThrows(
        static fn (): array => $malformedClient->query($store, 'query PaymentReportOrders { shop { name } }', []),
        ShopifyUnavailableException::class,
    );

    $graphqlClient = new ShopifyAdminClient(
        transport: static fn (): array => [
            'errors' => [['message' => 'Remote failure sensitive-token']],
        ],
        diagnostics: $noopDiagnostics,
    );

    try {
        $graphqlClient->query($store, 'query PaymentReportOrders { shop { name } }', []);
    } catch (ShopifyUnavailableException $exception) {
        assertNotContainsText('sensitive-token', $exception->getMessage());
        assertNotContainsText('Remote failure', $exception->getMessage());
        return;
    }

    throw new RuntimeException('Expected a ShopifyUnavailableException to be thrown.');
});

$runner->test('escapes CSV fields, neutralizes formulas and preserves UTF-8 with CRLF records', static function (): void {
    $row = new PaymentReportRow(
        '2026-01-01T10:00:00+01:00',
        '2026-01-01T10:05:00+01:00',
        '=HYPERLINK("https://example.invalid")',
        "PAGADO,\nRevisado",
        '+cmd',
        'Pasarela "España"',
        '@CAPTURE',
        '-SUCCESS',
        '10.00',
        'EUR',
    );
    $csv = CsvEncoder::encode([$row]);
    $stream = fopen('php://temp', 'w+b');

    if ($stream === false) {
        throw new RuntimeException('Could not create CSV test stream.');
    }

    fwrite($stream, $csv);
    rewind($stream);
    $headers = fgetcsv($stream, null, ',', '"', '');
    $values = fgetcsv($stream, null, ',', '"', '');
    fclose($stream);

    assertSameValue(CsvEncoder::HEADERS, $headers);
    assertSameValue("'=HYPERLINK(\"https://example.invalid\")", $values[2] ?? null);
    assertSameValue("PAGADO,\nRevisado", $values[3] ?? null);
    assertSameValue("'+cmd", $values[4] ?? null);
    assertSameValue('Pasarela "España"', $values[5] ?? null);
    assertSameValue("'@CAPTURE", $values[6] ?? null);
    assertSameValue("'-SUCCESS", $values[7] ?? null);
    assertContainsText("\r\n", $csv);
    assertContainsText('España', $csv);
});

$runner->test('creates a header-only CSV and exposes clear empty-report UI behavior', static function (): void {
    $client = new ShopifyAdminClient(static fn (): array => [
        'data' => [
            'orders' => [
                'nodes' => [],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ],
        ],
    ]);
    $store = new StoreConfig('ohyeah', 'ohyeah-test.myshopify.com', 'test-token', '2026-07');
    $range = DateRange::fromInput('2026-01-01', '2026-01-31');
    $rows = iterator_to_array((new PaymentReportService($client))->generate($store, $range), false);
    $csv = CsvEncoder::encode($rows);
    $javascript = file_get_contents(__DIR__ . '/../src/js/main.js');
    $response = file_get_contents(__DIR__ . '/../src/php/CsvResponse.php');

    if ($javascript === false || $response === false) {
        throw new RuntimeException('Could not inspect empty export behavior.');
    }

    assertSameValue([], $rows);
    assertSameValue(1, substr_count($csv, "\r\n"));
    assertSameValue(CsvEncoder::HEADERS, str_getcsv(rtrim($csv, "\r\n"), ',', '"', ''));
    assertContainsText("header('X-Export-Row-Count: '", $response);
    assertContainsText('No se encontraron pedidos coincidentes', $javascript);
});

$runner->test('keeps download and error responses stable and free of internal details', static function (): void {
    $endpoint = file_get_contents(__DIR__ . '/../export.php');
    $response = file_get_contents(__DIR__ . '/../src/php/CsvResponse.php');

    if ($endpoint === false || $response === false) {
        throw new RuntimeException('Could not inspect the HTTP response contract.');
    }

    assertContainsText("header('Content-Type: text/csv; charset=UTF-8')", $response);
    assertContainsText("header('Content-Disposition: attachment; filename=\"'", $response);
    assertContainsText("header('X-Content-Type-Options: nosniff')", $response);
    assertContainsText("respondWithError(500, 'No se ha podido generar el CSV.", $endpoint);
    assertNotContainsText('echo $exception', $endpoint);
    assertNotContainsText('getTrace', $endpoint);
});

$runner->finish();
