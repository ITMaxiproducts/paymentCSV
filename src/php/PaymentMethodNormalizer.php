<?php

declare(strict_types=1);

namespace PaymentCsv;

final class PaymentMethodNormalizer
{
    /**
     * @var list<string>
     */
    private const CARD_MARKERS = [
        'card',
        'credit',
        'debit',
        'visa',
        'mastercard',
        'master card',
        'american express',
        'amex',
        'maestro',
        'discover',
        'diners',
        'jcb',
        'shop pay',
        'shoppay',
        'apple pay',
        'google pay',
        'shopify payments',
        'shopify_payments',
        'stripe',
    ];

    public function normalize(
        ?string $paymentMethod,
        ?string $gateway,
        ?string $formattedGateway = null,
    ): ?string {
        $haystack = strtolower(trim(implode(' ', array_filter([
            $paymentMethod,
            $gateway,
            $formattedGateway,
        ], static fn (?string $value): bool => $value !== null && $value !== ''))));

        if ($haystack === '') {
            return null;
        }

        if (str_contains($haystack, 'paypal')) {
            return 'paypal';
        }

        foreach (self::CARD_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                return 'card';
            }
        }

        return null;
    }
}
