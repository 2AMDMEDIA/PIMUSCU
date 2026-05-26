<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Middleware\Auth;
use App\Repositories\AdminRepository;
use App\Services\ClientResolver;

final class DashboardController extends BaseController
{
    public function index(): void
    {
        Auth::require();

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            // Super-admin sans client sélectionné → renvoie sur /admin
            // Client user sans aucune liaison → message d'erreur
            if (\App\Session::get('is_super_admin', false)) {
                $this->redirect('/admin');
            }
            $this->renderApp('pages.dashboard.no_client', [], [
                'page_title' => 'Aucun client assigné',
            ]);
            return;
        }

        // Stats produits / catégories depuis la DB locale (cache de sync)
        $pdo = Database::pdo();

        $productStats = $pdo->prepare(
            'SELECT
                COUNT(*) AS total,
                COALESCE(SUM(has_description), 0) AS with_desc,
                COALESCE(SUM(CASE WHEN has_description = 0 AND has_cms_content = 0 THEN 1 ELSE 0 END), 0) AS without_desc,
                COALESCE(SUM(has_cms_content), 0) AS cms_content
              FROM presta_products
             WHERE client_id = :client_id'
        );
        $productStats->execute([':client_id' => $client->id]);
        $products = $productStats->fetch() ?: ['total' => 0, 'with_desc' => 0, 'without_desc' => 0, 'cms_content' => 0];

        $categoriesCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM presta_categories WHERE client_id = " . $pdo->quote($client->id)
        )->fetchColumn();

        $usage = (new AdminRepository())->usageStatsForClient($client->id, 30);

        $this->renderApp('pages.dashboard.index', [
            'product_stats' => [
                'total' => (int) $products['total'],
                'with_desc' => (int) $products['with_desc'],
                'without_desc' => (int) $products['without_desc'],
                'cms_content' => (int) $products['cms_content'],
            ],
            'categories_count' => $categoriesCount,
            'usage' => $usage,
        ], [
            'active' => 'dashboard',
            'page_title' => 'Tableau de bord',
        ]);
    }
}
