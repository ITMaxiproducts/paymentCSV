<?php

declare(strict_types=1);

namespace PaymentCsv;

use RuntimeException;

final class CsvEncoder
{
    /**
     * @var list<string>
     */
    public const HEADERS = [
        'Fecha del pedido',
        'Fecha de la transacción',
        'Referencia del pedido',
        'Estado del pago',
        'Método de pago',
        'Pasarela de pago',
        'Tipo de transacción',
        'Estado de la transacción',
        'Importe de la transacción',
        'Moneda',
    ];

    /**
     * @param iterable<PaymentReportRow> $rows
     */
    public static function encode(iterable $rows): string
    {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('No se ha podido crear el CSV.');
        }

        fputcsv($stream, self::HEADERS, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, $row->toCsvRow(), ',', '"', '');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if ($csv === false) {
            throw new RuntimeException('No se ha podido leer el CSV.');
        }

        return $csv;
    }
}
