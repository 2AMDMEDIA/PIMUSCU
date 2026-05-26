<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Helpers\Csrf;
use App\Helpers\JsonRescue;
use App\Middleware\Auth;
use App\Repositories\ClientEditorialRepository;
use App\Repositories\PrestaProductRepository;
use App\Services\AiService;
use App\Services\ClientResolver;
use App\Services\ReviewsClient;
use App\Services\ReviewsPromptBuilder;

final class AvisController extends BaseController
{
    /**
     * Liste des produits avec note moyenne + nombre d'avis.
     */
    public function index(): void
    {
        Auth::require();
        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->redirect('/dashboard');
        }

        $service = new ReviewsClient($client);
        if (!$service->isConfigured()) {
            $this->renderApp('pages.avis.no_config', [], [
                'active' => 'avis',
                'page_title' => 'Avis Produit',
            ]);
            return;
        }

        $error = null;
        $stats = [];
        try {
            $stats = $service->fetchStatsByProduct();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        // Récupère les produits qui ont des avis, joints aux infos locales (nom, image, prix)
        $products = [];
        if ($stats !== []) {
            $ids = array_keys($stats);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo = Database::pdo();
            $sql = "SELECT id, presta_id, name, reference, price, image_url
                      FROM presta_products
                     WHERE client_id = ? AND presta_id IN ($placeholders)
                     ORDER BY name ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$client->id], $ids));
            foreach ($stmt->fetchAll() as $row) {
                $prestaId = (int) $row['presta_id'];
                $row['count'] = $stats[$prestaId]['count'] ?? 0;
                $row['avg_grade'] = $stats[$prestaId]['avg_grade'] ?? 0;
                $products[] = $row;
            }
            // Tri par note moyenne décroissante, puis nb avis
            usort($products, function ($a, $b) {
                if ($a['avg_grade'] != $b['avg_grade']) {
                    return $b['avg_grade'] <=> $a['avg_grade'];
                }
                return $b['count'] <=> $a['count'];
            });
        }

        $totalReviews = array_sum(array_map(fn ($p) => $p['count'], $products));

        $this->renderApp('pages.avis.index', [
            'products' => $products,
            'total_reviews' => $totalReviews,
            'error' => $error,
        ], [
            'active' => 'avis',
            'page_title' => 'Avis Produit',
        ]);
    }

    /**
     * Détail d'un produit : photo + nom + liste des avis.
     */
    public function product(string $id): void
    {
        Auth::require();
        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->redirect('/dashboard');
        }

        $row = (new PrestaProductRepository())->findById($client->id, $id);
        if ($row === null) {
            http_response_code(404);
            $this->renderApp('pages.errors.404', ['title' => 'Produit introuvable'], [
                'active' => 'avis',
                'page_title' => 'Produit introuvable',
            ]);
            return;
        }

        $reviews = [];
        $error = null;
        try {
            $reviews = (new ReviewsClient($client))->fetchReviewsForProduct((int) $row['presta_id']);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        // Calcul des stats locales sur la liste retournée
        $count = count($reviews);
        $validCount = count(array_filter($reviews, fn ($r) => $r['validate'] === 1));
        $avg = $count > 0
            ? round(array_sum(array_map(fn ($r) => $r['grade'], $reviews)) / $count, 1)
            : 0.0;

        $this->renderApp('pages.avis.product', [
            'product' => $row,
            'reviews' => $reviews,
            'count' => $count,
            'valid_count' => $validCount,
            'avg' => $avg,
            'error' => $error,
        ], [
            'active' => 'avis',
            'page_title' => (string) $row['name'],
        ]);
    }

    /**
     * Génère N avis réalistes par IA + les pousse sur PrestaShop via api_reviews.php.
     * Endpoint AJAX (réponse JSON).
     */
    public function generateReviews(string $id): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->json(['ok' => false, 'message' => 'Aucun client actif.'], 400);
        }

        $row = (new PrestaProductRepository())->findById($client->id, $id);
        if ($row === null) {
            $this->json(['ok' => false, 'message' => 'Produit introuvable.'], 404);
        }

        $reviewsService = new ReviewsClient($client);
        if (!$reviewsService->isConfigured()) {
            $this->json([
                'ok' => false,
                'message' => 'Clé API Avis non configurée. Allez dans Paramètres → PrestaShop.',
            ], 400);
        }

        $count = (int) ($this->input('count') ?? '5');
        if ($count < 1) { $count = 1; }
        if ($count > 20) { $count = 20; }
        $instructions = $this->input('instructions') ?? '';

        // Bornes de dates pour les avis générés (défaut : 6 derniers mois)
        $dateFrom = $this->parseDate($this->input('date_from'), '-180 days');
        $dateTo = $this->parseDate($this->input('date_to'), 'now');
        if ($dateFrom > $dateTo) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }

        // Note moyenne cible (1.0 - 5.0) — si fournie, on overrideles grades du LLM pour matcher exactement
        $targetAvgRaw = $this->input('target_avg');
        $targetAvg = $targetAvgRaw !== null && $targetAvgRaw !== '' ? (float) $targetAvgRaw : null;
        if ($targetAvg !== null) {
            $targetAvg = max(1.0, min(5.0, $targetAvg));
        }
        $forcedGrades = $targetAvg !== null ? $this->distributeGrades($count, $targetAvg) : null;

        $editorial = (new ClientEditorialRepository())->get($client->id);

        $description = trim((string) ($row['description_short'] ?? '')) !== ''
            ? (string) $row['description_short']
            : (string) ($row['description'] ?? '');

        $prompts = (new ReviewsPromptBuilder($editorial))->build(
            productName: (string) $row['name'],
            productDescription: $description,
            count: $count,
            userInstructions: $instructions,
        );

        try {
            $result = (new AiService($client))->generate([
                'system_prompt' => $prompts['system_prompt'],
                'user_prompt' => $prompts['user_prompt'],
                'max_tokens' => 3000,
                'temperature' => 0.9, // plus de variété
                'json_mode' => true,
                'entity_type' => 'reviews',
                'entity_id' => $row['id'],
            ]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $payload = JsonRescue::decode($result['text']);
        if ($payload === null || !isset($payload['reviews']) || !is_array($payload['reviews'])) {
            $this->json([
                'ok' => false,
                'message' => 'Le modèle a renvoyé une réponse non parsable.',
                'raw' => mb_substr($result['text'], 0, 2000),
            ], 502);
        }

        // Prépare le payload pour api_reviews.php :
        // - dates aléatoires entre dateFrom et dateTo (uniformes)
        // - grades : soit ceux du LLM, soit la distribution forcée par target_avg
        $prestaId = (int) $row['presta_id'];
        $batch = [];
        $fromTs = $dateFrom->getTimestamp();
        $toTs = $dateTo->getTimestamp();
        $rangeSec = max(0, $toTs - $fromTs);

        foreach ($payload['reviews'] as $i => $r) {
            $randomTs = $fromTs + ($rangeSec > 0 ? random_int(0, $rangeSec) : 0);
            $grade = $forcedGrades !== null && isset($forcedGrades[$i])
                ? $forcedGrades[$i]
                : (int) max(1, min(5, (int) ($r['grade'] ?? 5)));
            $batch[] = [
                'id_product' => $prestaId,
                'customer_name' => (string) ($r['customer_name'] ?? 'Anonyme'),
                'title' => (string) ($r['title'] ?? ''),
                'content' => (string) ($r['content'] ?? ''),
                'grade' => $grade,
                'validate' => 1,
                'date_add' => date('Y-m-d H:i:s', $randomTs),
            ];
        }

        try {
            $pushResult = $reviewsService->pushReviews($batch);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'message' => 'Erreur push avis : ' . $e->getMessage()], 502);
        }

        // Refresh des stats avis stockées en DB pour ce client (pour que la liste produits + la page détail
        // affichent les nouveaux nombres au prochain chargement)
        try {
            $freshStats = $reviewsService->fetchStatsByProduct();
            (new PrestaProductRepository())->applyReviewsStats($client->id, $freshStats);
            $thisProductStats = $freshStats[$prestaId] ?? ['count' => 0, 'avg_grade' => 0];
        } catch (\Throwable) {
            $thisProductStats = ['count' => 0, 'avg_grade' => 0];
        }

        $this->json([
            'ok' => true,
            'inserted' => $pushResult['inserted'],
            'updated' => $pushResult['updated'],
            'errors' => $pushResult['errors'],
            'new_stats' => $thisProductStats,
            'usage' => [
                'model' => $result['model'],
                'prompt_tokens' => $result['prompt_tokens'],
                'completion_tokens' => $result['completion_tokens'],
                'cost_eur' => $result['cost_eur'],
            ],
        ]);
    }

    /**
     * Parse un input date (YYYY-MM-DD) en DateTimeImmutable. Fallback sur $default.
     */
    private function parseDate(?string $raw, string $default): \DateTimeImmutable
    {
        if ($raw !== null && trim($raw) !== '') {
            try {
                $dt = new \DateTimeImmutable(trim($raw));
                return $dt;
            } catch (\Throwable) {
                // fallback
            }
        }
        return new \DateTimeImmutable($default);
    }

    /**
     * Distribue $count notes entre 1 et 5 dont la moyenne est très proche de $targetAvg.
     * Algo : base = floor(targetAvg), on incrémente le reste pour matcher la somme cible, puis shuffle.
     *
     * Exemples :
     *   distributeGrades(5, 4.5)  → [5,4,5,4,5]  (somme=23 → moy 4.6) ou [4,5,5,4,5] selon shuffle
     *   distributeGrades(10, 4.2) → 2×5 + 8×4   (somme=42 → moy 4.2)
     *   distributeGrades(3, 3.0)  → 3×3        (somme=9 → moy 3.0)
     *
     * @return list<int>
     */
    private function distributeGrades(int $count, float $targetAvg): array
    {
        $targetAvg = max(1.0, min(5.0, $targetAvg));
        $targetSum = (int) round($count * $targetAvg);
        $targetSum = max($count, min($count * 5, $targetSum));

        $base = intdiv($targetSum, $count);
        $remainder = $targetSum - $base * $count;
        if ($base + 1 > 5) {
            // edge case : que des 5
            return array_fill(0, $count, 5);
        }

        $grades = array_merge(
            array_fill(0, $count - $remainder, $base),
            array_fill(0, $remainder, $base + 1),
        );
        shuffle($grades);
        return $grades;
    }

    /**
     * Met à jour un avis existant. Endpoint AJAX JSON.
     */
    public function updateReview(string $productId, string $reviewId): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->json(['ok' => false, 'message' => 'Aucun client actif.'], 400);
        }

        $service = new ReviewsClient($client);
        if (!$service->isConfigured()) {
            $this->json(['ok' => false, 'message' => 'Clé API Avis non configurée.'], 400);
        }

        $fields = [];
        foreach (['customer_name', 'title', 'content', 'grade', 'validate'] as $f) {
            $v = $this->input($f);
            if ($v !== null) {
                $fields[$f] = $f === 'grade' || $f === 'validate' ? (int) $v : $v;
            }
        }

        if ($fields === []) {
            $this->json(['ok' => false, 'message' => 'Aucun champ à mettre à jour.'], 400);
        }

        try {
            $service->updateReview((int) $reviewId, $fields);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $this->json(['ok' => true]);
    }

    /**
     * Supprime un avis (soft delete par défaut). Endpoint AJAX JSON.
     */
    public function deleteReview(string $productId, string $reviewId): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->json(['ok' => false, 'message' => 'Aucun client actif.'], 400);
        }

        $service = new ReviewsClient($client);
        if (!$service->isConfigured()) {
            $this->json(['ok' => false, 'message' => 'Clé API Avis non configurée.'], 400);
        }

        $hard = $this->inputBool('hard');

        try {
            $service->deleteReview((int) $reviewId, $hard);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $this->json(['ok' => true]);
    }
}
