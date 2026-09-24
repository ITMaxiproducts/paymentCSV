<?php

declare(strict_types=1);

namespace PaymentCsv;

use InvalidArgumentException;

final class PaymentMethodFilter
{
    public const ALL = 'all';
    public const CARD = 'card';
    public const PAYPAL = 'paypal';

    /**
     * @var list<string>
     */
    private const ALLOWED_VALUES = [self::ALL, self::CARD, self::PAYPAL];

    private function __construct(
        private readonly string $value,
    ) {
    }

    public static function fromInput(?string $value): self
    {
        $value ??= self::ALL;

        if (!in_array($value, self::ALLOWED_VALUES, true)) {
            throw new InvalidArgumentException('Selecciona TODOS, CARD o PAYPAL como tipo de pago.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function accepts(?string $normalizedMethod): bool
    {
        if (!in_array($normalizedMethod, [self::CARD, self::PAYPAL], true)) {
            return false;
        }

        return $this->value === self::ALL || $this->value === $normalizedMethod;
    }
}
