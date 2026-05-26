<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Session;
use RuntimeException;

/**
 * Token CSRF par session. Régénéré à chaque login.
 * Tous les formulaires POST doivent inclure <input name="_csrf" value="<?= Csrf::token() ?>">.
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (!Session::has(self::KEY)) {
            Session::set(self::KEY, bin2hex(random_bytes(32)));
        }
        return Session::get(self::KEY);
    }

    public static function regenerate(): void
    {
        Session::set(self::KEY, bin2hex(random_bytes(32)));
    }

    public static function check(?string $submitted): bool
    {
        $expected = Session::get(self::KEY);
        if (!is_string($expected) || !is_string($submitted)) {
            return false;
        }
        return hash_equals($expected, $submitted);
    }

    public static function enforce(?string $submitted): void
    {
        if (!self::check($submitted)) {
            throw new RuntimeException('Token CSRF invalide.');
        }
    }
}
