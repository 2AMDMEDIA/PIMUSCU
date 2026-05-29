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

    /** Lignes par page pour les tableaux de contrôle. */
    private const PER_PAGE = 50;

    /**
     * Redirige vers /controle en préservant le contexte (onglet, page, filtres)
     * passé en hidden fields dans les formulaires POST.
     */
    private function redirectBack(): void
    {
        $params = [];
        if (in_array($this->input('tab'), ['2', '3'], true)) {
            $params['tab'] = (string) $this->input('tab');
        }
        $page = (int) ($this->input('page') ?? 0);
        if ($page > 1) {
            $params['page'] = $page;
        }
        $q = trim((string) ($this->input('q') ?? ''));
        if ($q !== '') {
            $params['q'] = $q;
        }
        $brand = trim((string) ($this->input('brand') ?? ''));
        if ($brand !== '') {
            $params['brand'] = $brand;
        }
        if (in_array($this->input('active'), ['0', '1'], true)) {
            $params['active'] = (string) $this->input('active');
        }
        $this->redirect('/controle' . ($params !== [] ? '?' . http_build_query($params) : ''));
    }

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
            $this->redirectBack();
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
        $this->redirectBack();
    }

    /**
     * POST : "Supprimer attribut" (onglet 2) — n'exécute RIEN en base. Empile une
     * requête SQL DELETE sur ps_product_attribute_combination (retire l'association
     * d'UNE valeur d'attribut a UNE declinaison) dans la file en session.
     */
    public function fixCombinationAttribute(): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->redirect('/dashboard');
        }

        $idProductAttribute = (int) ($this->input('id_product_attribute') ?? 0);
        $idAttribute = (int) ($this->input('id_attribute') ?? 0);
        if ($idProductAttribute <= 0 || $idAttribute <= 0) {
            $this->flashError('Paramètres invalides (id_product_attribute ou id_attribute manquant).');
            $this->redirectBack();
        }

        $sql = sprintf(
            'DELETE FROM `ps_product_attribute_combination` WHERE `ps_product_attribute_combination`.`id_attribute` = %d AND `ps_product_attribute_combination`.`id_product_attribute` = %d;',
            $idAttribute,
            $idProductAttribute
        );

        $queue = Session::get(self::SQL_QUEUE_KEY, []);
        if (!is_array($queue)) {
            $queue = [];
        }
        // Clé unique par couple (decli, attribut) → pas de doublon au reclic.
        $queue['pac_' . $idProductAttribute . '_' . $idAttribute] = $sql;
        Session::set(self::SQL_QUEUE_KEY, $queue);

        $this->flashSuccess('Requête SQL ajoutée (déclinaison #' . $idProductAttribute . ', attribut #' . $idAttribute . ').');
        $this->redirectBack();
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
        $this->redirectBack();
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

        // Onglet actif + filtres + pagination (server-side).
        $tab = in_array($this->input('tab'), ['2', '3', '4'], true) ? (int) $this->input('tab') : 1;
        $page = max(1, (int) ($this->input('page') ?? 1));
        $search = trim((string) ($this->input('q') ?? ''));
        $brand = trim((string) ($this->input('brand') ?? ''));
        $active = in_array($this->input('active'), ['0', '1'], true) ? (string) $this->input('active') : '';
        $perPage = self::PER_PAGE;
        $offset = ($page - 1) * $perPage;

        $supplierId = $client->supplierId;
        $controlError = null;
        $combRepo = new PrestaProductCombinationRepository();

        // ----- Onglet 1 : réf fournisseur mal placée (live Webservice). Calculé seulement si actif. -----
        $supplierRefMisplaced = [];
        $brands1 = [];
        $total1 = 0;
        if ($tab === 1 && $supplierId !== null && $supplierId > 0) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            try {
                $service = new PrestaShopClient($client);
                $productLevelRefs = $service->fetchProductLevelSupplierRefs($supplierId);
                $combiCounts = $combRepo->productIdsWithCombinations($client->id);
                $flaggedIds = array_values(array_intersect(array_keys($productLevelRefs), array_keys($combiCounts)));
                $details = (new PrestaProductRepository())->findByPrestaIds($client->id, $flaggedIds);

                $all = [];
                foreach ($flaggedIds as $pid) {
                    $all[] = [
                        'id' => $details[$pid]['id'] ?? null,
                        'presta_id' => $pid,
                        'name' => $details[$pid]['name'] ?? ('Produit #' . $pid),
                        'reference' => $details[$pid]['reference'] ?? '',
                        'supplier_reference' => $productLevelRefs[$pid] ?? '',
                        'brand' => $details[$pid]['manufacturer_name'] ?? '',
                        'active' => $details[$pid]['active'] ?? 1,
                        'nb_combinations' => $combiCounts[$pid] ?? 0,
                    ];
                }
                // Liste des marques pour le filtre (avant filtrage).
                foreach ($all as $r) {
                    if ($r['brand'] !== '') $brands1[$r['brand']] = true;
                }
                $brands1 = array_keys($brands1);
                sort($brands1, SORT_NATURAL | SORT_FLAG_CASE);

                // Filtre recherche + marque (PHP, la source est live).
                if ($search !== '') {
                    $needle = mb_strtolower($search);
                    $all = array_values(array_filter($all, fn($r) => str_contains(mb_strtolower($r['name']), $needle)
                        || str_contains(mb_strtolower((string) $r['reference']), $needle)
                        || str_contains(mb_strtolower((string) $r['supplier_reference']), $needle)));
                }
                if ($brand !== '') {
                    $all = array_values(array_filter($all, fn($r) => (string) $r['brand'] === $brand));
                }
                if ($active === '1' || $active === '0') {
                    $wantActive = (int) $active;
                    $all = array_values(array_filter($all, fn($r) => (int) $r['active'] === $wantActive));
                }
                usort($all, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

                $total1 = count($all);
                $supplierRefMisplaced = array_slice($all, $offset, $perPage);
            } catch (\Throwable $e) {
                $controlError = $e->getMessage();
            }
        }

        // ----- Onglet 2 : déclinaisons multi-attributs (cache local, SQL paginé). -----
        $multiAttrCombinations = [];
        $brands2 = $combRepo->distinctBrandsWithMultipleAttributes($client->id);
        $active2 = $tab === 2 ? $active : '';
        $total2 = $combRepo->countWithMultipleAttributes($client->id, $tab === 2 ? $search : '', $tab === 2 ? $brand : '', $active2);
        $distinctProducts2 = $combRepo->countDistinctProductsWithMultipleAttributes($client->id, $tab === 2 ? $search : '', $tab === 2 ? $brand : '', $active2);
        if ($tab === 2) {
            $multiAttrCombinations = $combRepo->listWithMultipleAttributes($client->id, $search, $brand, $perPage, $offset, $active);
        }

        // ----- Onglet 3 : produits avec exactement 1 déclinaison (cache local, SQL paginé). -----
        $singleComboProducts = [];
        $brands3 = $combRepo->distinctBrandsWithSingleCombination($client->id);
        $total3 = $combRepo->countProductsWithSingleCombination($client->id, $tab === 3 ? $search : '', $tab === 3 ? $brand : '', $tab === 3 ? $active : '');
        if ($tab === 3) {
            $singleComboProducts = $combRepo->listProductsWithSingleCombination($client->id, $search, $brand, $perPage, $offset, $active);
        }

        // ----- Onglet 4 : doublons de valeurs d'attribut (live Webservice). Calculé si actif. -----
        $dupAttributes = [];
        $total4 = 0;
        $attrError = null;
        if ($tab === 4) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            try {
                $groups = (new PrestaShopClient($client))->fetchAttributeGroupsWithValues();
                $all = [];
                foreach ($groups as $g) {
                    $byLabel = [];
                    foreach ($g['values'] as $v) {
                        $key = mb_strtolower(trim((string) $v['label']));
                        if ($key === '') continue;
                        $byLabel[$key][] = $v;
                    }
                    foreach ($byLabel as $vals) {
                        if (count($vals) < 2) continue;
                        $all[] = [
                            'group_id' => (int) $g['id'],
                            'group_name' => (string) $g['name'],
                            'label' => (string) $vals[0]['label'],
                            'ids' => array_map(fn($x) => (int) $x['id'], $vals),
                            'count' => count($vals),
                        ];
                    }
                }
                if ($search !== '') {
                    $needle = mb_strtolower($search);
                    $all = array_values(array_filter($all, fn($r) => str_contains(mb_strtolower($r['label']), $needle)
                        || str_contains(mb_strtolower($r['group_name']), $needle)));
                }
                usort($all, fn($a, $b) => [$a['group_name'], $a['label']] <=> [$b['group_name'], $b['label']]);
                $total4 = count($all);
                $dupAttributes = array_slice($all, $offset, $perPage);
            } catch (\Throwable $e) {
                $attrError = $e->getMessage();
            }
        }

        // File des requêtes SQL empilées (jouées manuellement).
        $sqlQueue = array_values((array) Session::get(self::SQL_QUEUE_KEY, []));

        $activeTotal = match ($tab) {
            2 => $total2,
            3 => $total3,
            4 => $total4,
            default => $total1,
        };
        $totalPages = max(1, (int) ceil($activeTotal / $perPage));

        $this->renderApp('pages.controle.index', [
            'supplier_id' => $supplierId,
            'supplier_ref_misplaced' => $supplierRefMisplaced,
            'control_error' => $controlError,
            'multi_attr_combinations' => $multiAttrCombinations,
            'single_combo_products' => $singleComboProducts,
            'sql_queue' => $sqlQueue,
            'tab' => $tab,
            'page' => $page,
            'per_page' => $perPage,
            'total1' => $total1,
            'total2' => $total2,
            'total3' => $total3,
            'total4' => $total4,
            'distinct_products2' => $distinctProducts2,
            'dup_attributes' => $dupAttributes,
            'attr_error' => $attrError,
            'total_pages' => $totalPages,
            'search' => $search,
            'brand' => $brand,
            'active' => $active,
            'brands1' => $brands1,
            'brands2' => $brands2,
            'brands3' => $brands3,
        ], [
            'active' => 'controle',
            'page_title' => 'Contrôle',
        ]);
    }
}
