<?php

declare(strict_types=1);

namespace PaymentCsv;

final class StoreConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $domain,
        public readonly string $accessToken,
        public readonly string $apiVersion,
    ) {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $this->domain)) {
            throw new StoreConfigurationException('La configuración de la tienda Shopify no es válida.');
        }

        if ($this->accessToken === '') {
            throw new StoreConfigurationException('La tienda seleccionada no está configurada en el servidor.');
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $this->apiVersion)) {
            throw new StoreConfigurationException('La configuración de la tienda Shopify no es válida.');
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
