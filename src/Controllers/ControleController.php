<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Middleware\Auth;
use App\Repositories\PrestaProductCombinationRepository;
use App\Repositories\PrestaProductRepository;
use App\Services\ClientResolver;
use App\Services\PrestaShopClient;
use App\Session;

final class ControleController extends BaseController
{
    /** Clé session pour la file des requêtes SQL à jouer manuellement. */
    private const SQL_QUEUE_KEY = 'controle_sql_queue';

    /**
     * POST : "Corriger" — n'exécute RIEN en base. Empile une requête SQL DELETE
     * (niveau produit, attr=0) dans une file en session, que l'utilisateur copie
     * et joue lui-même en base (phpMyAdmin). Évite tout appel API destructeur.
     */
    public function fixSupplierRef(): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->redirect('/dashboard');
        }

        $prestaId = (int) ($this->input('presta_product_id') ?? 0);
        if ($prestaId <= 0 || $client->supplierId === null || $client->supplierId <= 0) {
            $this->flashError('Paramètres invalides (produit ou fournisseur manquant).');
            $this->redirect('/controle');
        }

        $supplierId = (int) $client->supplierId;
        $sql = sprintf(
            'DELETE FROM ps_product_supplier WHERE id_product = %d AND id_product_attribute = 0 AND id_supplier = %d;',
            $prestaId,
            $supplierId
        );

        $queue = Session::get(self::SQL_QUEUE_KEY, []);
        if (!is_array($queue)) {
            $queue = [];
        }
        // Clé = presta_id → cliquer deux fois sur le même produit ne crée pas de doublon.
        $queue[(string) $prestaId] = $sql;
        Session::set(self::SQL_QUEUE_KEY, $queue);

        $this->flashSuccess('Requête SQL ajoutée pour le produit #' . $prestaId . ' (à jouer en base).');
        $this->redirect('/controle');
    }

    /**
     * POST : vide la file des requêtes SQL en attente.
     */
    public function clearSqlQueue(): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        Session::forget(self::SQL_QUEUE_KEY);
        $this->flashSuccess('Liste des requêtes SQL vidée.');
        $this->redirect('/controle');
    }


    public function index(): void
    {
        Auth::require();

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->renderApp('pages.dashboard.no_client', [], [
                'page_title' => 'Aucun client',
            ]);
            return;
        }

        // Contrôle #1 : produits AVEC déclinaisons qui ont une ligne ps_product_supplier
        // au niveau PRODUIT (id_product_attribute = 0) pour le fournisseur configuré.
        // Source exacte : Webservice (filtre attr=0) croisé avec les déclinaisons locales.
        $supplierRefMisplaced = [];
        $controlError = null;
        $supplierId = $client->supplierId;

        if ($supplierId !== null && $supplierId > 0) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            try {
                $service = new PrestaShopClient($client);
                // Map id_product => ref (lignes attr=0) — la verite cote Presta
                $productLevelRefs = $service->fetchProductLevelSupplierRefs($supplierId);
                // Produits qui ont des declinaisons (cache local)
                $combiCounts = (new PrestaProductCombinationRepository())->productIdsWithCombinations($client->id);
                // Intersection : produit AVEC declis ET ligne attr=0
                $flaggedIds = array_values(array_intersect(array_keys($productLevelRefs), array_keys($combiCounts)));
                // Details (nom, ref, uuid) depuis le cache produit local
                $details = (new PrestaProductRepository())->findByPrestaIds($client->id, $flaggedIds);
                foreach ($flaggedIds as $pid) {
                    $supplierRefMisplaced[] = [
                        'id' => $details[$pid]['id'] ?? null,
                        'presta_id' => $pid,
                        'name' => $details[$pid]['name'] ?? ('Produit #' . $pid),
                        'reference' => $details[$pid]['reference'] ?? '',
                        'supplier_reference' => $productLevelRefs[$pid] ?? '',
                        'nb_combinations' => $combiCounts[$pid] ?? 0,
                    ];
                }
                usort($supplierRefMisplaced, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
            } catch (\Throwable $e) {
                $controlError = $e->getMessage();
            }
        }

        // Contrôle #2 : déclinaisons ayant 2 attributs ou plus (lecture cache local).
        $multiAttrCombinations = (new PrestaProductCombinationRepository())
            ->listWithMultipleAttributes($client->id);

        // File des requêtes SQL empilées via le bouton "Corriger" (jouées manuellement).
        $sqlQueue = array_values((array) Session::get(self::SQL_QUEUE_KEY, []));

        $this->renderApp('pages.controle.index', [
            'supplier_id' => $supplierId,
            'supplier_ref_misplaced' => $supplierRefMisplaced,
            'control_error' => $controlError,
            'multi_attr_combinations' => $multiAttrCombinations,
            'sql_queue' => $sqlQueue,
        ], [
            'active' => 'controle',
            'page_title' => 'Contrôle',
        ]);
    }
}
