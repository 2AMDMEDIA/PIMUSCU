<?php

declare(strict_types=1);

namespace App\Helpers;

use RuntimeException;

/**
 * Chiffrement symétrique AES-256-CBC pour les API keys stockées en DB.
 * La clé maître provient de APP_SECRET (.env), 64 caractères hex (32 octets).
 *
 * Format stocké : base64( iv | ciphertext | hmac )
 */
final class Encryption
{
    private const CIPHER = 'aes-256-cbc';
    private const HMAC_ALGO = 'sha256';

    private static function key(): string
    {
        $secret = $_ENV['APP_SECRET'] ?? '';
        if ($secret === '' || strlen($secret) < 32) {
            throw new RuntimeException('APP_SECRET manquant ou trop court (32 octets minimum, 64 hex).');
        }
        // Si l'utilisateur a fourni 64 hex chars, on les décode en 32 octets binaires.
        if (preg_match('/^[0-9a-f]{64}$/i', $secret)) {
            return hex2bin($secret);
        }
        return substr(hash('sha256', $secret, true), 0, 32);
    }

    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new RuntimeException('Échec du chiffrement.');
        }
        $hmac = hash_hmac(self::HMAC_ALGO, $iv . $ciphertext, $key, true);
        return base64_encode($iv . $ciphertext . $hmac);
    }

    public static function decrypt(string $encoded): string
    {
        $key = self::key();
        $blob = base64_decode($encoded, true);
        if ($blob === false) {
            throw new RuntimeException('Données chiffrées invalides (base64).');
        }
        $ivLen = openssl_cipher_iv_length(self::CIPHER);
        $hmacLen = 32; // sha256 raw
        if (strlen($blob) < $ivLen + $hmacLen + 1) {
            throw new RuntimeException('Données chiffrées trop courtes.');
        }
        $iv = substr($blob, 0, $ivLen);
        $hmac = substr($blob, -$hmacLen);
        $ciphertext = substr($blob, $ivLen, -$hmacLen);

        $expected = hash_hmac(self::HMAC_ALGO, $iv . $ciphertext, $key, true);
        if (!hash_equals($expected, $hmac)) {
            throw new RuntimeException('HMAC invalide — données corrompues ou clé erronée.');
        }

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            throw new RuntimeException('Échec du déchiffrement.');
        }
        return $plaintext;
    }
}
