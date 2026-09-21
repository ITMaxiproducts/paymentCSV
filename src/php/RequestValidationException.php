<?php

declare(strict_types=1);

namespace PaymentCsv;

use RuntimeException;

final class RequestValidationException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }
}
