<?php

declare(strict_types=1);

namespace PaymentCsv;

use Closure;
use JsonException;
use RuntimeException;

class ShopifyAdminClient
{
    public function __construct(
        private readonly ?Closure $transport = null,
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public function query(StoreConfig $store, string $query, array $variables): array
    {
        $payload = [
            'query' => $query,
            'variables' => $variables,
        ];

        if ($this->transport !== null) {
            $decoded = ($this->transport)($store, $payload);
        } else {
            $decoded = $this->send($store, $payload);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Shopify ha devuelto una respuesta no válida.');
        }

        if (!empty($decoded['errors'])) {
            if ($this->isOrdersPermissionError($decoded['errors'])) {
                throw new ShopifyPermissionException(
                    'Shopify no permite consultar todo el periodo. Comprueba que la aplicación tenga los permisos read_orders y read_all_orders.',
                );
            }

            throw new RuntimeException('Shopify ha devuelto un error de GraphQL.');
        }

        $data = $decoded['data'] ?? null;

        if (!is_array($data)) {
            throw new RuntimeException('Shopify ha devuelto una respuesta incompleta.');
        }

        return $data;
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

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function send(StoreConfig $store, array $payload): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('La extensión cURL de PHP es obligatoria.');
        }

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('No se ha podido codificar la petición a Shopify.', 0, $exception);
        }

        $handle = curl_init($store->graphqlUrl());

        if ($handle === false) {
            throw new RuntimeException('No se ha podido iniciar la petición a Shopify.');
        }

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
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($response === false) {
            unset($handle);
            throw new RuntimeException('No se ha podido conectar con Shopify.');
        }

        unset($handle);

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Shopify ha rechazado la petición.');
        }

        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Shopify ha devuelto un JSON no válido.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Shopify ha devuelto una respuesta no válida.');
        }

        return $decoded;
    }
}
