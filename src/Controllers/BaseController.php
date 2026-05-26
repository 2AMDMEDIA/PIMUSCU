<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Helpers\Renderer;
use App\Services\ClientResolver;
use App\Session;

/**
 * Base de tous les contrôleurs : utilitaires render, redirect, JSON, input.
 */
abstract class BaseController
{
    /**
     * Rend un template et envoie la réponse.
     *
     * @param array<string,mixed> $data
     */
    protected function render(string $view, array $data = [], ?string $layout = null): void
    {
        $defaults = [
            'flashes' => Session::takeFlashes(),
            'csrf_token' => Csrf::token(),
            'is_logged_in' => Session::isLoggedIn(),
            'current_user_id' => Session::userId(),
        ];
        $merged = array_merge($defaults, $data);
        if ($layout !== null) {
            Renderer::outputWithLayout($layout, $view, $merged);
            return;
        }
        Renderer::output($view, $merged);
    }

    /**
     * Rend une page dans le layout principal (sidebar + topbar) en remplissant
     * automatiquement le contexte (user, client actif, etc.).
     *
     * @param array<string,mixed> $data Données spécifiques à la page
     * @param array{active?:string,page_title?:string} $options Options de chrome (item sidebar actif, titre topbar)
     */
    protected function renderApp(string $view, array $data = [], array $options = []): void
    {
        $resolver = new ClientResolver();
        $client = $resolver->resolveCurrent();

        $sidebar = [
            'active' => $options['active'] ?? '',
            'is_super_admin' => (bool) Session::get('is_super_admin', false),
            'current_client' => $client !== null ? [
                'id' => $client->id,
                'name' => $client->name,
                'logo_url' => $client->logoUrl,
            ] : null,
        ];

        $topbar = [
            'user_name' => (string) Session::get('user_full_name', ''),
            'user_email' => (string) Session::get('user_email', ''),
            'is_super_admin' => (bool) Session::get('is_super_admin', false),
            'page_title' => $options['page_title'] ?? '',
        ];

        $this->render($view, layout: 'layouts.app', data: array_merge($data, [
            'sidebar' => $sidebar,
            'topbar' => $topbar,
            'title' => $options['page_title'] ?? ($data['title'] ?? 'PIM Musculation'),
        ]));
    }

    protected function redirect(string $location): void
    {
        header('Location: ' . $location);
        exit;
    }

    protected function back(): void
    {
        $this->redirect($_SERVER['HTTP_REFERER'] ?? '/');
    }

    /**
     * @param array<string,mixed> $payload
     */
    protected function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    protected function input(string $key, ?string $default = null): ?string
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? $default;
        if (is_string($value)) {
            $value = trim($value);
        }
        return $value === '' ? $default : (is_string($value) ? $value : $default);
    }

    protected function inputBool(string $key): bool
    {
        return filter_var($_POST[$key] ?? $_GET[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    protected function flashError(string $message): void
    {
        Session::flash('error', $message);
    }

    protected function flashSuccess(string $message): void
    {
        Session::flash('success', $message);
    }
}
