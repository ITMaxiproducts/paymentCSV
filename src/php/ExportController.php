<?php

declare(strict_types=1);

namespace PaymentCsv;

use Closure;
use RuntimeException;

final class ExportController
{
    public function __construct(
        private readonly ?Closure $clientFactory = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function export(array $input): ExportResult
    {
        $storeKey = is_string($input['shop'] ?? null) ? $input['shop'] : '';
        $from = is_string($input['date_from'] ?? null) ? $input['date_from'] : '';
        $to = is_string($input['date_to'] ?? null) ? $input['date_to'] : '';
        $store = StoreRegistry::get($storeKey);
        $range = DateRange::fromInput($from, $to);
        $client = $this->clientFactory !== null
            ? ($this->clientFactory)($store)
            : new ShopifyAdminClient();

        if (!$client instanceof ShopifyAdminClient) {
            throw new RuntimeException('El cliente de Shopify no es válido.');
        }

        $service = new PaymentReportService($client);
        $rows = iterator_to_array($service->generate($store, $range), false);

        return new ExportResult(
            $rows,
            sprintf('pagos-shopify-%s-%s-%s.csv', $store->key, $range->fromInputValue(), $range->toInputValue()),
        );
    }
}
