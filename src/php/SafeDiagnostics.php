<?php

declare(strict_types=1);

namespace PaymentCsv;

final class SafeDiagnostics
{
    /**
     * @param array<string, bool|int|string|null> $context
     */
    public static function record(string $event, array $context): void
    {
        $safe = [
            'event' => self::identifier($event),
            'store' => self::identifier((string) ($context['store'] ?? 'desconocida')),
            'operation' => self::identifier((string) ($context['operation'] ?? 'desconocida')),
            'category' => self::identifier((string) ($context['category'] ?? 'desconocida')),
        ];

        foreach (['attempt', 'status', 'delay_ms'] as $numericKey) {
            if (isset($context[$numericKey]) && is_int($context[$numericKey])) {
                $safe[$numericKey] = $context[$numericKey];
            }
        }

        $encoded = json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        error_log('[payment-csv] ' . ($encoded === false ? '{"event":"diagnostico_invalido"}' : $encoded));
    }

    private static function identifier(string $value): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $value) ?? '';

        return substr($normalized !== '' ? $normalized : 'desconocida', 0, 64);
    }
}
