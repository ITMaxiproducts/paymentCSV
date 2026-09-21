<?php

declare(strict_types=1);

namespace PaymentCsv;

final class CsvResponse
{
    /**
     * @param iterable<PaymentReportRow> $rows
     */
    public static function stream(iterable $rows, string $filename): never
    {
        $csv = CsvEncoder::encode($rows);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($csv));

        if (is_countable($rows)) {
            header('X-Export-Row-Count: ' . count($rows));
        }

        echo $csv;
        exit;
    }
}
