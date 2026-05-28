<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Repositories\ClientApiKeyRepository;
use RuntimeException;

/**
 * Comparateur de prix via SerpApi (Google Shopping, France).
 *
 * Avantages vs web_search Claude :
 *  - Données structurées (titre, prix extrait, source marchand, lien, note, avis)
 *  - Coût fixe et faible (~0,01 €/recherche selon plan SerpApi)
 *  - Fiable, à jour, pas d'invention de Claude
 *  - Pas de parsing JSON à risque
 *
 * Nécessite une clé API SerpApi (Paramètres → Outils IA).
 */
final class PriceComparator
{
    private const SERPAPI_URL = 'https://serpapi.com/search.json';
    private const ENGINE = 'google_shopping';
    private const LOCATION = 'France';
    private const HL = 'fr';
    private const GL = 'fr';
    private const MAX_RESULTS = 20;

    public function __construct(
        private readonly Client $client,
    ) {
    }

    /**
     * @return array{
     *     results: list<array{site:string, name:string, price_eur:?float, url:?string, in_stock:?bool, notes:?string, thumbnail:?string, rating:?float, reviews_count:?int}>,
     *     summary: string,
     *     avg_price_eur: ?float,
     *     min_price_eur: ?float,
     *     max_price_eur: ?float,
     *     median_price_eur: ?float,
     *     search_query: string,
     *     search_count: int,
     * }
     */
    public function compare(string $productName, string $reference, float $currentPriceTTC): array
    {
        $apiKey = (new ClientApiKeyRepository())->get($this->client->id, 'serpapi');
        if ($apiKey === null || $apiKey === '') {
            throw new RuntimeException(
                'Pour la comparaison de prix, il faut une clé API SerpApi. '
                . 'Configure-la dans Paramètres → Outils IA → SerpApi (Google Shopping). '
                . 'Crée un compte gratuit sur https://serpapi.com pour récupérer ta clé.'
            );
        }

        $query = trim($productName);
        if ($query === '') {
            throw new RuntimeException('Nom de produit vide — impossible de chercher.');
        }

        $params = [
            'engine' => self::ENGINE,
            'q' => $query,
            'location' => self::LOCATION,
            'hl' => self::HL,
            'gl' => self::GL,
            'api_key' => $apiKey,
        ];
        $url = self::SERPAPI_URL . '?' . http_build_query($params);

        $resp = $this->httpGetJson($url);

        if (isset($resp['error'])) {
            throw new RuntimeException('SerpApi : ' . (string) $resp['error']);
        }

        $shoppingResults = $resp['shopping_results'] ?? [];
        if (!is_array($shoppingResults)) {
            $shoppingResults = [];
        }

        $results = [];
        $foundCount = 0;
        foreach ($shoppingResults as $r) {
            if (!is_array($r) || $foundCount >= self::MAX_RESULTS) continue;

            // SerpApi renvoie un champ `extracted_price` (float) en plus du `price` formaté.
            $price = null;
            if (isset($r['extracted_price']) && is_numeric($r['extracted_price'])) {
                $price = (float) $r['extracted_price'];
            } elseif (isset($r['price']) && is_string($r['price'])) {
                // Fallback : parse string genre "79,90 €" ou "€79.90"
                $clean = preg_replace('/[^\d.,]/', '', $r['price']);
                if ($clean !== '' && $clean !== null) {
                    $clean = str_replace(',', '.', (string) $clean);
                    // S'il reste plusieurs points (séparateur de milliers), garde le dernier comme décimal
                    $lastDot = strrpos($clean, '.');
                    if ($lastDot !== false) {
                        $intPart = str_replace('.', '', substr($clean, 0, $lastDot));
                        $clean = $intPart . '.' . substr($clean, $lastDot + 1);
                    }
                    if (is_numeric($clean)) $price = (float) $clean;
                }
            }

            $results[] = [
                'site' => (string) ($r['source'] ?? '—'),
                'name' => (string) ($r['title'] ?? ''),
                'price_eur' => $price,
                'url' => isset($r['product_link']) && is_string($r['product_link']) ? $r['product_link']
                        : (isset($r['link']) && is_string($r['link']) ? $r['link'] : null),
                'in_stock' => null, // SerpApi ne renvoie pas de stock fiable
                'notes' => isset($r['delivery']) && is_string($r['delivery']) ? $r['delivery'] : null,
                'thumbnail' => isset($r['thumbnail']) && is_string($r['thumbnail']) ? $r['thumbnail'] : null,
                'rating' => isset($r['rating']) && is_numeric($r['rating']) ? (float) $r['rating'] : null,
                'reviews_count' => isset($r['reviews']) && is_numeric($r['reviews']) ? (int) $r['reviews'] : null,
            ];
            $foundCount++;
        }

        // Stats sur les prix non null
        $valid = array_values(array_filter(array_column($results, 'price_eur'), fn($p) => $p !== null && $p > 0));
        sort($valid);
        $avg = !empty($valid) ? array_sum($valid) / count($valid) : null;
        $min = !empty($valid) ? $valid[0] : null;
        $max = !empty($valid) ? end($valid) : null;
        $median = null;
        if (!empty($valid)) {
            $n = count($valid);
            $median = ($n % 2 === 1)
                ? $valid[(int) ($n / 2)]
                : ($valid[$n / 2 - 1] + $valid[$n / 2]) / 2;
        }

        // Résumé textuel auto (pas d'IA)
        $summary = $this->buildSummary($currentPriceTTC, $avg, $min, $max, count($results));

        return [
            'results' => $results,
            'summary' => $summary,
            'avg_price_eur' => $avg,
            'min_price_eur' => $min,
            'max_price_eur' => $max,
            'median_price_eur' => $median,
            'search_query' => $query,
            'search_count' => count($results),
        ];
    }

    private function buildSummary(float $currentPrice, ?float $avg, ?float $min, ?float $max, int $foundCount): string
    {
        if ($foundCount === 0) {
            return 'Aucun résultat trouvé sur Google Shopping France pour ce produit. Essaye avec un nom plus générique ou vérifie l\'orthographe.';
        }
        if ($avg === null) {
            return $foundCount . ' résultat' . ($foundCount > 1 ? 's' : '') . ' trouvé' . ($foundCount > 1 ? 's' : '') . ' mais aucun avec un prix exploitable.';
        }

        $diff = $currentPrice - $avg;
        $diffPct = ($avg > 0) ? ($diff / $avg) * 100 : 0;

        $position = match (true) {
            $diffPct >= 15 => 'nettement plus cher que la moyenne marché (+' . number_format($diffPct, 1, ',', ' ') . ' %)',
            $diffPct >= 5  => 'plus cher que la moyenne marché (+' . number_format($diffPct, 1, ',', ' ') . ' %)',
            $diffPct >= -5 => 'dans la moyenne marché (' . ($diff >= 0 ? '+' : '') . number_format($diffPct, 1, ',', ' ') . ' %)',
            $diffPct >= -15 => 'moins cher que la moyenne marché (' . number_format($diffPct, 1, ',', ' ') . ' %)',
            default => 'nettement moins cher que la moyenne marché (' . number_format($diffPct, 1, ',', ' ') . ' %)',
        };

        return sprintf(
            'Sur %d résultats Google Shopping France, ton produit est %s. Fourchette concurrentielle : %s €  →  %s €.',
            $foundCount,
            $position,
            number_format((float) $min, 2, ',', ' '),
            number_format((float) $max, 2, ',', ' '),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function httpGetJson(string $url): array
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'PIM-Musculation/0.1',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
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
        $rawBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($rawBody === false) {
            throw new RuntimeException('Erreur réseau SerpApi : ' . $error);
        }
        if ($httpCode === 401 || $httpCode === 403) {
            throw new RuntimeException('Clé SerpApi invalide ou expirée (HTTP ' . $httpCode . '). Vérifie dans Paramètres → Outils IA.');
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $snippet = mb_substr((string) $rawBody, 0, 500);
            throw new RuntimeException("HTTP {$httpCode} SerpApi : {$snippet}");
        }

        $decoded = json_decode((string) $rawBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Réponse SerpApi JSON invalide.');
        }
        return $decoded;
    }
}
