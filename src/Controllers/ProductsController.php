<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Middleware\Auth;
use App\Repositories\PrestaProductCombinationRepository;
use App\Repositories\PrestaProductRepository;
use App\Services\ClientResolver;
use App\Services\PrestaShopClient;
use App\Services\ReviewsClient;

final class ProductsController extends BaseController
{
    private const PER_PAGE = 60;

    public function index(): void
    {
        Auth::require();
        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->redirect('/dashboard');
        }

        $repo = new PrestaProductRepository();
        $search = trim((string) ($this->input('q') ?? ''));
        $filter = (string) ($this->input('filter') ?? 'all');
        if (!in_array($filter, ['all', 'with_desc', 'without_desc', 'cms'], true)) {
            $filter = 'all';
        }
        $status = (string) ($this->input('status') ?? 'all');
        if (!in_array($status, ['all', 'active', 'inactive'], true)) {
            $status = 'all';
        }
        $catalog = (string) ($this->input('catalog') ?? 'all');
        if (!in_array($catalog, ['all', 'in', 'out'], true)) {
            $catalog = 'all';
        }
        $categoryId = (int) ($this->input('category') ?? '0');
        if ($categoryId < 0) $categoryId = 0;
        $page = max(1, (int) ($this->input('page') ?? '1'));

        $products = $repo->listForClient($client->id, $search, $filter, $page, self::PER_PAGE, $status, $categoryId, $catalog);
        $totalFiltered = $repo->countForClient($client->id, $search, $filter, $status, $categoryId, $catalog);
        $stats = $repo->statsForClient($client->id);
        $categoryOptions = $repo->categoriesWithProductCounts($client->id);
        $totalPages = (int) max(1, ceil($totalFiltered / self::PER_PAGE));

        $this->renderApp('pages.produits.index', [
            'products' => $products,
            'stats' => $stats,
            'total_filtered' => $totalFiltered,
            'search' => $search,
            'filter' => $filter,
            'status' => $status,
            'catalog' => $catalog,
            'category' => $categoryId,
            'category_options' => $categoryOptions,
            'page' => $page,
            'total_pages' => $totalPages,
            'has_api_key' => $client->prestashopApiKeyEncrypted !== null,
        ], [
            'active' => 'produits',
            'page_title' => 'Produits',
        ]);
    }

    public function sync(): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->flashError('Aucun client actif.');
            $this->redirect('/produits');
        }

        $service = new PrestaShopClient($client);
        if (!$service->isConfigured()) {
            $this->flashError('Configurez d\'abord votre clé API PrestaShop dans les paramètres.');
            $this->redirect('/settings?tab=prestashop');
        }

        try {
            // Sync long (gros catalogue) : on retire la limite de temps PHP et on
            // continue même si le navigateur/proxy se déconnecte (sinon les derniers
            // lots de combinaisons — donc les déclinaisons récentes — sont manqués).
            @set_time_limit(0);
            ignore_user_abort(true);

            // Libère le verrou de session pour autoriser les autres requêtes pendant le sync long.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }


            $repo = new PrestaProductRepository();

            // Catégories à ignorer (Paramètres → PrestaShop) : on récupère l'ensemble
            // des id_product de ces catégories pour les exclure du sync. Comme ils ne
            // sont pas ajoutés à $syncedIds, deleteStale les purgera s'ils étaient déjà
            // en cache. Best-effort : si l'appel plante, on n'ignore rien.
            $ignoredProductIds = [];
            if (!empty($client->ignoredCategoryIds)) {
                try {
                    $ignoredProductIds = $service->fetchProductIdsInCategories($client->ignoredCategoryIds);
                } catch (\Throwable $e) {
                    error_log('Sync ignored categories failed: ' . $e->getMessage());
                }
            }

            // Pré-charge la map presta_product_id => product_supplier_reference
            // si un id_supplier est configuré pour ce client.
            $supplierRefs = [];
            if ($client->supplierId !== null && $client->supplierId > 0) {
                try {
                    $supplierRefs = $service->fetchProductSuppliersBySupplier($client->supplierId);
                } catch (\Throwable $e) {
                    error_log('Sync supplier refs failed: ' . $e->getMessage());
                }
            }

            // Stream par batches de 100 produits pour rester en memoire plate
            // (Fitadium a un gros catalogue avec descriptions HTML lourdes).
            // Track les IDs synchros pour purger les orphelins apres (produits
            // supprimes cote PS).
            $syncedIds = [];
            $count = $service->streamAllProducts(function (array $batch) use ($repo, $client, $supplierRefs, $ignoredProductIds, &$syncedIds): void {
                $toUpsert = [];
                foreach ($batch as $product) {
                    // Produit dans une catégorie ignorée : on saute (et on ne le compte pas
                    // dans syncedIds -> deleteStale le purgera s'il était déjà en cache).
                    if (isset($ignoredProductIds[(int) $product['id']])) {
                        continue;
                    }
                    $product['supplier_reference'] = $supplierRefs[$product['id']] ?? null;
                    $syncedIds[] = (int) $product['id'];
                    $toUpsert[] = $product;
                }
                if ($toUpsert !== []) {
                    $repo->upsertBatch($client->id, $toUpsert);
                }
            });
            // Nombre réellement importé (hors produits ignorés).
            $count = count($syncedIds);
            $purgedCount = $repo->deleteStale($client->id, $syncedIds);

            // Sync des combinaisons (déclinaisons taille/saveur/couleur).
            // Best-effort : si ça casse, on log et on continue (les produits sont déjà sync).
            $combinationCount = 0;
            try {
                $attributeIndex = $service->fetchAttributeIndex();
                $combSupplierRefs = [];
                if ($client->supplierId !== null && $client->supplierId > 0) {
                    $combSupplierRefs = $service->fetchCombinationSuppliersBySupplier($client->supplierId);
                }
                $combRepo = new PrestaProductCombinationRepository();
                // NON-destructif : on ne purge PLUS avant. On upsert pendant le stream
                // (synced_at = NOW()), puis on supprime APRES complétion les lignes non
                // touchées (déclis disparues côté PS). Si le sync coupe (timeout), la
                // purge n'est jamais atteinte → on ne perd aucune déclinaison existante.
                $combSyncStart = $combRepo->dbNow();

                $combinationCount = $service->streamAllCombinations(function (array $batch) use ($combRepo, $client, $attributeIndex, $combSupplierRefs): void {
                    foreach ($batch as &$c) {
                        // On garde labels + ids ALIGNES (meme ordre, 1:1) pour pouvoir
                        // les appairer cote affichage (Controle : "Gris (id: 2152)").
                        $labels = [];
                        $keptIds = [];
                        foreach ($c['option_value_ids'] as $valId) {
                            $lbl = $attributeIndex[$valId]['label'] ?? '';
                            if ($lbl !== '') {
                                $labels[] = $lbl;
                                $keptIds[] = (int) $valId;
                            }
                        }
                        $c['attributes_label'] = implode(' · ', $labels);
                        $c['option_value_ids'] = $keptIds;
                        $c['supplier_reference'] = $combSupplierRefs[$c['id']] ?? null;
                    }
                    unset($c);
                    $combRepo->upsertBatch($client->id, $batch);
                });
                // Sync complet atteint : purge des déclis obsolètes (non revues ce run).
                $combRepo->deleteSyncedBefore($client->id, $combSyncStart);
            } catch (\Throwable $e) {
                error_log('Sync combinations failed: ' . $e->getMessage());
            }

            // Sync des promos actives : on vide, puis on applique celles dont la fenetre est ouverte.
            $promoCount = 0;
            try {
                $repo->clearAllPromos($client->id);
                $specificPrices = $service->fetchAllSpecificPrices(2000);
                $promoCount = $repo->applyActivePromos($client->id, $specificPrices);
            } catch (\Throwable $e) {
                error_log('Sync promos failed: ' . $e->getMessage());
            }

            // Sync des stats avis (best-effort) : appel à api_reviews.php → stockage en DB.
            $reviewsCount = 0;
            $reviewsService = new ReviewsClient($client);
            if ($reviewsService->isConfigured()) {
                try {
                    $reviewsStats = $reviewsService->fetchStatsByProduct();
                    $reviewsCount = $repo->applyReviewsStats($client->id, $reviewsStats);
                } catch (\Throwable $e) {
                    error_log('Sync reviews stats failed: ' . $e->getMessage());
                }
            }

            $msg = $count . ' produit' . ($count > 1 ? 's' : '') . ' synchronisé' . ($count > 1 ? 's' : '') . '.';
            if ($purgedCount > 0) {
                $msg .= ' ' . $purgedCount . ' supprimé' . ($purgedCount > 1 ? 's' : '') . ' (orphelin' . ($purgedCount > 1 ? 's' : '') . ').';
            }
            if ($promoCount > 0) {
                $msg .= ' ' . $promoCount . ' promo' . ($promoCount > 1 ? 's' : '') . ' active' . ($promoCount > 1 ? 's' : '') . '.';
            }
            if ($combinationCount > 0) {
                $msg .= ' ' . $combinationCount . ' déclinaison' . ($combinationCount > 1 ? 's' : '') . '.';
            }
            if ($reviewsCount > 0) {
                $msg .= ' ' . $reviewsCount . ' produit' . ($reviewsCount > 1 ? 's' : '') . ' avec avis.';
            }
            $this->flashSuccess($msg);
        } catch (\Throwable $e) {
            $this->flashError('Erreur de synchronisation : ' . $e->getMessage());
        }

        $this->redirect('/produits');
    }
}
