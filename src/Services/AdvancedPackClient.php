<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Encryption;
use App\Models\Client;
use RuntimeException;

/**
 * Client HTTP pour le fichier api_advancedpack.php uploade a la racine PS.
 * Retourne la liste des id_product qui sont des packs Advanced Pack.
 */
final class AdvancedPackClient
{
    private string $lastCalledUrl = '';
    private string $lastRawBody = '';

    public function __construct(
        private readonly Client $client,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->client->advancedPackApiKeyEncrypted !== null
            && trim((string) $this->client->prestashopUrl) !== '';
    }

    public function getLastCalledUrl(): string
    {
        return $this->lastCalledUrl;
    }

    public function getLastRawBody(): string
    {
        return $this->lastRawBody;
    }

    /**
     * Retourne la liste des id_product qui sont des packs Advanced Pack.
     * @return list<int>
     */
    public function fetchPackIds(): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Clé API Advanced Pack non configurée.');
        }
        $apiKey = Encryption::decrypt((string) $this->client->advancedPackApiKeyEncrypted);
        $url = rtrim($this->client->prestashopUrl, '/') . '/api_advancedpack.php';
        $this->lastCalledUrl = $url;

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-API-Key: ' . $apiKey,
            ],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 PIM-Musculation/0.1',
        ];
        if (class_exists(\Composer\CaBundle\CaBundle::class)) {
            $caPath = \Composer\CaBundle\CaBundle::getBundledCaBundlePath();
            if (is_file($caPath)) $options[CURLOPT_CAINFO] = $caPath;
        }
        $tlsVerify = $_ENV['APP_TLS_VERIFY'] ?? 'true';
        if (filter_var($tlsVerify, FILTER_VALIDATE_BOOLEAN) === false) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Erreur réseau api_advancedpack : ' . $error);
        }
        $this->lastRawBody = (string) $body;
        if ($httpCode < 200 || $httpCode >= 300) {
            $snippet = mb_substr((string) $body, 0, 300);
            throw new RuntimeException("api_advancedpack HTTP {$httpCode} : {$snippet}");
        }

        $payload = json_decode((string) $body, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Réponse api_advancedpack invalide (JSON attendu).');
        }
        if (empty($payload['success'])) {
            $err = (string) ($payload['error'] ?? 'erreur inconnue');
            throw new RuntimeException('api_advancedpack retour erreur : ' . $err);
        }
        $ids = $payload['data']['pack_ids'] ?? [];
        if (!is_array($ids)) return [];
        return array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
    }
}
