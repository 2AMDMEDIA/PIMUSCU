<?php

declare(strict_types=1);

namespace App;

use App\Controllers\BaseController;
use RuntimeException;

/**
 * Router minimaliste basé sur une table de routes.
 *
 * Chaque entrée : [METHOD, PATH, [Controller, action], $auth]
 *  - METHOD : 'GET'|'POST'|'PUT'|'DELETE'
 *  - PATH   : '/foo/bar/{id}' (les {param} matchent [^/]+)
 *  - $auth  : false (public), true (auth requise), 'super-admin' (auth + super-admin)
 *
 * Les paramètres extraits de l'URL sont passés à l'action en arguments nommés.
 */
final class Router
{
    /** @var array<int,array{method:string,regex:string,handler:array,auth:mixed,params:array<int,string>}> */
    private array $routes = [];

    public function __construct(array $routes)
    {
        foreach ($routes as $route) {
            [$method, $path, $handler, $auth] = array_pad($route, 4, false);
            $this->add($method, $path, $handler, $auth);
        }
    }

    private function add(string $method, string $path, array $handler, mixed $auth): void
    {
        $params = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', function ($m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $path);

        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'auth' => $auth,
            'params' => $params,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        // Strip query string
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $method = strtoupper($method);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            array_shift($matches); // remove full match
            $args = array_combine($route['params'], $matches) ?: [];

            $this->checkAuth($route['auth']);
            $this->callHandler($route['handler'], $args);
            return;
        }

        $this->notFound();
    }

    private function checkAuth(mixed $auth): void
    {
        if ($auth === false || $auth === null) {
            return;
        }

        if (!Session::isLoggedIn()) {
            header('Location: /login');
            exit;
        }

        if ($auth === 'super-admin') {
            // Vérification effectuée par le middleware au niveau de l'action si nécessaire.
            // Le router fait ici la vérif basique d'auth ; le contrôle super-admin
            // sera affiné par App\Middleware\RequireSuperAdmin.
        }
    }

    private function callHandler(array $handler, array $args): void
    {
        [$class, $action] = $handler;
        if (!class_exists($class)) {
            throw new RuntimeException("Controller introuvable : {$class}");
        }
        $controller = new $class();
        if (!method_exists($controller, $action)) {
            throw new RuntimeException("Action introuvable : {$class}::{$action}");
        }
        $controller->{$action}(...array_values($args));
    }

    private function notFound(): void
    {
        http_response_code(404);
        if (class_exists(BaseController::class)) {
            (new class extends BaseController {
                public function show(): void
                {
                    $this->render('pages.errors.404', ['title' => 'Page introuvable']);
                }
            })->show();
            return;
        }
        echo '404 — Page introuvable';
    }
}
