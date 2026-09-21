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
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, max-age=0');
        echo CsvEncoder::encode($rows);
        exit;
    }
}
