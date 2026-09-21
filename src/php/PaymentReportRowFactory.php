<?php

declare(strict_types=1);

namespace PaymentCsv;

final class PaymentReportRowFactory
{
    public function __construct(
        private readonly PaymentMethodNormalizer $methodNormalizer = new PaymentMethodNormalizer(),
    ) {
    }

    /**
     * @param array<string, mixed> $order
     */
    public function fromOrder(array $order, DateRange $range): ?PaymentReportRow
    {
        $transactions = $order['transactions'] ?? [];

        if (!is_array($transactions)) {
            return null;
        }

        usort($transactions, static function (mixed $left, mixed $right): int {
            if (!is_array($left) || !is_array($right)) {
                return 0;
            }

            return strcmp((string) ($left['processedAt'] ?? ''), (string) ($right['processedAt'] ?? ''));
        });

        foreach ($transactions as $transaction) {
            if (!is_array($transaction) || !$this->isQualifyingTransaction($transaction, $range)) {
                continue;
            }

            $details = is_array($transaction['paymentDetails'] ?? null)
                ? $transaction['paymentDetails']
                : [];
            $method = $this->methodNormalizer->normalize(
                self::nullableString($details['paymentMethodName'] ?? null),
                self::nullableString($transaction['gateway'] ?? null),
                self::nullableString($transaction['formattedGateway'] ?? null),
            );

            if ($method === null) {
                continue;
            }

            $money = $transaction['amountSet']['shopMoney'] ?? null;

            if (!is_array($money) || ($money['currencyCode'] ?? null) !== 'EUR') {
                continue;
            }

            $processedAt = (string) $transaction['processedAt'];
            $createdAt = (string) ($order['createdAt'] ?? '');

            if ($createdAt === '') {
                continue;
            }

            return new PaymentReportRow(
                $range->formatInTimezone($createdAt),
                $range->formatInTimezone($processedAt),
                (string) ($order['name'] ?? ''),
                (string) ($order['displayFinancialStatus'] ?? ''),
                $method,
                (string) (($transaction['formattedGateway'] ?? null) ?: ($transaction['gateway'] ?? '')),
                (string) $transaction['kind'],
                (string) $transaction['status'],
                number_format((float) ($money['amount'] ?? 0), 2, '.', ''),
                'EUR',
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function isQualifyingTransaction(array $transaction, DateRange $range): bool
    {
        $processedAt = $transaction['processedAt'] ?? null;

        return ($transaction['test'] ?? true) === false
            && in_array($transaction['kind'] ?? null, ['SALE', 'CAPTURE'], true)
            && ($transaction['status'] ?? null) === 'SUCCESS'
            && is_string($processedAt)
            && $range->contains($processedAt);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
