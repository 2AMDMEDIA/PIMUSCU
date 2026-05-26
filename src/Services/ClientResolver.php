<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Models\Client;
use App\Repositories\ClientRepository;
use App\Session;

/**
 * Résout le client_id actif pour la requête courante.
 *
 *  - Si super-admin avec cookie x-client-id → ce client (impersonation)
 *  - Sinon → le 1er client dans user_clients pour le user courant
 *  - Sinon null
 */
final class ClientResolver
{
    private const COOKIE_NAME = 'x-client-id';
    private const COOKIE_LIFETIME = 86400; // 24h

    public function resolveCurrent(): ?Client
    {
        $userId = Session::userId();
        if ($userId === null) {
            return null;
        }
        $isSuperAdmin = (bool) Session::get('is_super_admin', false);
        $repo = new ClientRepository();

        // Super-admin avec cookie d'impersonation
        if ($isSuperAdmin) {
            $cookieClientId = $_COOKIE[self::COOKIE_NAME] ?? null;
            if (is_string($cookieClientId) && $cookieClientId !== '') {
                $client = $repo->findById($cookieClientId);
                if ($client !== null) {
                    return $client;
                }
                // Cookie pourri ou client supprimé → on l'efface
                self::clearCookie();
            }
            return null;
        }

        // User normal — premier client lié
        return $repo->findFirstForUser($userId);
    }

    public function setActiveClient(string $clientId): void
    {
        setcookie(self::COOKIE_NAME, $clientId, [
            'expires' => time() + self::COOKIE_LIFETIME,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE_NAME] = $clientId; // immédiatement disponible dans la requête courante
    }

    public static function clearCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::COOKIE_NAME]);
    }
}
