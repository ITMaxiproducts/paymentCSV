<?php

declare(strict_types=1);

use PaymentCsv\CsvResponse;
use PaymentCsv\ExportController;
use PaymentCsv\ExportRequestValidator;
use PaymentCsv\RequestValidationException;
use PaymentCsv\SafeDiagnostics;
use PaymentCsv\ShopifyAuthenticationException;
use PaymentCsv\ShopifyPermissionException;
use PaymentCsv\ShopifyThrottleException;
use PaymentCsv\ShopifyUnavailableException;
use PaymentCsv\StoreConfigurationException;

require_once __DIR__ . '/src/bootstrap.php';

try {
    $input = ExportRequestValidator::validate($_SERVER, $_POST, $_FILES);
    $result = (new ExportController())->export($input);
    CsvResponse::stream($result->rows, $result->filename);
} catch (RequestValidationException $exception) {
    $headers = $exception->status === 405 ? ['Allow' => 'POST'] : [];
    respondWithError($exception->status, $exception->getMessage(), $headers);
} catch (StoreConfigurationException $exception) {
    respondWithError(503, $exception->getMessage());
} catch (InvalidArgumentException $exception) {
    respondWithError(422, $exception->getMessage());
} catch (ShopifyAuthenticationException $exception) {
    respondWithError(502, $exception->getMessage());
} catch (ShopifyPermissionException $exception) {
    respondWithError(502, $exception->getMessage());
} catch (ShopifyThrottleException $exception) {
    respondWithError(503, $exception->getMessage(), ['Retry-After' => '60']);
} catch (ShopifyUnavailableException $exception) {
    respondWithError(502, $exception->getMessage());
} catch (Throwable) {
    SafeDiagnostics::record('exportacion_fallida', [
        'store' => is_string($_POST['shop'] ?? null) ? $_POST['shop'] : 'desconocida',
        'operation' => 'ExportPaymentCsv',
        'category' => 'interno',
    ]);
    respondWithError(500, 'No se ha podido generar el CSV. Inténtalo de nuevo más tarde.');
}

/** @param array<string, string> $headers */
function respondWithError(int $status, string $message, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');

    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }

    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
