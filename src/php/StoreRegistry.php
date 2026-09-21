<?php

declare(strict_types=1);

namespace PaymentCsv;

use InvalidArgumentException;

final class StoreRegistry
{
    private const STORES = [
        'ohyeah' => 'SHOPIFY_OHYEAH',
        'horeca' => 'SHOPIFY_HORECA',
    ];

    public static function get(string $storeKey): StoreConfig
    {
        $key = strtolower(trim($storeKey));
        $prefix = self::STORES[$key] ?? null;

        if ($prefix === null) {
            throw new InvalidArgumentException('Selecciona una tienda Shopify válida.');
        }

        $domain = self::environment($prefix . '_DOMAIN');
        $token = self::environment($prefix . '_ACCESS_TOKEN');
        $version = self::environment('SHOPIFY_API_VERSION');
        $domain = preg_replace('#^https?://#i', '', rtrim($domain, '/')) ?? $domain;

        return new StoreConfig($key, strtolower($domain), $token, $version);
    }

    private static function environment(string $name): string
    {
        $value = getenv($name);

        if ($value === false || trim($value) === '') {
            throw new InvalidArgumentException('La configuración de la tienda Shopify está incompleta.');
        }

        return trim($value);
    }
}
