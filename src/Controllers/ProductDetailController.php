<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Helpers\JsonRescue;
use App\Helpers\NutrifactsRenderer;
use App\Middleware\Auth;
use App\Repositories\ClientEditorialRepository;
use App\Repositories\ClientFieldInstructionsRepository;
use App\Repositories\GeneratedImageRepository;
use App\Repositories\PrestaProductCombinationRepository;
use App\Repositories\PrestaProductRepository;
use App\Repositories\NutriwebCatalogRepository;
use App\Repositories\ProductPriceAnalysisRepository;
use App\Services\AiService;
use App\Services\AwCpfClient;
use App\Services\ClientResolver;
use App\Services\KieClient;
use App\Services\NutriwebClient;
use App\Services\PriceComparator;
use App\Services\PrestaShopClient;
use App\Services\ProductPromptBuilder;

final class ProductDetailController extends BaseController
{
    public function show(string $id): void
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
                'active' => 'produits',
                'page_title' => 'Produit introuvable',
            ]);
            return;
        }

        $shopUrl = rtrim($client->prestashopUrl, '/');
        $linkRewrite = (string) ($row['link_rewrite'] ?? '');
        $externalUrl = $linkRewrite !== '' ? $shopUrl . '/' . $row['presta_id'] . '-' . $linkRewrite . '.html' : null;

        // Promos actives : lues depuis la colonne active_promos_json (peuplée au
        // sync produits via applyActivePromos). Aucun appel API live.
        $activePromos = [];
        if (!empty($row['active_promos_json'])) {
            $decoded = json_decode((string) $row['active_promos_json'], true);
            if (is_array($decoded)) $activePromos = $decoded;
        }

        // Galerie : lue depuis la colonne image_ids (CSV persistante). Cache lazy :
        // si NULL et clé API dispo → fetch live UNE fois + save en DB → instant après.
        // Force refresh : ?refresh=1 dans l'URL.
        $galleryImages = [];
        $prestaProductId = (int) $row['presta_id'];
        $forceRefresh = $this->input('refresh') === '1';
        $imageIdsCsv = $forceRefresh ? null : ($row['image_ids'] ?? null);
        $imageIds = null;
        if ($imageIdsCsv !== null && $imageIdsCsv !== '') {
            $imageIds = array_values(array_filter(array_map('intval', explode(',', (string) $imageIdsCsv)), fn($i) => $i > 0));
        } elseif ($client->prestashopApiKeyEncrypted !== null) {
            // Premier chargement (ou refresh forcé) : fetch + persist.
            try {
                $service = new PrestaShopClient($client);
                $imageIds = $service->fetchProductImageIds($prestaProductId);
                (new PrestaProductRepository())->saveImageIds($client->id, $prestaProductId, $imageIds);
            } catch (\Throwable) {
                $imageIds = null;
            }
        }
        if ($imageIds !== null && $client->prestashopApiKeyEncrypted !== null) {
            $service = new PrestaShopClient($client);
            foreach ($imageIds as $imageId) {
                $galleryImages[] = [
                    'id' => $imageId,
                    'thumb_url' => $service->buildProductImageUrl($imageId, $linkRewrite, 'medium_default'),
                    'large_url' => $service->buildProductImageUrl($imageId, $linkRewrite, 'large_default'),
                ];
            }
        }

        $combinations = (new PrestaProductCombinationRepository())
            ->listForProduct($client->id, (int) $row['presta_id']);

        $generations = (new GeneratedImageRepository())->listForProduct($client->id, (string) $row['id'], 20);
        $kieConfigured = (new KieClient($client))->isConfigured();

        // Derniere etude de prix SerpApi (si deja faite)
        $priceAnalysis = (new ProductPriceAnalysisRepository())->findLatest($client->id, (int) $row['presta_id']);

        $this->renderApp('pages.produits.detail', [
            'row' => $row,
            'external_url' => $externalUrl,
            'gallery_images' => $galleryImages,
            'combinations' => $combinations,
            'generations' => $generations,
            'kie_configured' => $kieConfigured,
            'price_analysis' => $priceAnalysis,
            'active_promos' => $activePromos,
        ], [
            'active' => 'produits',
            'page_title' => (string) $row['name'],
        ]);
    }

    /**
     * Prix effectif TTC du produit (price HT * 1.20). Helper centralise pour
     * l'etude de prix. (La promo flash, si re-activee, pourra raffiner ici.)
     */
    private function computeEffectivePriceTTC(array $row): float
    {
        return (float) ($row['price'] ?? 0) * 1.20;
    }

    /**
     * POST AJAX : etude de prix concurrentielle via SerpApi (Google Shopping FR).
     */
    public function comparePrices(string $id): void
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

        $priceTTC = $this->computeEffectivePriceTTC($row);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        try {
            $result = (new PriceComparator($client))->compare(
                productName: (string) $row['name'],
                reference: (string) ($row['reference'] ?? ''),
                currentPriceTTC: $priceTTC,
            );
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $analysisId = null;
        try {
            $analysisId = (new ProductPriceAnalysisRepository())->create(
                clientId: $client->id,
                prestaProductId: (int) $row['presta_id'],
                searchQuery: $result['search_query'],
                currentPriceTTC: $priceTTC,
                avg: $result['avg_price_eur'],
                min: $result['min_price_eur'],
                max: $result['max_price_eur'],
                median: $result['median_price_eur'],
                foundCount: count($result['results']),
                summary: $result['summary'],
                results: $result['results'],
            );
        } catch (\Throwable $e) {
            error_log('Save price analysis failed: ' . $e->getMessage());
        }

        $this->json([
            'ok' => true,
            'analysis_id' => $analysisId,
            'results' => $result['results'],
            'summary' => $result['summary'],
            'stats' => [
                'avg_price_eur' => $result['avg_price_eur'],
                'min_price_eur' => $result['min_price_eur'],
                'max_price_eur' => $result['max_price_eur'],
                'median_price_eur' => $result['median_price_eur'],
                'current_price_ttc' => $priceTTC,
                'found_count' => count($result['results']),
            ],
            'search_query' => $result['search_query'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Generation d'images IA (Kie.AI Nano Banana 2)
    // -------------------------------------------------------------------------

    /**
     * POST AJAX : soumet une generation image. Renvoie generation_id + task_id.
     * Le JS polle ensuite /produits/{id}/images/{generation_id}/status.
     */
    public function generateImage(string $id): void
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

        $prompt = trim((string) ($this->input('prompt') ?? ''));
        if ($prompt === '') {
            $this->json(['ok' => false, 'message' => 'Prompt obligatoire.'], 400);
        }

        // Recup images source : array d'IDs Presta envoye par le JS depuis la galerie
        $requestedIds = $_POST['image_ids'] ?? [];
        if (!is_array($requestedIds)) {
            $requestedIds = [];
        }

        $service = new PrestaShopClient($client);
        if (!$service->isConfigured()) {
            $this->json(['ok' => false, 'message' => 'Clé API PrestaShop non configurée.'], 400);
        }

        try {
            $imageIds = $service->fetchProductImageIds((int) $row['presta_id']);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'message' => 'Récupération images : ' . $e->getMessage()], 500);
        }
        if (empty($imageIds)) {
            $this->json(['ok' => false, 'message' => 'Aucune image source sur ce produit côté Presta.'], 400);
        }

        $chosenIds = [];
        foreach ($requestedIds as $rawId) {
            $i = (int) $rawId;
            if ($i > 0 && in_array($i, $imageIds, true) && !in_array($i, $chosenIds, true)) {
                $chosenIds[] = $i;
            }
        }
        if ($chosenIds === []) {
            $chosenIds = [$imageIds[0]];
        }
        if (count($chosenIds) > 5) {
            $chosenIds = array_slice($chosenIds, 0, 5);
        }

        $linkRewrite = (string) ($row['link_rewrite'] ?? '');
        $inputUrls = array_map(
            fn(int $imgId) => $service->buildProductImageUrl($imgId, $linkRewrite, 'large_default'),
            $chosenIds,
        );

        $kie = new KieClient($client);
        if (!$kie->isConfigured()) {
            $this->json(['ok' => false, 'message' => 'Clé API Kie.AI manquante. Va dans Paramètres → Outils IA.'], 400);
        }

        $augmented = count($inputUrls) > 1
            ? 'Generate a new product photo variation based on the ' . count($inputUrls) . ' reference product images. ' . $prompt
            : 'Generate a new product photo variation based on the reference product image. ' . $prompt;

        try {
            $sub = $kie->submitGeneration($augmented, 1080, 1080, 'nano-banana-2', $inputUrls);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $genId = (new GeneratedImageRepository())->create(
            clientId: $client->id,
            productId: (string) $row['id'],
            prestaProductId: (int) $row['presta_id'],
            prompt: $prompt,
            inputUrls: $inputUrls,
            model: $sub['model'],
            taskId: $sub['task_id'],
        );

        $this->json([
            'ok' => true,
            'generation_id' => $genId,
            'task_id' => $sub['task_id'],
            'source_image_ids' => $chosenIds,
        ]);
    }

    /**
     * GET AJAX : statut d'une generation. Met a jour la DB si l'etat Kie.AI a change.
     */
    public function imageStatus(string $id, string $generationId): void
    {
        Auth::require();

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->json(['ok' => false, 'state' => 'error', 'message' => 'Aucun client actif.'], 400);
        }

        $repo = new GeneratedImageRepository();
        $gen = $repo->findById($client->id, $generationId);
        if ($gen === null || (string) $gen['product_id'] !== $id) {
            $this->json(['ok' => false, 'state' => 'error', 'message' => 'Generation introuvable.'], 404);
        }

        // Si deja terminee, pas besoin de re-poll Kie.AI
        if ($gen['status'] !== 'pending') {
            $this->json([
                'ok' => true,
                'state' => $gen['status'],
                'image_url' => $gen['image_url'],
                'error' => $gen['error_message'],
            ]);
        }

        $kie = new KieClient($client);
        try {
            $poll = $kie->pollStatus((string) $gen['task_id']);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'state' => 'error', 'message' => $e->getMessage()], 500);
        }

        if ($poll['state'] === 'success') {
            $repo->updateStatus($client->id, $generationId, 'success', $poll['image_url']);
        } elseif ($poll['state'] === 'error') {
            $repo->updateStatus($client->id, $generationId, 'error', null, $poll['error']);
        }

        $this->json([
            'ok' => true,
            'state' => $poll['state'],
            'image_url' => $poll['image_url'],
            'error' => $poll['error'],
        ]);
    }

    /**
     * POST AJAX : raffine une image existante (nouvelle prompt, meme image source).
     */
    public function refineImage(string $id, string $generationId): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->json(['ok' => false, 'message' => 'Aucun client actif.'], 400);
        }

        $repo = new GeneratedImageRepository();
        $source = $repo->findById($client->id, $generationId);
        if ($source === null || (string) $source['product_id'] !== $id) {
            $this->json(['ok' => false, 'message' => 'Generation source introuvable.'], 404);
        }
        if ($source['status'] !== 'success' || empty($source['image_url'])) {
            $this->json(['ok' => false, 'message' => 'La generation source n\'a pas d\'image.'], 400);
        }

        $prompt = trim((string) ($this->input('prompt') ?? ''));
        if ($prompt === '') {
            $this->json(['ok' => false, 'message' => 'Prompt obligatoire.'], 400);
        }

        $kie = new KieClient($client);
        if (!$kie->isConfigured()) {
            $this->json(['ok' => false, 'message' => 'Clé API Kie.AI manquante.'], 400);
        }

        // Refinement : on prend l'image generee comme seule input + le nouveau prompt
        $inputUrls = [(string) $source['image_url']];
        $augmented = 'Modify only the following detail of the image, keep everything else identical: ' . $prompt;

        try {
            $sub = $kie->submitGeneration($augmented, 1080, 1080, 'nano-banana-2', $inputUrls);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $newGenId = $repo->create(
            clientId: $client->id,
            productId: (string) $source['product_id'],
            prestaProductId: (int) $source['presta_product_id'],
            prompt: '[Refinement] ' . $prompt,
            inputUrls: $inputUrls,
            model: $sub['model'],
            taskId: $sub['task_id'],
            parentGenerationId: (string) $source['id'],
        );

        $this->json([
            'ok' => true,
            'generation_id' => $newGenId,
            'task_id' => $sub['task_id'],
        ]);
    }

    /**
     * POST AJAX : ajoute l'image generee a la galerie Presta du produit.
     */
    public function addImageToGallery(string $id, string $generationId): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->json(['ok' => false, 'message' => 'Aucun client actif.'], 400);
        }

        $repo = new GeneratedImageRepository();
        $gen = $repo->findById($client->id, $generationId);
        if ($gen === null || (string) $gen['product_id'] !== $id) {
            $this->json(['ok' => false, 'message' => 'Generation introuvable.'], 404);
        }
        if ($gen['status'] !== 'success' || empty($gen['image_url'])) {
            $this->json(['ok' => false, 'message' => 'Pas d\'image à pousser (statut : ' . $gen['status'] . ').'], 400);
        }

        $row = (new PrestaProductRepository())->findById($client->id, $id);
        if ($row === null) {
            $this->json(['ok' => false, 'message' => 'Produit introuvable.'], 404);
        }

        $service = new PrestaShopClient($client);
        try {
            $newImageId = $service->uploadProductImageFromUrl((int) $row['presta_id'], (string) $gen['image_url']);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'message' => 'Upload Presta echec : ' . $e->getMessage()], 502);
        }

        $repo->markPushedToGallery($client->id, $generationId, $newImageId);
        self::invalidateProductLiveCache($client->id, (int) $row['presta_id']);

        $this->json([
            'ok' => true,
            'image_id' => $newImageId,
            'message' => 'Image ajoutee a la galerie Presta (id ' . $newImageId . ').',
        ]);
    }

    /**
     * POST : supprime une generation de l'historique (cache uniquement, ne touche pas Presta).
     */
    public function deleteImage(string $id, string $generationId): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->redirect('/dashboard');
        }
        (new GeneratedImageRepository())->delete($client->id, $generationId);
        $this->flashSuccess('Generation supprimee de l\'historique.');
        $this->redirect('/produits/' . urlencode($id));
    }

    // -------------------------------------------------------------------------
    // Promo flash (specific_price PrestaShop)
    // -------------------------------------------------------------------------

    public function createFlashPromo(string $id): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->redirect('/dashboard');
        }

        $row = (new PrestaProductRepository())->findById($client->id, $id);
        if ($row === null) {
            http_response_code(404);
            echo 'Produit introuvable';
            return;
        }

        $newPriceTTC = $this->input('new_price_ttc');
        $discountPct = $this->input('discount_pct');
        $dateFrom = $this->input('date_from');
        $dateTo = $this->input('date_to');

        if ($dateFrom === null || $dateTo === null) {
            $this->flashError('Les dates Du et Au sont obligatoires.');
            $this->redirect('/produits/' . $id);
        }
        $tsFrom = strtotime($dateFrom);
        $tsTo = strtotime($dateTo);
        if ($tsFrom === false || $tsTo === false) {
            $this->flashError('Format de date invalide.');
            $this->redirect('/produits/' . $id);
        }
        if ($tsTo < $tsFrom) {
            $this->flashError('La date "Au" doit être postérieure ou égale à la date "Du".');
            $this->redirect('/produits/' . $id);
        }

        $priceTTC = (float) $row['price'] * 1.20;
        $reductionType = null;
        $reductionValue = null;

        if ($newPriceTTC !== null && $newPriceTTC !== '') {
            $newPriceFloat = (float) str_replace(',', '.', $newPriceTTC);
            if ($newPriceFloat <= 0 || $newPriceFloat >= $priceTTC) {
                $this->flashError('Le nouveau prix doit être positif et inférieur au prix actuel (' . number_format($priceTTC, 2, ',', ' ') . ' € TTC).');
                $this->redirect('/produits/' . $id);
            }
            $reductionValue = $priceTTC - $newPriceFloat;
            $reductionType = 'amount';
        } elseif ($discountPct !== null && $discountPct !== '') {
            $pctFloat = (float) str_replace(',', '.', $discountPct);
            if ($pctFloat <= 0 || $pctFloat >= 100) {
                $this->flashError('La remise doit être comprise entre 1 et 99 %.');
                $this->redirect('/produits/' . $id);
            }
            $reductionValue = round($pctFloat / 100, 6);
            $reductionType = 'percentage';
        } else {
            $this->flashError('Saisissez un nouveau prix TTC OU une remise %.');
            $this->redirect('/produits/' . $id);
        }

        $service = new PrestaShopClient($client);
        if (!$service->isConfigured()) {
            $this->flashError('Clé API PrestaShop non configurée.');
            $this->redirect('/settings?tab=prestashop');
        }

        try {
            $existing = $service->listSpecificPricesForProduct((int) $row['presta_id']);
            foreach ($existing as $promo) {
                $service->deleteSpecificPrice($promo['id']);
            }
            $service->createSpecificPrice([
                'id_product' => (int) $row['presta_id'],
                'reduction' => (float) $reductionValue,
                'reduction_type' => $reductionType,
                'from' => date('Y-m-d 00:00:00', $tsFrom),
                'to' => date('Y-m-d 23:59:59', $tsTo),
            ]);
        } catch (\Throwable $e) {
            $this->flashError('Échec création promo : ' . $e->getMessage());
            $this->redirect('/produits/' . $id);
        }

        $label = $reductionType === 'amount'
            ? '-' . number_format((float) $reductionValue, 2, ',', ' ') . ' € TTC'
            : '-' . number_format((float) $reductionValue * 100, 1, ',', ' ') . ' %';
        self::invalidateProductLiveCache($client->id, (int) $row['presta_id']);
        $this->flashSuccess('Promo flash créée ' . $label . ' du ' . date('d/m/Y', $tsFrom) . ' au ' . date('d/m/Y', $tsTo) . '.');
        $this->redirect('/produits/' . $id);
    }

    /** Supprime toutes les promos actives sur ce produit. */
    public function deleteFlashPromo(string $id): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->redirect('/dashboard');
        }

        $row = (new PrestaProductRepository())->findById($client->id, $id);
        if ($row === null) {
            http_response_code(404);
            echo 'Produit introuvable';
            return;
        }

        try {
            $service = new PrestaShopClient($client);
            $existing = $service->listSpecificPricesForProduct((int) $row['presta_id']);
            foreach ($existing as $promo) {
                $service->deleteSpecificPrice($promo['id']);
            }
            self::invalidateProductLiveCache($client->id, (int) $row['presta_id']);
            $this->flashSuccess(count($existing) . ' promo(s) supprimée(s).');
        } catch (\Throwable $e) {
            $this->flashError('Échec suppression : ' . $e->getMessage());
        }

        $this->redirect('/produits/' . $id);
    }

    public function saveOptimized(string $id): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->redirect('/dashboard');
        }

        $repo = new PrestaProductRepository();
        if ($repo->findById($client->id, $id) === null) {
            http_response_code(404);
            echo 'Produit introuvable';
            return;
        }

        $repo->saveOptimized(
            clientId: $client->id,
            id: $id,
            descriptionShort: $this->emptyToNull($this->input('optimized_description_short')),
            description: $this->emptyToNull($this->input('optimized_description')),
            metaTitle: $this->emptyToNull($this->input('optimized_meta_title')),
            metaDescription: $this->emptyToNull($this->input('optimized_meta_description')),
        );

        $this->flashSuccess('Version optimisée enregistrée.');
        $this->redirect('/produits/' . $id);
    }

    public function push(string $id): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->redirect('/dashboard');
        }

        $repo = new PrestaProductRepository();
        $row = $repo->findById($client->id, $id);
        if ($row === null) {
            http_response_code(404);
            echo 'Produit introuvable';
            return;
        }

        // Save first
        $optDescShort = $this->emptyToNull($this->input('optimized_description_short'));
        $optDesc = $this->emptyToNull($this->input('optimized_description'));
        $optMetaTitle = $this->emptyToNull($this->input('optimized_meta_title'));
        $optMetaDesc = $this->emptyToNull($this->input('optimized_meta_description'));
        $optMetaKw = $this->emptyToNull($this->input('optimized_meta_keywords'));
        $repo->saveOptimized($client->id, $id, $optDescShort, $optDesc, $optMetaTitle, $optMetaDesc, $optMetaKw);

        // Une checkbox par champ : génération IA + push contrôlés par la même case.
        $fieldsToPush = [];
        if ($this->inputBool('enabled_description_short') && $optDescShort !== null) {
            $fieldsToPush['description_short'] = $optDescShort;
        }
        if ($this->inputBool('enabled_description') && $optDesc !== null) {
            $fieldsToPush['description'] = $optDesc;
        }
        if ($this->inputBool('enabled_meta_title') && $optMetaTitle !== null) {
            $fieldsToPush['meta_title'] = $optMetaTitle;
        }
        if ($this->inputBool('enabled_meta_description') && $optMetaDesc !== null) {
            $fieldsToPush['meta_description'] = $optMetaDesc;
        }
        if ($this->inputBool('enabled_meta_keywords') && $optMetaKw !== null) {
            $fieldsToPush['meta_keywords'] = $optMetaKw;
        }

        if ($fieldsToPush === []) {
            $this->flashError('Aucun champ à pousser (cochez au moins une case et remplissez la version optimisée).');
            $this->redirect('/produits/' . $id);
        }

        $service = new PrestaShopClient($client);
        if (!$service->isConfigured()) {
            $this->flashError('Clé API PrestaShop non configurée.');
            $this->redirect('/settings?tab=prestashop');
        }

        try {
            $service->updateProductFields((int) $row['presta_id'], $fieldsToPush);
        } catch (\Throwable $e) {
            $this->flashError('Échec du push PrestaShop : ' . $e->getMessage());
            $this->redirect('/produits/' . $id);
        }

        $repo->applyAfterPush(
            $client->id,
            $id,
            $fieldsToPush['description_short'] ?? null,
            $fieldsToPush['description'] ?? null,
            $fieldsToPush['meta_title'] ?? null,
            $fieldsToPush['meta_description'] ?? null,
            $fieldsToPush['meta_keywords'] ?? null,
        );

        $count = count($fieldsToPush);
        $this->flashSuccess($count . ' champ' . ($count > 1 ? 's' : '') . ' poussé' . ($count > 1 ? 's' : '') . ' sur PrestaShop.');
        $this->redirect('/produits/' . $id);
    }

    public function generate(string $id): void
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

        $userInstructions = $this->input('instructions') ?? '';
        $wordCount = (int) ($this->input('word_count') ?? '300');
        if ($wordCount < 50) { $wordCount = 50; }
        if ($wordCount > 1500) { $wordCount = 1500; }

        $editorial = (new ClientEditorialRepository())->get($client->id);
        $fieldInstructions = (new ClientFieldInstructionsRepository())->getForEntity($client->id, 'product');

        $prompts = (new ProductPromptBuilder($editorial))->build(
            productName: (string) $row['name'],
            reference: (string) ($row['reference'] ?? ''),
            price: (float) $row['price'],
            currentDescription: (string) ($row['description'] ?? ''),
            currentDescriptionShort: (string) ($row['description_short'] ?? ''),
            userInstructions: $userInstructions,
            wordCount: $wordCount,
            fieldInstructions: $fieldInstructions,
        );

        try {
            $result = (new AiService($client))->generate([
                'system_prompt' => $prompts['system_prompt'],
                'user_prompt' => $prompts['user_prompt'],
                'max_tokens' => 4500,
                'temperature' => 0.7,
                'json_mode' => true,
                'entity_type' => 'product',
                'entity_id' => $row['id'],
            ]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $payload = JsonRescue::decode($result['text']);
        if ($payload === null) {
            $this->json([
                'ok' => false,
                'message' => 'Le modèle a renvoyé une réponse non parsable. Réessayez.',
                'raw' => mb_substr($result['text'], 0, 2000),
            ], 502);
        }

        $this->json([
            'ok' => true,
            'description_short' => $payload['description_short'] ?? '',
            'description' => $payload['description'] ?? '',
            'meta_title' => $payload['meta_title'] ?? '',
            'meta_description' => $payload['meta_description'] ?? '',
            'meta_keywords' => $payload['meta_keywords'] ?? '',
            'debug_system_prompt' => $prompts['system_prompt'] ?? '',
            'usage' => [
                'model' => $result['model'],
                'prompt_tokens' => $result['prompt_tokens'],
                'completion_tokens' => $result['completion_tokens'],
                'cost_eur' => $result['cost_eur'],
            ],
        ]);
    }

    /**
     * AJAX : réordonne les images de la galerie produit côté PrestaShop.
     * Reçoit en POST un tableau image_ids[] représentant le nouvel ordre.
     */
    public function reorderGallery(string $id): void
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

        $rawIds = $_POST['image_ids'] ?? [];
        if (!is_array($rawIds) || empty($rawIds)) {
            $this->json(['ok' => false, 'message' => 'Ordre manquant ou invalide.'], 400);
        }
        $orderedIds = array_values(array_filter(array_map('intval', $rawIds), fn(int $i) => $i > 0));
        if (empty($orderedIds)) {
            $this->json(['ok' => false, 'message' => 'Aucun id image valide.'], 400);
        }

        $service = new PrestaShopClient($client);
        if (!$service->isConfigured()) {
            $this->json(['ok' => false, 'message' => 'Clé API PrestaShop non configurée.'], 400);
        }

        try {
            $report = $service->reorderProductImages((int) $row['presta_id'], $orderedIds);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'message' => 'Échec réordonnancement : ' . $e->getMessage()], 502);
        }

        $this->json([
            'ok' => true,
            'updated' => $report['updated'],
            'skipped' => $report['skipped'],
            'errors' => $report['errors'],
            'message' => $report['updated'] . ' image' . ($report['updated'] > 1 ? 's' : '') . ' réordonnée' . ($report['updated'] > 1 ? 's' : '') . '.',
        ]);
    }

    /**
     * Invalide le cache DB galerie/promos pour un produit donné.
     * À appeler après toute mutation côté PS (add image, create/delete promo).
     * Les données seront re-fetchées au prochain chargement de la fiche.
     */
    private static function invalidateProductLiveCache(string $clientId, int $prestaProductId): void
    {
        $repo = new PrestaProductRepository();
        // Nulls image_ids -> re-fetch au prochain show()
        $repo->saveImageIds($clientId, $prestaProductId, null);
        // Nulls active_promos_json -> vidé au prochain show() (jusqu'au prochain sync)
        $repo->clearActivePromosJson($clientId, $prestaProductId);
    }

    private function emptyToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    // ------------------------------------------------------------------------
    // Sync du mapping Nutriweb -> PrestaShop (aw_customproductfield)
    // ------------------------------------------------------------------------

    /**
     * POST /produits/{id}/sync-mapping : pour chaque SKU Nutriweb matché à ce
     * produit, résout les valeurs sources (colonnes du cache + nutrifacts live)
     * et pousse les champs custom mappés vers le module aw_customproductfield.
     * v1 : seules les destinations `custom.*` sont poussées ; les mappings
     * `product.*` / `combination.*` sont dénombrés dans le retour.
     */
    /**
     * POST /produits/{id}/sync-mapping — pousse pour tous les SKUs matchés au produit.
     */
    public function syncMapping(string $id): void
    {
        [$client, $row, $back] = $this->requireProductForSync($id);
        $catRows = (new NutriwebCatalogRepository())->listMatchedToProduct($client->id, (int) $row['presta_id']);
        if ($catRows === []) {
            $this->flashError('Aucun SKU Nutriweb lié à ce produit. Synchronise d\'abord le catalogue Nutriweb.');
            $this->redirect($back);
        }
        $this->pushMappingAndRedirect($client, (int) $row['presta_id'], $catRows, $back, 'ce produit');
    }

    /**
     * POST /produits/{id}/sync-mapping/{comboId} — pousse pour UNE déclinaison précise.
     */
    public function syncMappingCombination(string $id, string $comboId): void
    {
        [$client, $row, $back] = $this->requireProductForSync($id);
        $comboId = (int) $comboId;
        if ($comboId <= 0) {
            $this->flashError('ID de déclinaison invalide.');
            $this->redirect($back);
        }
        $catRow = (new NutriwebCatalogRepository())->findByCombination($client->id, $comboId);
        if ($catRow === null) {
            $this->flashError('Aucun SKU Nutriweb lié à la déclinaison D#' . $comboId . '. Synchronise d\'abord le catalogue Nutriweb.');
            $this->redirect($back);
        }
        $this->pushMappingAndRedirect($client, (int) $row['presta_id'], [$catRow], $back, 'la déclinaison D#' . $comboId);
    }

    /**
     * Charge le client + le produit + le mapping et vérifie qu'ils existent.
     * @return array{0:\App\Models\Client, 1:array<string,mixed>, 2:string} [$client, $row, $backUrl]
     */
    private function requireProductForSync(string $id): array
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->redirect('/dashboard');
        }
        $row = (new PrestaProductRepository())->findById($client->id, $id);
        if ($row === null) {
            $this->flashError('Produit introuvable.');
            $this->redirect('/produits');
        }
        $back = '/produits/' . urlencode((string) $row['id']);
        if (empty($client->fieldMapping)) {
            $this->flashError('Aucune correspondance dans Paramètres → Mapping.');
            $this->redirect($back);
        }
        return [$client, $row, $back];
    }

    /**
     * Coeur du sync : construit le batch depuis les rows catalog + le mapping,
     * pousse au module aw_customproductfield, flash + redirect.
     *
     * @param list<array<string,mixed>> $catRows
     */
    private function pushMappingAndRedirect(\App\Models\Client $client, int $prestaProductId, array $catRows, string $back, string $scopeLabel): void
    {
        $mapping = $client->fieldMapping ?? [];

        // Sépare par type de destination
        $customMappings = [];   // src_key => cpf_field
        $skippedNativeCount = 0;
        foreach ($mapping as $src => $dest) {
            if (str_starts_with((string) $dest, 'custom.')) {
                $customMappings[(string) $src] = substr((string) $dest, strlen('custom.'));
            } else {
                $skippedNativeCount++;
            }
        }

        $aw = new AwCpfClient($client);
        if (!$aw->isConfigured()) {
            $this->flashError('Clé API aw_customproductfield non configurée (Paramètres → PrestaShop).');
            $this->redirect($back);
        }

        // Schema pour connaître les champs lang.
        $langByField = [];
        try {
            foreach ($aw->fetchSchema() as $f) {
                $langByField[$f['key']] = !empty($f['lang']);
            }
        } catch (\Throwable $e) {
            error_log('syncMapping fetchSchema failed: ' . $e->getMessage());
        }

        // Faut-il fetcher les nutrifacts en live ?
        $needsNutrifacts = false;
        foreach (array_keys($mapping) as $src) {
            if (str_starts_with((string) $src, 'nutrifacts.')) {
                $needsNutrifacts = true;
                break;
            }
        }

        $batch = [];
        $fetchErrors = [];
        foreach ($catRows as $catRow) {
            $sku = (string) ($catRow['sku'] ?? '');
            if ($sku === '') continue;
            $idProductAttr = (int) ($catRow['presta_combination_id'] ?? 0);

            $nutrifacts = null;
            if ($needsNutrifacts && $customMappings !== []) {
                try {
                    $payload = (new NutriwebClient($client->id))->fetchSkuDetail($sku, 'nutrifacts');
                    $nutrifacts = self::extractNutrifactsFromPayload($payload);
                } catch (\Throwable $e) {
                    $fetchErrors[] = 'SKU ' . $sku . ' nutrifacts KO : ' . $e->getMessage();
                }
            }

            foreach ($customMappings as $srcKey => $cpfField) {
                $val = self::resolveSourceValue($srcKey, $catRow, $nutrifacts);
                if ($val === null || $val === '') continue;
                $update = [
                    'id_product' => $prestaProductId,
                    'id_product_attribute' => $idProductAttr,
                    'field' => $cpfField,
                    'value' => $val,
                ];
                if (!empty($langByField[$cpfField])) {
                    $update['id_lang'] = 1;
                }
                $batch[] = $update;
            }
        }

        if ($batch === []) {
            $msg = 'Aucun champ custom à pousser pour ' . $scopeLabel . ' (mapping vide, valeurs sources absentes, ou seuls des mappings natifs configurés).';
            if ($fetchErrors !== []) $msg .= ' Erreurs nutrifacts : ' . implode(' | ', $fetchErrors);
            $this->flashError($msg);
            $this->redirect($back);
        }

        try {
            $result = $aw->setBatch($batch);
        } catch (\Throwable $e) {
            error_log('syncMapping setBatch failed: ' . $e->getMessage());
            $this->flashError('Échec push aw_customproductfield : ' . $e->getMessage());
            $this->redirect($back);
        }

        $sent = count($batch);
        $applied = (int) ($result['applied'] ?? 0);
        $errs = array_merge($fetchErrors, $result['errors'] ?? []);
        $msg = "Sync mapping OK pour {$scopeLabel} : {$sent} champ" . ($sent > 1 ? 's' : '') . " envoyé" . ($sent > 1 ? 's' : '') . ", {$applied} appliqué" . ($applied > 1 ? 's' : '') . " côté module.";
        if ($skippedNativeCount > 0) {
            $msg .= " (Note : {$skippedNativeCount} mapping" . ($skippedNativeCount > 1 ? 's' : '') . " natif" . ($skippedNativeCount > 1 ? 's' : '') . " ignoré" . ($skippedNativeCount > 1 ? 's' : '') . " en v1.)";
        }
        if ($errs !== []) {
            $this->flashError($msg . ' Erreurs : ' . implode(' | ', $errs));
        } else {
            $this->flashSuccess($msg);
        }
        $this->redirect($back);
    }

    /**
     * Extrait récursivement la première occurrence de la clé `nutrifacts` dans le payload.
     * @return array<string,mixed>|null
     */
    private static function extractNutrifactsFromPayload(mixed $node): ?array
    {
        if (!is_array($node)) return null;
        if (isset($node['nutrifacts']) && is_array($node['nutrifacts'])) {
            return $node['nutrifacts'];
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $found = self::extractNutrifactsFromPayload($child);
                if ($found !== null) return $found;
            }
        }
        return null;
    }

    /**
     * Résout la valeur pour une clé source du mapping.
     *  - `nutrifacts.<key>` : lit dans le payload live (ex. nutrifacts.ingredient).
     *    Cas spécial : `nutrifacts.macro` → rendu HTML macro + micro
     *    (via NutrifactsRenderer, HTML identique à la page /catalogue/sku/{sku}).
     *  - autre : lit dans la row nutriweb_catalog (colonnes du cache).
     */
    private static function resolveSourceValue(string $srcKey, array $catRow, ?array $nutrifacts): mixed
    {
        if (str_starts_with($srcKey, 'nutrifacts.')) {
            if ($nutrifacts === null) return null;
            $path = substr($srcKey, strlen('nutrifacts.'));
            if ($path === 'macro') {
                // On rend macro + micro dans un même bloc HTML (micro n'est ajouté
                // que s'il est présent et non vide dans le payload).
                $html = NutrifactsRenderer::renderMacroAndMicro($nutrifacts);
                return $html !== '' ? $html : null;
            }
            if (!array_key_exists($path, $nutrifacts)) return null;
            $v = $nutrifacts[$path];
            return is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
        }
        if (!array_key_exists($srcKey, $catRow)) return null;
        return $catRow[$srcKey];
    }
}
