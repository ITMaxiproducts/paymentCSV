<?php

declare(strict_types=1);

namespace PaymentCsv;

final class ExportResult
{
    /**
     * @param list<PaymentReportRow> $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly string $filename,
    ) {
    }
}
