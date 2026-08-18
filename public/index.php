<?php

use App\Kernel;

// When this file is used as PHP's built-in-server router, let the server
// return real public files (compiled CSS/JS, images, fonts) directly. Without
// this guard, Symfony Runtime attempts to execute each asset as a PHP app.
if (PHP_SAPI === 'cli-server') {
    $publicDirectory = realpath(__DIR__);
    $requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $requestedFile = realpath(__DIR__.$requestPath);

    if ($publicDirectory !== false
        && $requestedFile !== false
        && str_starts_with($requestedFile, $publicDirectory.DIRECTORY_SEPARATOR)
        && is_file($requestedFile)) {
        return false;
    }
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
