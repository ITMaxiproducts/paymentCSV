<?php

declare(strict_types=1);

namespace PaymentCsv;

final class ExportRequestValidator
{
    public const MAX_BODY_BYTES = 16_384;

    /**
     * @var list<string>
     */
    private const FIELDS = ['shop', 'date_from', 'date_to', 'payment_method'];

    /**
     * @var list<string>
     */
    private const REQUIRED_FIELDS = ['shop', 'date_from', 'date_to'];

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $input
     * @param array<string, mixed> $files
     * @return array<string, string>
     */
    public static function validate(array $server, array $input, array $files = []): array
    {
        if (($server['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new RequestValidationException(
                405,
                'Utiliza el formulario de exportación para generar un CSV.',
            );
        }

        $contentLength = $server['CONTENT_LENGTH'] ?? null;

        if (
            $contentLength !== null
            && (!is_scalar($contentLength) || !ctype_digit((string) $contentLength))
        ) {
            throw new RequestValidationException(400, 'La petición de exportación no es válida.');
        }

        if ($contentLength !== null && (int) $contentLength > self::MAX_BODY_BYTES) {
            throw new RequestValidationException(413, 'La petición de exportación es demasiado grande.');
        }

        $contentType = strtolower(trim((string) ($server['CONTENT_TYPE'] ?? '')));

        if (!str_starts_with($contentType, 'multipart/form-data;')) {
            throw new RequestValidationException(
                415,
                'Envía la exportación como un formulario multipart/form-data.',
            );
        }

        if ($files !== [] || array_diff(array_keys($input), self::FIELDS) !== []) {
            throw new RequestValidationException(422, 'La petición de exportación contiene campos no admitidos.');
        }

        $validated = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            $value = $input[$field] ?? null;

            if (!is_string($value) || strlen($value) > 32) {
                throw new RequestValidationException(422, 'La petición de exportación no es válida.');
            }

            $validated[$field] = $value;
        }

        $hasPaymentMethod = array_key_exists('payment_method', $input);
        $paymentMethod = $hasPaymentMethod ? $input['payment_method'] : null;

        if ($hasPaymentMethod && (!is_string($paymentMethod) || strlen($paymentMethod) > 32)) {
            throw new RequestValidationException(422, 'La petición de exportación no es válida.');
        }

        try {
            $validated['payment_method'] = PaymentMethodFilter::fromInput($paymentMethod)->value();
        } catch (\InvalidArgumentException $exception) {
            throw new RequestValidationException(422, $exception->getMessage());
        }

        return $validated;
    }
}
