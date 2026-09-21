<?php

declare(strict_types=1);

namespace PaymentCsv;

use RuntimeException;

final class PaymentReportOrders
{
    public static function query(): string
    {
        $query = file_get_contents(__DIR__ . '/../graphql/PaymentReportOrders.graphql');

        if ($query === false) {
            throw new RuntimeException('No se ha podido cargar la consulta de Shopify.');
        }

        return $query;
    }
}
