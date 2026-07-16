<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Encryption;
use App\Models\Client;
use RuntimeException;

/**
 * Client HTTP pour le module PrestaShop `aw_customproductfield`
 * (endpoint /modules/aw_customproductfield/api.php).
 *
 * Auth : header `X-API-Key: <clé>` (clé stockée chiffrée sur le client PIM).
 */
final class AwCpfClient
{
    private const API_PATH = '/modules/aw_customproductfield/api.php';

    /** Pour debug : dernière URL appelée et corps de réponse brut. */
    private string $lastCalledUrl = '';
    private string $lastRawBody = '';

    public function __construct(
        private readonly Client $client,
    ) {
    }

    public function getLastCalledUrl(): string
    {
        return $this->lastCalledUrl;
    }

    public function getLastRawBody(): string
    {
        return $this->lastRawBody;
    }

    public function isConfigured(): bool
    {
        return $this->client->awCpfApiKeyEncrypted !== null
            && trim((string) $this->client->prestashopUrl) !== '';
    }

    /**
     * Retourne la liste des champs personnalisés définis côté module.
     *
     * @return list<array{key:string, label:string, type:string, lang?:bool, enabled?:bool, scope?:string}>
     */
    public function fetchSchema(): array
    {
        $body = $this->get('?action=schema');
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Réponse aw_customproductfield invalide (JSON attendu).');
        }

        // Le payload peut avoir plusieurs formes : liste directe, {fields:[...]},
        // {data:{fields:[...], total:N}} (forme rencontrée sur musculation.com), etc.
        // On cherche récursivement une liste dont les items ressemblent à des champs.
        $items = $this->findFieldsList($payload);
        if ($items === null) {
            return [];
        }

        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $key = trim((string) ($it['key'] ?? $it['name'] ?? ''));
            if ($key === '') continue;
            // scope : 'product' | 'combination' (défaut 'combination' pour retro-compat)
            $scope = (string) ($it['scope'] ?? 'combination');
            if (!in_array($scope, ['product', 'combination'], true)) $scope = 'combination';
            $out[] = [
                'key' => $key,
                'label' => (string) ($it['label'] ?? $it['title'] ?? $key),
                'type' => (string) ($it['type'] ?? 'text'),
                'lang' => !empty($it['lang']),
                'enabled' => !isset($it['enabled']) || (bool) $it['enabled'],
                'scope' => $scope,
            ];
        }
        return $out;
    }

    /**
     * Écrit un lot de valeurs sur le module (endpoint ?action=set_batch).
     *
     * @param list<array{id_product:int, id_product_attribute:int, field:string, value:mixed, id_lang?:int}> $updates
     * @return array{ok:bool, applied:int, errors:list<string>, raw:array<string,mixed>|null}
     */
    public function setBatch(array $updates): array
    {
        if ($updates === []) {
            return ['ok' => true, 'applied' => 0, 'errors' => [], 'raw' => null];
        }
        $body = $this->postJson('?action=set_batch', ['updates' => $updates]);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Réponse aw_customproductfield invalide (JSON attendu).');
        }
        // La réponse peut suivre plusieurs formes ; on essaye de déduire un compteur
        // et une liste d'erreurs. En fallback on remonte le brut.
        $ok = (bool) ($decoded['success'] ?? $decoded['ok'] ?? true);
        $applied = (int) ($decoded['data']['applied'] ?? $decoded['applied'] ?? $decoded['data']['total'] ?? count($updates));
        $errors = [];
        foreach (($decoded['data']['errors'] ?? $decoded['errors'] ?? []) as $e) {
            $errors[] = is_string($e) ? $e : json_encode($e, JSON_UNESCAPED_UNICODE);
        }
        return ['ok' => $ok, 'applied' => $applied, 'errors' => $errors, 'raw' => $decoded];
    }

    /**
     * Cherche récursivement dans le payload une liste dont les items ressemblent
     * à des définitions de champs (contiennent au moins une clé `key`/`name`).
     */
    private function findFieldsList(array $node): ?array
    {
        // Cas 1 : $node est déjà la liste attendue.
        if (array_is_list($node) && $this->looksLikeFieldsList($node)) {
            return $node;
        }
        // Cas 2 : clés canoniques à un niveau (fields/schema/items/result/data).
        //   Attention : "data" peut être soit une liste, soit un objet {fields:[...]}.
        foreach (['fields', 'schema', 'items', 'result', 'data'] as $k) {
            if (!isset($node[$k]) || !is_array($node[$k])) continue;
            if (array_is_list($node[$k]) && $this->looksLikeFieldsList($node[$k])) {
                return $node[$k];
            }
            $found = $this->findFieldsList($node[$k]);
            if ($found !== null) return $found;
        }
        // Cas 3 : parcours défensif de tout enfant (au cas où une clé inconnue enveloppe).
        foreach ($node as $v) {
            if (is_array($v)) {
                $found = $this->findFieldsList($v);
                if ($found !== null) return $found;
            }
        }
        return null;
    }

    private function looksLikeFieldsList(array $list): bool
    {
        if ($list === []) return false;
        $first = $list[0] ?? null;
        return is_array($first) && (isset($first['key']) || isset($first['name']));
    }

    private function apiKey(): string
    {
        if ($this->client->awCpfApiKeyEncrypted === null) {
            throw new RuntimeException('Clé API aw_customproductfield non configurée (Paramètres → PrestaShop).');
        }
        return Encryption::decrypt($this->client->awCpfApiKeyEncrypted);
    }

    private function baseUrl(): string
    {
        return rtrim($this->client->prestashopUrl, '/') . self::API_PATH;
    }

    /**
     * POST JSON. Ex : postJson('?action=set_batch', ['updates' => [...]])
     *
     * @param array<string,mixed> $payload
     */
    private function postJson(string $queryString, array $payload): string
    {
        $url = $this->baseUrl() . $queryString;
        $this->lastCalledUrl = $url;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Impossible d\'encoder le payload JSON.');
        }
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json; charset=utf-8',
                'X-API-Key: ' . $this->apiKey(),
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
            throw new RuntimeException('Erreur réseau aw_customproductfield : ' . $error);
        }
        $this->lastRawBody = (string) $body;
        if ($httpCode < 200 || $httpCode >= 300) {
            $snippet = mb_substr((string) $body, 0, 300);
            throw new RuntimeException("aw_customproductfield HTTP {$httpCode} : {$snippet}");
        }
        return (string) $body;
    }

    /**
     * @param string $queryString Ex. "?action=schema"
     */
    private function get(string $queryString): string
    {
        $url = $this->baseUrl() . $queryString;
        $this->lastCalledUrl = $url;
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-API-Key: ' . $this->apiKey(),
            ],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 PIM-Musculation/0.1',
        ];
        if (class_exists(\Composer\CaBundle\CaBundle::class)) {
            $caPath = \Composer\CaBundle\CaBundle::getBundledCaBundlePath();
            if (is_file($caPath)) $options[CURLOPT_CAINFO] = $caPath;
        }
        // Opt-in dev local : désactivation TLS verify.
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
            throw new RuntimeException('Erreur réseau aw_customproductfield : ' . $error);
        }
        $this->lastRawBody = (string) $body;
        if ($httpCode < 200 || $httpCode >= 300) {
            $snippet = mb_substr((string) $body, 0, 200);
            throw new RuntimeException("aw_customproductfield HTTP {$httpCode} : {$snippet}");
        }
        return (string) $body;
    }
}
