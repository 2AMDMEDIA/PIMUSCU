<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Router;

$rootPath = dirname(__DIR__);

require $rootPath . '/src/Bootstrap.php';

try {
    Bootstrap::boot($rootPath);

    $routes = require $rootPath . '/config/routes.php';
    $router = new Router($routes);

    $router->dispatch(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        $_SERVER['REQUEST_URI'] ?? '/'
    );
} catch (Throwable $e) {
    http_response_code(500);
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());

    $debug = filter_var($_ENV['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

    // Détection AJAX : Accept header contient application/json
    // OU X-Requested-With (jQuery) OU Content-Type JSON sur la requête entrante.
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isAjax = str_contains($accept, 'application/json')
        || strcasecmp($requestedWith, 'XMLHttpRequest') === 0
        || str_contains($contentType, 'application/json');

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => $debug
                ? $e->getMessage()
                : 'Erreur serveur (500). Voir storage/logs/php-errors.log sur le serveur.',
            'trace' => $debug ? $e->getTraceAsString() : null,
        ]);
        exit;
    }

    if ($debug) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Erreur 500\n\n";
        echo $e->getMessage() . "\n\n";
        echo $e->getTraceAsString();
    } else {
        echo '<h1>500 — Erreur serveur</h1><p>Une erreur inattendue est survenue.</p>';
    }
}
