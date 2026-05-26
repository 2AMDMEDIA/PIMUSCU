<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Repositories\ClientApiKeyRepository;
use RuntimeException;

/**
 * Client Kie.AI pour la génération d'images (GPT Image-2 par défaut).
 *
 * Doc officielle :
 *   - https://docs.kie.ai/market/gpt/gpt-image-2-text-to-image
 *   - https://docs.kie.ai/market/common/get-task-detail
 *
 * Pattern unifié Kie.AI (depuis fin 2025) :
 *   POST {base}/api/v1/jobs/createTask
 *        body: { model, input: {...}, callBackUrl? }
 *        resp: { code, msg, data: { taskId } }
 *
 *   GET  {base}/api/v1/jobs/recordInfo?taskId=X
 *        resp: { code, msg, data: { taskId, model, state, resultJson, costTime, creditsConsumed } }
 *
 *  - state ∈ { waiting, queuing, generating, success, fail }
 *  - resultJson est une string JSON-encoded : '{"resultUrls":["https://..."]}'
 */
final class KieClient
{
    private const BASE_URL = 'https://api.kie.ai';
    public const DEFAULT_MODEL = 'gpt-image-2-text-to-image';

    /**
     * Catalogue des modèles Kie.AI proposés dans l'UI.
     *
     *  - input_urls_mode      : 'none' (pas d'image source) | 'optional' | 'required'
     *  - input_urls_field     : nom du champ dans le payload Kie.AI ("input_urls" pour GPT, "image_input" pour Nano Banana…)
     *  - input_urls_max       : nombre max d'URLs source acceptées
     *  - aspect_ratios        : liste des ratios acceptés par le modèle (sans "auto"), du plus précis au plus large
     *  - output_format        : forcé dans le payload si défini (png/jpg)
     *
     * @var array<string, array{
     *   label:string, description:string,
     *   input_urls_mode:string, input_urls_field:?string, input_urls_max:int,
     *   aspect_ratios:list<string>, output_format:?string
     * }>
     */
    public const MODELS = [
        'gpt-image-2-text-to-image' => [
            'label' => 'GPT Image 2 — Texte → Image',
            'description' => 'Génération depuis un prompt texte. Très bon pour intégrer du texte lisible dans l\'image.',
            'input_urls_mode' => 'none',
            'input_urls_field' => null,
            'input_urls_max' => 0,
            'aspect_ratios' => ['1:1', '9:16', '16:9', '4:3', '3:4'],
            'output_format' => null,
        ],
        'gpt-image-2-image-to-image' => [
            'label' => 'GPT Image 2 — Image + Texte → Image',
            'description' => 'Transforme une image source selon ton prompt. Idéal pour mettre en scène une photo produit.',
            'input_urls_mode' => 'required',
            'input_urls_field' => 'input_urls',
            'input_urls_max' => 16,
            'aspect_ratios' => ['1:1', '9:16', '16:9', '4:3', '3:4'],
            'output_format' => null,
        ],
        'nano-banana-2' => [
            'label' => 'Nano Banana 2 (Google)',
            'description' => 'Modèle Google polyvalent. 14 ratios précis (1:1, 16:9, 21:9, 4:1, 9:16, 4:5…) et images source optionnelles.',
            'input_urls_mode' => 'optional',
            'input_urls_field' => 'image_input',
            'input_urls_max' => 14,
            'aspect_ratios' => ['1:1', '1:4', '1:8', '2:3', '3:2', '3:4', '4:1', '4:3', '4:5', '5:4', '8:1', '9:16', '16:9', '21:9'],
            'output_format' => 'png',
        ],
    ];

    public function __construct(
        private readonly Client $client,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== null;
    }

    private function apiKey(): ?string
    {
        return (new ClientApiKeyRepository())->get($this->client->id, 'kie');
    }

    /**
     * Lance une génération image. Renvoie le task_id Kie.AI.
     *
     * @param list<string> $inputUrls URLs des images source (requis pour les modèles image-to-image, optionnel pour Nano Banana)
     * @return array{task_id:string, model:string, raw:array<string,mixed>}
     */
    public function submitGeneration(
        string $prompt,
        int $width,
        int $height,
        string $model = self::DEFAULT_MODEL,
        array $inputUrls = [],
    ): array {
        if (!isset(self::MODELS[$model])) {
            throw new RuntimeException("Modèle Kie.AI inconnu : {$model}");
        }
        $meta = self::MODELS[$model];

        // Validation des input_urls selon le mode du modèle
        $mode = $meta['input_urls_mode'];
        if ($mode === 'required' && empty($inputUrls)) {
            throw new RuntimeException("Le modèle {$model} requiert au moins une URL d'image source.");
        }
        if ($mode === 'none' && !empty($inputUrls)) {
            // On ignore silencieusement les URLs fournies par erreur sur un modèle pure text-to-image
            $inputUrls = [];
        }
        if (count($inputUrls) > $meta['input_urls_max']) {
            throw new RuntimeException("Le modèle {$model} accepte au maximum {$meta['input_urls_max']} images source.");
        }

        // Choix du ratio dans la liste supportée par ce modèle (le plus proche du w/h demandé)
        $aspect = $this->closestRatioFromList($width, $height, $meta['aspect_ratios']);
        // Résolution adaptative
        $resolution = $this->pickResolution($width, $height, $aspect);

        $input = [
            'prompt' => $prompt,
            'aspect_ratio' => $aspect,
            'resolution' => $resolution,
        ];
        if (!empty($inputUrls) && $meta['input_urls_field'] !== null) {
            $input[$meta['input_urls_field']] = array_values($inputUrls);
        }
        if ($meta['output_format'] !== null) {
            $input['output_format'] = $meta['output_format'];
        }

        $body = [
            'model' => $model,
            'input' => $input,
        ];

        $resp = $this->httpJson('POST', '/api/v1/jobs/createTask', $body);
        $taskId = $resp['data']['taskId'] ?? null;
        if (!is_string($taskId) || $taskId === '') {
            throw new RuntimeException('Réponse Kie.AI sans taskId : ' . json_encode($resp, JSON_UNESCAPED_UNICODE));
        }
        return ['task_id' => $taskId, 'model' => $model, 'raw' => $resp];
    }

    /**
     * Récupère l'état d'une génération.
     *
     * @return array{state:'pending'|'success'|'error', image_url:?string, error:?string, raw:array<string,mixed>}
     */
    public function pollStatus(string $taskId): array
    {
        $resp = $this->httpJson('GET', '/api/v1/jobs/recordInfo?taskId=' . urlencode($taskId), null);

        $data = $resp['data'] ?? [];
        $stateRaw = strtolower((string) ($data['state'] ?? ''));

        $state = 'pending';
        $imageUrl = null;
        $error = null;

        if ($stateRaw === 'success') {
            $state = 'success';
            $imageUrl = $this->extractFirstUrl($data);
            if ($imageUrl === null) {
                $state = 'error';
                $error = 'Tâche réussie mais aucune image dans resultJson : '
                    . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        } elseif ($stateRaw === 'fail') {
            $state = 'error';
            $error = (string) ($data['failMsg']
                ?? $data['failReason']
                ?? $data['errorMessage']
                ?? $data['msg']
                ?? 'Échec Kie.AI (state=fail)');
        }
        // waiting / queuing / generating → reste pending

        return ['state' => $state, 'image_url' => $imageUrl, 'error' => $error, 'raw' => $resp];
    }

    /**
     * Parse data.resultJson (string JSON-encoded) et renvoie la première URL.
     *
     * @param array<string,mixed> $data
     */
    private function extractFirstUrl(array $data): ?string
    {
        $raw = $data['resultJson'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $urls = $decoded['resultUrls'] ?? [];
                if (is_array($urls) && !empty($urls) && is_string($urls[0]) && $urls[0] !== '') {
                    return $urls[0];
                }
            }
        }
        // Fallback : certains modèles renvoient resultUrls directement à la racine de data
        $urls = $data['resultUrls'] ?? null;
        if (is_array($urls) && !empty($urls) && is_string($urls[0])) {
            return $urls[0];
        }
        return null;
    }

    /**
     * Choisit le ratio le plus proche de width/height parmi la liste autorisée par le modèle.
     *
     * @param list<string> $candidates ex: ['1:1', '9:16', '16:9', '4:3', '3:4']
     */
    private function closestRatioFromList(int $w, int $h, array $candidates): string
    {
        if ($w <= 0 || $h <= 0 || empty($candidates)) {
            return $candidates[0] ?? '1:1';
        }
        $ratio = $w / $h;
        $best = $candidates[0];
        $bestDelta = PHP_FLOAT_MAX;
        foreach ($candidates as $label) {
            $parts = explode(':', $label, 2);
            if (count($parts) !== 2) continue;
            $a = (float) $parts[0];
            $b = (float) $parts[1];
            if ($b <= 0) continue;
            $delta = abs(($a / $b) - $ratio);
            if ($delta < $bestDelta) {
                $bestDelta = $delta;
                $best = $label;
            }
        }
        return $best;
    }

    /**
     * Choisit la résolution Kie.AI (1K / 2K / 4K) en fonction de la dimension cible.
     * Contrainte doc : aspect 1:1 ne peut PAS être en 4K.
     */
    private function pickResolution(int $w, int $h, string $aspect): string
    {
        $maxSide = max($w, $h);
        if ($maxSide >= 3000 && $aspect !== '1:1') {
            return '4K';
        }
        if ($maxSide >= 1500) {
            return '2K';
        }
        return '1K';
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array<string,mixed>
     */
    private function httpJson(string $method, string $path, ?array $payload): array
    {
        $key = $this->apiKey();
        if ($key === null) {
            throw new RuntimeException('Clé API Kie.AI non configurée pour ce client. Allez dans Paramètres → Outils IA.');
        }

        $url = self::BASE_URL . $path;
        $ch = curl_init($url);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'PIM-Musculation/0.1',
        ];

        if ($payload !== null && $method !== 'GET') {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Erreur réseau Kie.AI : ' . $error);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("HTTP {$httpCode} Kie.AI : " . mb_substr((string) $body, 0, 500));
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Réponse Kie.AI JSON invalide : ' . mb_substr((string) $body, 0, 200));
        }

        // Kie.AI renvoie HTTP 200 même pour les erreurs business → on check le champ "code"
        $code = $decoded['code'] ?? null;
        if ($code !== null && (int) $code !== 200) {
            $msg = (string) ($decoded['msg'] ?? 'Erreur Kie.AI');
            throw new RuntimeException("Kie.AI code {$code} : {$msg}");
        }

        return $decoded;
    }

    private function resolveCaBundlePath(): ?string
    {
        if (class_exists(\Composer\CaBundle\CaBundle::class)) {
            $path = \Composer\CaBundle\CaBundle::getBundledCaBundlePath();
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }
}
