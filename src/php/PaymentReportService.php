<?php

declare(strict_types=1);

namespace PaymentCsv;

use RuntimeException;

final class PaymentReportService
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly ShopifyAdminClient $client,
        private readonly PaymentReportRowFactory $rowFactory = new PaymentReportRowFactory(),
    ) {
    }

    /**
     * @return iterable<PaymentReportRow>
     */
    public function generate(StoreConfig $store, DateRange $range, PaymentMethodFilter $filter): iterable
    {
        $cursor = null;
        $rows = [];

        do {
            $data = $this->client->query($store, PaymentReportOrders::query(), [
                'first' => self::PAGE_SIZE,
                'after' => $cursor,
                'query' => $range->candidateSearchQuery(),
            ]);
            $orders = $data['orders'] ?? null;

            if (!is_array($orders)) {
                throw new RuntimeException('Shopify ha devuelto una lista de pedidos incompleta.');
            }

            $nodes = $orders['nodes'] ?? [];

            if (!is_array($nodes)) {
                throw new RuntimeException('Shopify ha devuelto una lista de pedidos no válida.');
            }

            foreach ($nodes as $order) {
                if (!is_array($order)) {
                    continue;
                }

                $row = $this->rowFactory->fromOrder($order, $range, $filter);

                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            $pageInfo = is_array($orders['pageInfo'] ?? null) ? $orders['pageInfo'] : [];
            $hasNextPage = ($pageInfo['hasNextPage'] ?? false) === true;
            $nextCursor = $pageInfo['endCursor'] ?? null;

            if ($hasNextPage && (!is_string($nextCursor) || $nextCursor === '' || $nextCursor === $cursor)) {
                throw new RuntimeException('Shopify ha devuelto un cursor de paginación no válido.');
            }

            $cursor = $hasNextPage ? $nextCursor : null;
        } while ($cursor !== null);

        usort($rows, static function (PaymentReportRow $left, PaymentReportRow $right): int {
            return [$left->orderDate, $left->orderTime, $left->orderName]
                <=> [$right->orderDate, $right->orderTime, $right->orderName];
        });

        foreach ($rows as $row) {
            yield $row;
        }
    }
}
