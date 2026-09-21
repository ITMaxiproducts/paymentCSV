<?php

declare(strict_types=1);

namespace PaymentCsv;

final class EnvironmentLoader
{
    /** @var list<string> */
    private const ALLOWED_KEYS = [
        'SHOPIFY_OHYEAH_DOMAIN',
        'SHOPIFY_OHYEAH_ACCESS_TOKEN',
        'SHOPIFY_HORECA_DOMAIN',
        'SHOPIFY_HORECA_ACCESS_TOKEN',
        'SHOPIFY_API_VERSION',
    ];

    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $size = filesize($path);

        if ($size === false || $size > 65_536) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $index => $line) {
            if ($index === 0) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
            }

            $assignment = self::parseAssignment($line);

            if ($assignment === null) {
                continue;
            }

            [$name, $value] = $assignment;

            if (!in_array($name, self::ALLOWED_KEYS, true) || getenv($name) !== false) {
                continue;
            }

            putenv($name . '=' . $value);
        }
    }

    /** @return array{string, string}|null */
    private static function parseAssignment(string $line): ?array
    {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        if (str_starts_with($line, 'export ')) {
            $line = ltrim(substr($line, 7));
        }

        if (preg_match('/^([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $matches) !== 1) {
            return null;
        }

        $value = self::parseValue($matches[2]);

        return $value === null ? null : [$matches[1], $value];
    }

    private static function parseValue(string $value): ?string
    {
        if ($value === '') {
            return '';
        }

        $quote = $value[0];

        if ($quote === "'" || $quote === '"') {
            if (strlen($value) < 2 || !str_ends_with($value, $quote)) {
                return null;
            }

            $value = substr($value, 1, -1);

            if ($quote === '"') {
                $value = str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
            }
        } else {
            $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
            $value = rtrim($value);
        }

        return str_contains($value, "\0") ? null : $value;
    }
}
