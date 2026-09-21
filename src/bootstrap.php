<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Madrid');

spl_autoload_register(static function (string $class): void {
    $prefix = 'PaymentCsv\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $path = __DIR__ . '/php/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});
