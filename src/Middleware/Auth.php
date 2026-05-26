<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Session;

/**
 * Garde-fous d'authentification appelés depuis BaseController des contrôleurs concernés.
 */
final class Auth
{
    /** Exige une session active. Sinon redirige vers /login. */
    public static function require(): void
    {
        if (!Session::isLoggedIn()) {
            header('Location: /login');
            exit;
        }
    }

    /** Exige une session ET le flag super-admin. Sinon 403. */
    public static function requireSuperAdmin(): void
    {
        self::require();
        if (!Session::get('is_super_admin', false)) {
            http_response_code(403);
            echo '<h1>403 — Accès refusé</h1><p>Cette page est réservée aux super-admins.</p>';
            exit;
        }
    }
}
