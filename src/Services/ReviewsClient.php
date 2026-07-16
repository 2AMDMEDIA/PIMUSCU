<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Encryption;
use App\Models\Client;
use RuntimeException;

/**
 * Client pour l'endpoint custom `api_reviews.php` (module ws_productreviews).
 *
 * L'endpoint est servi à la racine du shop : {prestashop_url}/api_reviews.php
 * Authentification par paramètre ?api_key=...
 */
final class ReviewsClient
{
    public function __construct(
        private readonly Client $client,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->client->prestashopUrl !== '' && $this->client->prestashopReviewsApiKeyEncrypted !== null;
    }

    /**
     * @return array<int,array{count:int,avg_grade:float}>  Map id_product => stats
     */
    public function fetchStatsByProduct(): array
    {
        $body = $this->get([]);
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['stats']) || !is_array($decoded['stats'])) {
            return [];
        }
        $result = [];
        foreach ($decoded['stats'] as $row) {
            $id = (int) ($row['id_product'] ?? 0);
            if ($id > 0) {
                $result[$id] = [
                    'count' => (int) ($row['count'] ?? 0),
                    'avg_grade' => (float) ($row['avg_grade'] ?? 0),
                ];
            }
        }
        return $result;
    }

    /**
     * @return list<array{
     *     id:int,
     *     customer_name:string,
     *     title:string,
     *     content:string,
     *     grade:int,
     *     validate:int,
     *     deleted:int,
     *     date_add:string,
     * }>
     */
    public function fetchReviewsForProduct(int $productId): array
    {
        $body = $this->get(['product_id' => (string) $productId]);
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['reviews']) || !is_array($decoded['reviews'])) {
            return [];
        }
        $result = [];
        foreach ($decoded['reviews'] as $row) {
            $result[] = [
                'id' => (int) ($row['id_product_comment'] ?? 0),
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'content' => (string) ($row['content'] ?? ''),
                'grade' => (int) ($row['grade'] ?? 0),
                'validate' => (int) ($row['validate'] ?? 0),
                'deleted' => (int) ($row['deleted'] ?? 0),
                'date_add' => (string) ($row['date_add'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * Pousse un batch d'avis vers api_reviews.php (POST JSON).
     *
     * @param list<array{
     *     id_product:int, customer_name?:string, title?:string, content:string,
     *     grade?:int, validate?:int, date_add?:string,
     * }> $reviews
     * @return array{success:bool, inserted:int, updated:int, errors:array}
     */
    public function pushReviews(array $reviews): array
    {
        $url = rtrim($this->client->prestashopUrl, '/')
            . '/api_reviews.php?api_key=' . urlencode($this->apiKey());

        $payload = json_encode($reviews, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('Encodage JSON impossible.');
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 PIM-Musculation/0.1',
        ];

        $caPath = $this->resolveCaBundlePath();
        if ($caPath !== null) {
            $options[CURLOPT_CAINFO] = $caPath;
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
            throw new RuntimeException('Erreur réseau api_reviews.php (POST) : ' . $error);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $snippet = mb_substr((string) $body, 0, 1000);
            throw new RuntimeException("HTTP {$httpCode} sur POST api_reviews.php : {$snippet}");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Réponse api_reviews.php invalide (JSON attendu).');
        }
        return [
            'success' => (bool) ($decoded['success'] ?? false),
            'inserted' => (int) ($decoded['inserted'] ?? 0),
            'updated' => (int) ($decoded['updated'] ?? 0),
            'errors' => $decoded['errors'] ?? [],
        ];
    }

    /**
     * Met à jour un avis existant par son id_product_comment.
     *
     * @param array<string,mixed> $fields  customer_name, title, content, grade, validate
     */
    public function updateReview(int $reviewId, array $fields): void
    {
        $this->jsonRequest('PUT', '?id=' . $reviewId, $fields);
    }

    /**
     * Supprime un avis. Par défaut soft delete (deleted=1).
     */
    public function deleteReview(int $reviewId, bool $hard = false): void
    {
        $query = '?id=' . $reviewId . ($hard ? '&hard=1' : '');
        $this->jsonRequest('DELETE', $query, null);
    }

    /**
     * @param array<string,mixed>|null $payload
     */
    private function jsonRequest(string $method, string $queryStringSuffix, ?array $payload): string
    {
        $url = rtrim($this->client->prestashopUrl, '/')
            . '/api_reviews.php' . $queryStringSuffix
            . (str_contains($queryStringSuffix, '?') ? '&' : '?')
            . 'api_key=' . urlencode($this->apiKey());

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 PIM-Musculation/0.1',
        ];
        if ($payload !== null) {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        $caPath = $this->resolveCaBundlePath();
        if ($caPath !== null) {
            $options[CURLOPT_CAINFO] = $caPath;
        }
        $tlsVerify = $_ENV['APP_TLS_VERIFY'] ?? 'true';
        if (filter_var($tlsVerify, FILTER_VALIDATE_BOOLEAN) === false) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($ch, $options);
        $rawBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($rawBody === false) {
            throw new RuntimeException("Erreur réseau ({$method}) : " . $error);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $snippet = mb_substr((string) $rawBody, 0, 1000);
            throw new RuntimeException("HTTP {$httpCode} sur {$method} api_reviews.php : {$snippet}");
        }
        return (string) $rawBody;
    }

    private function apiKey(): string
    {
        if ($this->client->prestashopReviewsApiKeyEncrypted === null) {
            throw new RuntimeException('Clé API Avis non configurée pour ce client.');
        }
        return Encryption::decrypt($this->client->prestashopReviewsApiKeyEncrypted);
    }

    /**
     * @param array<string,string> $query
     */
    private function get(array $query): string
    {
        $query['api_key'] = $this->apiKey();
        $url = rtrim($this->client->prestashopUrl, '/') . '/api_reviews.php?' . http_build_query($query);

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 PIM-Musculation/0.1',
        ];

        $caPath = $this->resolveCaBundlePath();
        if ($caPath !== null) {
            $options[CURLOPT_CAINFO] = $caPath;
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
            throw new RuntimeException('Erreur réseau api_reviews.php : ' . $error);
        }
        if ($httpCode === 401) {
            throw new RuntimeException('Clé API Avis invalide (401). Vérifie qu\'elle correspond à celle dans api_reviews.php sur le shop.');
        }
        if ($httpCode === 404) {
            throw new RuntimeException('Fichier api_reviews.php introuvable à la racine du shop. Téléchargez-le depuis Paramètres → PrestaShop et placez-le sur votre serveur.');
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $snippet = mb_substr((string) $body, 0, 200);
            throw new RuntimeException("HTTP {$httpCode} de api_reviews.php : {$snippet}");
        }
        return (string) $body;
    }

    private function resolveCaBundlePath(): ?string
    {
        $override = $_ENV['APP_CA_BUNDLE'] ?? null;
        if (is_string($override) && $override !== '' && is_file($override)) {
            return $override;
        }
        if (class_exists(\Composer\CaBundle\CaBundle::class)) {
            $path = \Composer\CaBundle\CaBundle::getBundledCaBundlePath();
            if (is_file($path)) {
                return $path;
            }
        }
        foreach ([ini_get('curl.cainfo'), ini_get('openssl.cafile')] as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                return $path;
            }
        }
        return null;
    }
}
