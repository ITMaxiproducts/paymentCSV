<?php

declare(strict_types=1);

namespace PaymentCsv;

use RuntimeException;

final class CsvEncoder
{
    /** @var list<int> */
    private const TEXT_COLUMN_INDEXES = [0, 1, 2, 3, 4, 5, 6, 7, 9];

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

        if (fputcsv($stream, self::HEADERS, ',', '"', '', "\r\n") === false) {
            fclose($stream);
            throw new RuntimeException('No se han podido escribir las cabeceras del CSV.');
        }

        foreach ($rows as $row) {
            $values = $row->toCsvRow();

            foreach (self::TEXT_COLUMN_INDEXES as $index) {
                $values[$index] = self::protectFormula($values[$index]);
            }

            if (fputcsv($stream, $values, ',', '"', '', "\r\n") === false) {
                fclose($stream);
                throw new RuntimeException('No se ha podido escribir una fila del CSV.');
            }
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if ($csv === false) {
            throw new RuntimeException('No se ha podido leer el CSV.');
        }

        return $csv;
    }

    private static function protectFormula(string $value): string
    {
        return preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1
            ? "'" . $value
            : $value;
    }
}
