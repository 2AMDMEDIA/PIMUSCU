<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Bootstrap;
use App\Helpers\Csrf;
use App\Middleware\Auth;
use App\Repositories\PrestaCategoryRepository;
use App\Services\ClientResolver;
use App\Services\PrestaShopClient;

final class CategoriesController extends BaseController
{
    public function index(): void
    {
        Auth::require();
        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->redirect('/dashboard');
        }

        $repo = new PrestaCategoryRepository();
        $rows = $repo->listForClient($client->id);

        $thresholds = (array) (Bootstrap::config('app.description_thresholds') ?? ['empty' => 0, 'short_max' => 300]);
        $emptyMax = (int) ($thresholds['empty'] ?? 0);
        $shortMax = (int) ($thresholds['short_max'] ?? 300);

        $counts = ['all' => 0, 'complete' => 0, 'short' => 0, 'empty' => 0, 'optimized' => 0, 'not_optimized' => 0];
        foreach ($rows as $row) {
            $counts['all']++;
            // Total = description principale + description complémentaire (bas de page)
            $len = mb_strlen(strip_tags((string) ($row['description'] ?? '')))
                 + mb_strlen(strip_tags((string) ($row['aw_description_2'] ?? '')));
            if ($len <= $emptyMax) {
                $counts['empty']++;
            } elseif ($len <= $shortMax) {
                $counts['short']++;
            } else {
                $counts['complete']++;
            }

            $hasAnyOptimized = !empty($row['optimized_description'])
                || !empty($row['optimized_aw_description_2'])
                || !empty($row['optimized_meta_title'])
                || !empty($row['optimized_meta_description'])
                || !empty($row['optimized_meta_keywords']);
            if ($hasAnyOptimized) {
                $counts['optimized']++;
            } else {
                $counts['not_optimized']++;
            }
        }

        // Construction de l'arbre hiérarchique pour affichage indenté
        $tree = $this->buildTree($rows);

        $this->renderApp('pages.categories.index', [
            'rows' => $rows,
            'tree' => $tree,
            'counts' => $counts,
            'thresholds' => ['empty_max' => $emptyMax, 'short_max' => $shortMax],
            'has_api_key' => $client->prestashopApiKeyEncrypted !== null,
        ], [
            'active' => 'categories',
            'page_title' => 'Catégories',
        ]);
    }

    /**
     * Test de connexion synchronisé (AJAX).
     */
    public function testConnection(): void
    {
        Auth::require();
        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->json(['ok' => false, 'message' => 'Aucun client actif.'], 400);
        }
        $result = (new PrestaShopClient($client))->testConnection();
        $this->json($result, $result['ok'] ? 200 : 400);
    }

    /**
     * Sync catégories — synchrone (acceptable pour quelques centaines de catégories).
     * Pour des shops avec >5000 catégories, à passer en background job dans une V2.
     */
    public function sync(): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->flashError('Aucun client actif.');
            $this->redirect('/categories');
        }

        $service = new PrestaShopClient($client);
        if (!$service->isConfigured()) {
            $this->flashError('Configurez d\'abord votre clé API PrestaShop dans les paramètres.');
            $this->redirect('/settings?tab=prestashop');
        }

        try {
            $categories = $service->fetchAllCategories();
            $productCounts = $service->fetchProductsCountByCategory();
            $count = (new PrestaCategoryRepository())->upsertBatch($client->id, $categories, $productCounts);
            $this->flashSuccess($count . ' catégorie' . ($count > 1 ? 's' : '') . ' synchronisée' . ($count > 1 ? 's' : '') . '.');
        } catch (\Throwable $e) {
            $this->flashError('Erreur de synchronisation : ' . $e->getMessage());
        }

        $this->redirect('/categories');
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{row:array<string,mixed>,depth:int}>
     */
    private function buildTree(array $rows): array
    {
        $byParent = [];
        foreach ($rows as $row) {
            $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
            $byParent[$parentId][] = $row;
        }

        $result = [];
        $rootParents = array_keys($byParent);
        // Trouver les vraies racines : id_parent que personne n'utilise comme presta_id
        $allPrestaIds = array_map(fn ($r) => (int) $r['presta_id'], $rows);
        $allPrestaIdsSet = array_flip($allPrestaIds);

        $roots = [];
        foreach ($rows as $row) {
            $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
            if (!isset($allPrestaIdsSet[$parentId])) {
                $roots[] = $row;
            }
        }

        $walk = function (array $node, int $depth) use (&$walk, &$result, $byParent) {
            $result[] = ['row' => $node, 'depth' => $depth];
            $children = $byParent[(int) $node['presta_id']] ?? [];
            usort($children, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));
            foreach ($children as $child) {
                $walk($child, $depth + 1);
            }
        };

        usort($roots, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));
        foreach ($roots as $root) {
            $walk($root, 0);
        }

        return $result;
    }
}
