<?php

declare(strict_types=1);

namespace PaymentCsv;

use Closure;
use InvalidArgumentException;
use JsonException;

class ShopifyAdminClient
{
    /** @var list<int> */
    private const RETRYABLE_HTTP_STATUSES = [408, 429, 500, 502, 503, 504];

    public function __construct(
        private readonly ?Closure $transport = null,
        private readonly int $timeoutSeconds = 30,
        private readonly int $maxAttempts = 3,
        private readonly int $maxDelayMilliseconds = 5_000,
        private readonly ?Closure $sleeper = null,
        private readonly ?Closure $diagnostics = null,
    ) {
        if ($this->timeoutSeconds < 1 || $this->maxAttempts < 1 || $this->maxAttempts > 5) {
            throw new InvalidArgumentException('La configuración del cliente de Shopify no es válida.');
        }
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public function query(StoreConfig $store, string $query, array $variables): array
    {
        $payload = ['query' => $query, 'variables' => $variables];
        $operation = self::operationName($query);

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $response = $this->transport !== null
                    ? ($this->transport)($store, $payload)
                    : $this->send($store, $payload);
            } catch (ShopifyTransportException) {
                if ($attempt < $this->maxAttempts) {
                    $this->retry($store, $operation, $attempt, 'transporte');
                    continue;
                }

                $this->diagnose($store, $operation, $attempt, 'transporte_agotado');
                throw new ShopifyUnavailableException(
                    'Shopify no está disponible temporalmente. Inténtalo de nuevo más tarde.',
                );
            }

            if ($response instanceof ShopifyHttpResponse) {
                if (in_array($response->status, self::RETRYABLE_HTTP_STATUSES, true)) {
                    $category = $response->status === 429 ? 'throttling_http' : 'http_transitorio';

                    if ($attempt < $this->maxAttempts) {
                        $this->retry($store, $operation, $attempt, $category, $response);
                        continue;
                    }

                    $this->diagnose($store, $operation, $attempt, $category . '_agotado', $response->status);

                    if ($response->status === 429) {
                        throw new ShopifyThrottleException(
                            'Shopify está limitando temporalmente las solicitudes. Inténtalo de nuevo en unos minutos.',
                        );
                    }

                    throw new ShopifyUnavailableException(
                        'Shopify no está disponible temporalmente. Inténtalo de nuevo más tarde.',
                    );
                }

                if (in_array($response->status, [401, 403], true)) {
                    $this->diagnose($store, $operation, $attempt, 'autenticacion', $response->status);
                    throw new ShopifyAuthenticationException(
                        'Shopify ha rechazado la autenticación de la tienda. Revisa las credenciales y los permisos configurados.',
                    );
                }

                if ($response->status < 200 || $response->status >= 300) {
                    $this->diagnose($store, $operation, $attempt, 'http_no_reintentable', $response->status);
                    throw new ShopifyUnavailableException(
                        'Shopify no ha podido completar la solicitud. Inténtalo de nuevo más tarde.',
                    );
                }

                $decoded = $this->decode($response->body, $store, $operation, $attempt);
            } elseif (is_array($response)) {
                $decoded = $response;
            } else {
                $this->diagnose($store, $operation, $attempt, 'respuesta_invalida');
                throw new ShopifyUnavailableException(
                    'Shopify ha devuelto una respuesta no válida. Inténtalo de nuevo más tarde.',
                );
            }

            if (!empty($decoded['errors'])) {
                if ($this->isOrdersPermissionError($decoded['errors'])) {
                    $this->diagnose($store, $operation, $attempt, 'permisos');
                    throw new ShopifyPermissionException(
                        'Shopify no permite consultar todo el periodo. Comprueba que la aplicación tenga los permisos read_orders y read_all_orders.',
                    );
                }

                if ($this->isAuthenticationError($decoded['errors'])) {
                    $this->diagnose($store, $operation, $attempt, 'autenticacion_graphql');
                    throw new ShopifyAuthenticationException(
                        'Shopify ha rechazado la autenticación de la tienda. Revisa las credenciales y los permisos configurados.',
                    );
                }

                if ($this->isThrottleError($decoded['errors'])) {
                    if ($attempt < $this->maxAttempts) {
                        $this->retry($store, $operation, $attempt, 'throttling_graphql', null, $decoded);
                        continue;
                    }

                    $this->diagnose($store, $operation, $attempt, 'throttling_graphql_agotado');
                    throw new ShopifyThrottleException(
                        'Shopify está limitando temporalmente las solicitudes. Inténtalo de nuevo en unos minutos.',
                    );
                }

                $this->diagnose($store, $operation, $attempt, 'graphql');
                throw new ShopifyUnavailableException(
                    'Shopify no ha podido completar la solicitud. Inténtalo de nuevo más tarde.',
                );
            }

            $data = $decoded['data'] ?? null;

            if (!is_array($data)) {
                $this->diagnose($store, $operation, $attempt, 'respuesta_incompleta');
                throw new ShopifyUnavailableException(
                    'Shopify ha devuelto una respuesta incompleta. Inténtalo de nuevo más tarde.',
                );
            }

            return $data;
        }

        throw new ShopifyUnavailableException(
            'Shopify no está disponible temporalmente. Inténtalo de nuevo más tarde.',
        );
    }

    /** @return array<string, mixed> */
    private function decode(string $body, StoreConfig $store, string $operation, int $attempt): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->diagnose($store, $operation, $attempt, 'json_invalido');
            throw new ShopifyUnavailableException(
                'Shopify ha devuelto una respuesta no válida. Inténtalo de nuevo más tarde.',
            );
        }

        if (!is_array($decoded)) {
            $this->diagnose($store, $operation, $attempt, 'respuesta_invalida');
            throw new ShopifyUnavailableException(
                'Shopify ha devuelto una respuesta no válida. Inténtalo de nuevo más tarde.',
            );
        }

        return $decoded;
    }

    /** @param array<string, mixed>|null $decoded */
    private function retry(
        StoreConfig $store,
        string $operation,
        int $attempt,
        string $category,
        ?ShopifyHttpResponse $response = null,
        ?array $decoded = null,
    ): void {
        $delay = $this->retryDelayMilliseconds($attempt, $response, $decoded);
        $this->diagnose($store, $operation, $attempt, $category, $response?->status, $delay);

        if ($this->sleeper !== null) {
            ($this->sleeper)($delay);
            return;
        }

        usleep($delay * 1_000);
    }

    /** @param array<string, mixed>|null $decoded */
    private function retryDelayMilliseconds(
        int $attempt,
        ?ShopifyHttpResponse $response,
        ?array $decoded,
    ): int {
        $retryAfter = $response?->header('retry-after');

        if ($retryAfter !== null && is_numeric($retryAfter)) {
            return $this->boundedDelay((int) ceil((float) $retryAfter * 1_000));
        }

        $cost = $decoded['extensions']['cost'] ?? null;
        $throttle = is_array($cost) ? ($cost['throttleStatus'] ?? null) : null;

        if (is_array($cost) && is_array($throttle)) {
            $requested = (float) ($cost['requestedQueryCost'] ?? 0);
            $available = (float) ($throttle['currentlyAvailable'] ?? 0);
            $restoreRate = (float) ($throttle['restoreRate'] ?? 0);

            if ($restoreRate > 0) {
                $shortfall = max(1.0, $requested - $available);

                return $this->boundedDelay((int) ceil(($shortfall / $restoreRate) * 1_000));
            }
        }

        return $this->boundedDelay(250 * (2 ** ($attempt - 1)));
    }

    private function boundedDelay(int $milliseconds): int
    {
        return max(250, min($this->maxDelayMilliseconds, $milliseconds));
    }

    private function diagnose(
        StoreConfig $store,
        string $operation,
        int $attempt,
        string $category,
        ?int $status = null,
        ?int $delay = null,
    ): void {
        $context = [
            'store' => $store->key,
            'operation' => $operation,
            'category' => $category,
            'attempt' => $attempt,
            'status' => $status,
            'delay_ms' => $delay,
        ];

        if ($this->diagnostics !== null) {
            ($this->diagnostics)('shopify_request', $context);
            return;
        }

        SafeDiagnostics::record('shopify_request', $context);
    }

    private function isOrdersPermissionError(mixed $errors): bool
    {
        if (!is_array($errors)) {
            return false;
        }

        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $code = strtoupper((string) ($error['extensions']['code'] ?? ''));
            $message = strtolower((string) ($error['message'] ?? ''));

            if (
                $code === 'ACCESS_DENIED'
                || str_contains($message, 'read_all_orders')
                || str_contains($message, 'access denied')
                || str_contains($message, 'access scope')
            ) {
                return true;
            }
        }

        return false;
    }

    private function isAuthenticationError(mixed $errors): bool
    {
        if (!is_array($errors)) {
            return false;
        }

        foreach ($errors as $error) {
            $code = is_array($error) ? strtoupper((string) ($error['extensions']['code'] ?? '')) : '';

            if (in_array($code, ['UNAUTHENTICATED', 'AUTHENTICATION_ERROR'], true)) {
                return true;
            }
        }

        return false;
    }

    private function isThrottleError(mixed $errors): bool
    {
        if (!is_array($errors)) {
            return false;
        }

        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $code = strtoupper((string) ($error['extensions']['code'] ?? ''));
            $message = strtolower((string) ($error['message'] ?? ''));

            if ($code === 'THROTTLED' || str_contains($message, 'throttl')) {
                return true;
            }
        }

        return false;
    }

    private static function operationName(string $query): string
    {
        if (preg_match('/\b(?:query|mutation)\s+([A-Za-z_][A-Za-z0-9_]*)/', $query, $matches) === 1) {
            return $matches[1];
        }

        return 'AnonymousOperation';
    }

    /** @param array<string, mixed> $payload */
    private function send(StoreConfig $store, array $payload): ShopifyHttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new ShopifyTransportException('La extensión cURL de PHP es obligatoria.');
        }

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ShopifyTransportException('No se ha podido preparar la petición a Shopify.', 0, $exception);
        }

        $handle = curl_init($store->graphqlUrl());

        if ($handle === false) {
            throw new ShopifyTransportException('No se ha podido iniciar la petición a Shopify.');
        }

        $headers = [];
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Shopify-Access-Token: ' . $store->accessToken,
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HEADERFUNCTION => static function (mixed $curl, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($response === false) {
            throw new ShopifyTransportException('No se ha podido conectar con Shopify.');
        }

        return new ShopifyHttpResponse($status, $response, $headers);
    }
}
