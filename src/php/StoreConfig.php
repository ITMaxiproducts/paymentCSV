<?php

declare(strict_types=1);

namespace PaymentCsv;

use InvalidArgumentException;

final class StoreConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $domain,
        public readonly string $accessToken,
        public readonly string $apiVersion,
    ) {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $this->domain)) {
            throw new InvalidArgumentException('El dominio de la tienda Shopify no es válido.');
        }

        if ($this->accessToken === '') {
            throw new InvalidArgumentException('La configuración de la tienda Shopify está incompleta.');
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $this->apiVersion)) {
            throw new InvalidArgumentException('La versión de la API de Shopify no es válida.');
        }
    }

    public function graphqlUrl(): string
    {
        return sprintf(
            'https://%s/admin/api/%s/graphql.json',
            $this->domain,
            $this->apiVersion,
        );
    }
}
