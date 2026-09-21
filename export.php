<?php

declare(strict_types=1);

use PaymentCsv\CsvResponse;
use PaymentCsv\ExportController;
use PaymentCsv\ShopifyPermissionException;

require_once __DIR__ . '/src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respondWithError(405, 'Utiliza el formulario de exportación para generar un CSV.');
}

try {
    $result = (new ExportController())->export($_POST);
    CsvResponse::stream($result->rows, $result->filename);
} catch (InvalidArgumentException $exception) {
    respondWithError(422, $exception->getMessage());
} catch (ShopifyPermissionException $exception) {
    respondWithError(502, $exception->getMessage());
} catch (Throwable) {
    respondWithError(502, 'No se ha podido generar el CSV desde Shopify.');
}

function respondWithError(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
