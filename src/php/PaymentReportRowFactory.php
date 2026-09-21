<?php

declare(strict_types=1);

namespace PaymentCsv;

final class PaymentReportRowFactory
{
    private const COMBINED_VALUE_SEPARATOR = ' + ';

    public function __construct(
        private readonly PaymentMethodNormalizer $methodNormalizer = new PaymentMethodNormalizer(),
    ) {
    }

    /**
     * @param array<string, mixed> $order
     */
    public function fromOrder(array $order, DateRange $range): ?PaymentReportRow
    {
        $financialStatus = strtoupper(trim((string) ($order['displayFinancialStatus'] ?? '')));

        if (
            ($order['cancelledAt'] ?? null) !== null
            || strtolower((string) ($order['sourceName'] ?? '')) !== 'web'
            || $financialStatus === 'REFUNDED'
        ) {
            return null;
        }

        $transactions = $order['transactions'] ?? [];

        if (!is_array($transactions)) {
            return null;
        }

        usort($transactions, static function (mixed $left, mixed $right): int {
            if (!is_array($left) || !is_array($right)) {
                return 0;
            }

            return [
                (string) ($left['processedAt'] ?? ''),
                (string) ($left['id'] ?? ''),
            ] <=> [
                (string) ($right['processedAt'] ?? ''),
                (string) ($right['id'] ?? ''),
            ];
        });

        $qualifying = [];
        $qualifyingIds = [];

        foreach ($transactions as $transaction) {
            if (!is_array($transaction) || !$this->isQualifyingPayment($transaction, $range)) {
                continue;
            }

            $method = $this->normalizedMethod($transaction);
            $amount = $this->eurAmountInCents($transaction);
            $id = self::nullableString($transaction['id'] ?? null);

            if ($method === null || $amount === null || $id === null || $id === '') {
                continue;
            }

            $qualifying[] = [
                'transaction' => $transaction,
                'method' => $method,
                'amount' => $amount,
            ];
            $qualifyingIds[$id] = true;
        }

        if ($qualifying === []) {
            return null;
        }

        $refundTotal = 0;

        foreach ($transactions as $transaction) {
            if (!is_array($transaction) || !$this->isSuccessfulRefund($transaction)) {
                continue;
            }

            $parentId = self::nullableString($transaction['parentTransaction']['id'] ?? null);

            if ($parentId === null || !isset($qualifyingIds[$parentId])) {
                continue;
            }

            $refundAmount = $this->eurAmountInCents($transaction);

            if ($refundAmount !== null) {
                $refundTotal += $refundAmount;
            }
        }

        $paymentTotal = array_sum(array_column($qualifying, 'amount'));
        $netTotal = max(0, $paymentTotal - $refundTotal);
        $firstTransaction = $qualifying[0]['transaction'];
        $createdAt = self::nullableString($order['createdAt'] ?? null);
        $processedAt = self::nullableString($firstTransaction['processedAt'] ?? null);

        if ($createdAt === null || $createdAt === '' || $processedAt === null || $processedAt === '') {
            return null;
        }

        $methods = [];
        $gateways = [];
        $kinds = [];

        foreach ($qualifying as $payment) {
            $transaction = $payment['transaction'];
            self::appendDistinct($methods, $payment['method']);
            self::appendDistinct($gateways, (string) (
                ($transaction['formattedGateway'] ?? null)
                ?: ($transaction['gateway'] ?? '')
            ));
            self::appendDistinct($kinds, (string) ($transaction['kind'] ?? ''));
        }

        return new PaymentReportRow(
            $range->formatDateInTimezone($createdAt),
            $range->formatTimeInTimezone($createdAt),
            $range->formatDateInTimezone($processedAt),
            $range->formatTimeInTimezone($processedAt),
            (string) ($order['name'] ?? ''),
            self::normalizedPaymentStatus($financialStatus),
            implode(self::COMBINED_VALUE_SEPARATOR, $methods),
            implode(self::COMBINED_VALUE_SEPARATOR, $gateways),
            implode(self::COMBINED_VALUE_SEPARATOR, $kinds),
            'SUCCESS',
            number_format($netTotal / 100, 2, '.', ''),
            'EUR',
        );
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function isQualifyingPayment(array $transaction, DateRange $range): bool
    {
        $processedAt = $transaction['processedAt'] ?? null;

        return ($transaction['test'] ?? true) === false
            && in_array($transaction['kind'] ?? null, ['SALE', 'CAPTURE'], true)
            && ($transaction['status'] ?? null) === 'SUCCESS'
            && is_string($processedAt)
            && $range->contains($processedAt);
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function isSuccessfulRefund(array $transaction): bool
    {
        return ($transaction['test'] ?? true) === false
            && ($transaction['kind'] ?? null) === 'REFUND'
            && ($transaction['status'] ?? null) === 'SUCCESS';
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function normalizedMethod(array $transaction): ?string
    {
        $details = is_array($transaction['paymentDetails'] ?? null)
            ? $transaction['paymentDetails']
            : [];

        return $this->methodNormalizer->normalize(
            self::nullableString($details['paymentMethodName'] ?? null),
            self::nullableString($transaction['gateway'] ?? null),
            self::nullableString($transaction['formattedGateway'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function eurAmountInCents(array $transaction): ?int
    {
        $money = $transaction['amountSet']['shopMoney'] ?? null;

        if (!is_array($money) || ($money['currencyCode'] ?? null) !== 'EUR') {
            return null;
        }

        $amount = $money['amount'] ?? null;

        if (!is_string($amount) && !is_int($amount) && !is_float($amount)) {
            return null;
        }

        $amount = (string) $amount;

        if (!preg_match('/^(\d+)(?:\.(\d+))?$/', $amount, $matches)) {
            return null;
        }

        $fraction = str_pad($matches[2] ?? '', 3, '0');
        $cents = ((int) $matches[1] * 100) + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $cents++;
        }

        return $cents;
    }

    /**
     * @param list<string> $values
     */
    private static function appendDistinct(array &$values, string $value): void
    {
        if ($value !== '' && !in_array($value, $values, true)) {
            $values[] = $value;
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function normalizedPaymentStatus(string $financialStatus): string
    {
        return $financialStatus === 'PARTIALLY_REFUNDED' ? 'PAID' : $financialStatus;
    }
}
