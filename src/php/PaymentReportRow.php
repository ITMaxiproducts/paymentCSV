<?php

declare(strict_types=1);

namespace PaymentCsv;

final class PaymentReportRow
{
    public function __construct(
        public readonly string $orderDate,
        public readonly string $orderTime,
        public readonly string $transactionDate,
        public readonly string $transactionTime,
        public readonly string $orderName,
        public readonly string $paymentStatus,
        public readonly string $paymentMethod,
        public readonly string $gateway,
        public readonly string $kind,
        public readonly string $transactionStatus,
        public readonly string $amount,
        public readonly string $currency,
    ) {
    }

    /**
     * @return list<string>
     */
    public function toCsvRow(): array
    {
        return [
            $this->orderDate,
            $this->orderTime,
            $this->transactionDate,
            $this->transactionTime,
            $this->orderName,
            $this->paymentStatus,
            $this->paymentMethod,
            $this->gateway,
            $this->kind,
            $this->transactionStatus,
            $this->amount,
            $this->currency,
        ];
    }
}
