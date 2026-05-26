<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Repositories\AdminAlertsRepository;
use App\Repositories\AiUsageRepository;
use App\Repositories\ClientApiKeyRepository;
use App\Repositories\ClientAiPreferencesRepository;
use RuntimeException;

/**
 * Service IA unifié : sélectionne le provider configuré du client, appelle son endpoint,
 * log la consommation, applique le quota mensuel.
 *
 * Endpoint cible : tous renvoient { text, prompt_tokens, completion_tokens, model }.
 */
final class AiService
{
    public function __construct(
        private readonly Client $client,
    ) {
    }

    /**
     * Génère un texte (avec JSON output forcé si demandé).
     *
     * @param array{
     *     system_prompt:string,
     *     user_prompt:string,
     *     max_tokens?:int,
     *     temperature?:float,
     *     json_mode?:bool,
     *     entity_type?:string,
     *     entity_id?:string,
     * } $request
     * @return array{
     *     text:string,
     *     model:string,
     *     prompt_tokens:int,
     *     completion_tokens:int,
     *     cost_eur:float,
     * }
     */
    public function generate(array $request): array
    {
        $this->enforceQuota();

        $providerId = (new ClientAiPreferencesRepository())->get($this->client->id)['default_text_provider'];
        $apiKey = (new ClientApiKeyRepository())->get($this->client->id, $providerId);

        if ($apiKey === null || $apiKey === '') {
            throw new RuntimeException(
                'Aucune clé API configurée pour le provider "' . $providerId . '". '
                . 'Allez dans Paramètres → Outils IA pour la renseigner.'
            );
        }

        $result = match ($providerId) {
            'openrouter' => $this->callOpenRouter($apiKey, $request),
            'anthropic' => $this->callAnthropic($apiKey, $request),
            'openai', 'gemini', 'mistral' => throw new RuntimeException(
                'Provider "' . $providerId . '" pas encore implémenté en V1. '
                . 'Utilisez OpenRouter (qui supporte ' . $providerId . ' en interne) ou Anthropic.'
            ),
            default => throw new RuntimeException('Provider inconnu : ' . $providerId),
        };

        // Log usage + cost estimation
        $costEur = $this->estimateCostEur($providerId, $result['model'], $result['prompt_tokens'], $result['completion_tokens']);

        (new AiUsageRepository())->log(
            clientId: $this->client->id,
            provider: $providerId,
            model: $result['model'],
            promptTokens: $result['prompt_tokens'],
            completionTokens: $result['completion_tokens'],
            costEur: $costEur,
            entityType: $request['entity_type'] ?? null,
            entityId: $request['entity_id'] ?? null,
        );

        $this->checkAlertThreshold();

        return [
            'text' => $result['text'],
            'model' => $result['model'],
            'prompt_tokens' => $result['prompt_tokens'],
            'completion_tokens' => $result['completion_tokens'],
            'cost_eur' => $costEur,
        ];
    }

    // -------------------------------------------------------------------------
    // Provider implementations
    // -------------------------------------------------------------------------

    /**
     * @return array{text:string, model:string, prompt_tokens:int, completion_tokens:int}
     */
    private function callOpenRouter(string $apiKey, array $req): array
    {
        $model = 'anthropic/claude-sonnet-4.5';
        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $req['system_prompt']],
                ['role' => 'user', 'content' => $req['user_prompt']],
            ],
            'max_tokens' => $req['max_tokens'] ?? 2000,
            'temperature' => $req['temperature'] ?? 0.7,
        ];
        if (!empty($req['json_mode'])) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $resp = $this->httpJsonPost(
            url: 'https://openrouter.ai/api/v1/chat/completions',
            apiKey: $apiKey,
            body: $body,
            authHeader: 'Authorization: Bearer ' . $apiKey,
            extraHeaders: ['HTTP-Referer: https://github.com/2AMDMEDIA/PIMUSCU', 'X-Title: PIM Musculation'],
        );

        $text = $resp['choices'][0]['message']['content'] ?? '';
        if (!is_string($text) || $text === '') {
            throw new RuntimeException('Réponse OpenRouter vide ou inattendue.');
        }
        return [
            'text' => $text,
            'model' => $resp['model'] ?? $model,
            'prompt_tokens' => (int) ($resp['usage']['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($resp['usage']['completion_tokens'] ?? 0),
        ];
    }

    /**
     * @return array{text:string, model:string, prompt_tokens:int, completion_tokens:int}
     */
    private function callAnthropic(string $apiKey, array $req): array
    {
        $model = 'claude-sonnet-4-5-20250929';
        $body = [
            'model' => $model,
            'max_tokens' => $req['max_tokens'] ?? 2000,
            'temperature' => $req['temperature'] ?? 0.7,
            'system' => $req['system_prompt'],
            'messages' => [
                ['role' => 'user', 'content' => $req['user_prompt']],
            ],
        ];

        $resp = $this->httpJsonPost(
            url: 'https://api.anthropic.com/v1/messages',
            apiKey: $apiKey,
            body: $body,
            authHeader: 'x-api-key: ' . $apiKey,
            extraHeaders: ['anthropic-version: 2023-06-01'],
        );

        $text = $resp['content'][0]['text'] ?? '';
        if (!is_string($text) || $text === '') {
            throw new RuntimeException('Réponse Anthropic vide ou inattendue.');
        }
        return [
            'text' => $text,
            'model' => $resp['model'] ?? $model,
            'prompt_tokens' => (int) ($resp['usage']['input_tokens'] ?? 0),
            'completion_tokens' => (int) ($resp['usage']['output_tokens'] ?? 0),
        ];
    }

    // -------------------------------------------------------------------------
    // HTTP + helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<int,string> $extraHeaders
     * @return array<string,mixed>
     */
    private function httpJsonPost(string $url, string $apiKey, array $body, string $authHeader, array $extraHeaders = []): array
    {
        $ch = curl_init($url);

        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
            $authHeader,
        ], $extraHeaders);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'PIM-Musculation/0.1',
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

        $rawBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($rawBody === false) {
            throw new RuntimeException('Erreur réseau IA : ' . $error);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $snippet = mb_substr((string) $rawBody, 0, 500);
            throw new RuntimeException("HTTP {$httpCode} de l'API IA : {$snippet}");
        }

        $decoded = json_decode((string) $rawBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Réponse IA JSON invalide.');
        }
        return $decoded;
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

    /**
     * Estimation très grossière du coût en EUR pour les couples provider/model usuels.
     * Tarifs janvier 2026, USD → EUR (~0.92).
     */
    private function estimateCostEur(string $provider, string $model, int $promptTokens, int $completionTokens): float
    {
        // Tarifs par 1M tokens (input, output) en USD
        $tarifs = [
            'openrouter/anthropic/claude-sonnet-4.5' => [3.0, 15.0],
            'openrouter/anthropic/claude-sonnet-4'   => [3.0, 15.0],
            'openrouter/anthropic/claude-opus-4.7'   => [15.0, 75.0],
            'anthropic/claude-sonnet-4-5-20250929'   => [3.0, 15.0],
            'anthropic/claude-opus-4-7-20250929'     => [15.0, 75.0],
        ];

        $key = $provider . '/' . $model;
        $rates = $tarifs[$key] ?? [3.0, 15.0]; // défaut Sonnet
        $costUsd = ($promptTokens / 1_000_000) * $rates[0] + ($completionTokens / 1_000_000) * $rates[1];
        return round($costUsd * 0.92, 6); // USD → EUR
    }

    // -------------------------------------------------------------------------
    // Quotas et alertes
    // -------------------------------------------------------------------------

    private function enforceQuota(): void
    {
        $limit = $this->client->tokenMonthlyLimit;
        if ($limit <= 0) {
            return; // 0 = pas de limite
        }
        $used = (new AiUsageRepository())->tokensLast30Days($this->client->id);
        if ($used >= $limit) {
            (new AdminAlertsRepository())->pushIfNew(
                $this->client->id,
                'token_blocked',
                'Quota mensuel atteint pour ' . $this->client->name . ' (' . $used . '/' . $limit . ' tokens). Appel IA bloqué.',
            );
            throw new RuntimeException(
                'Quota mensuel de tokens atteint (' . number_format($used, 0, ',', ' ')
                . ' / ' . number_format($limit, 0, ',', ' ') . '). Contactez votre administrateur.'
            );
        }
    }

    private function checkAlertThreshold(): void
    {
        $limit = $this->client->tokenMonthlyLimit;
        if ($limit <= 0) {
            return;
        }
        $threshold = $this->client->tokenAlertThreshold;
        if ($threshold <= 0 || $threshold > 100) {
            return;
        }
        $used = (new AiUsageRepository())->tokensLast30Days($this->client->id);
        $percent = ($used / $limit) * 100;
        if ($percent >= $threshold) {
            (new AdminAlertsRepository())->pushIfNew(
                $this->client->id,
                'token_threshold',
                'Seuil d\'alerte atteint pour ' . $this->client->name . ' (' . round($percent, 1) . '% — '
                . $used . '/' . $limit . ' tokens sur 30 jours).',
            );
        }
    }
}
