<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Helpers\Csrf;
use App\Middleware\Auth;
use App\Repositories\ClientNutriwebSettingsRepository;
use App\Repositories\NutriwebCatalogRepository;
use App\Repositories\PrestaProductCombinationRepository;
use App\Repositories\PrestaProductRepository;
use App\Services\ClientResolver;
use App\Services\NutriwebClient;
use App\Services\PrestaShopClient;

final class CatalogueController extends BaseController
{
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

        $filter = $this->input('filter') ?? 'all';
        if (!in_array($filter, ['all', 'linked', 'unlinked'], true)) {
            $filter = 'all';
        }
        $search = trim((string) ($this->input('q') ?? ''));
        $brand = trim((string) ($this->input('brand') ?? ''));
        $sort = $this->input('sort') ?? '';
        if (!in_array($sort, ['size', 'flavor'], true)) {
            $sort = '';
        }
        $dir = $this->input('dir') === 'desc' ? 'desc' : 'asc';

        $catalogRepo = new NutriwebCatalogRepository();
        $catalogRows = $catalogRepo->listForClient($client->id);
        $lastSyncedAt = $catalogRepo->lastSyncedAt($client->id);

        // Verifie aussi la config Nutriweb (utile pour afficher l'etat si la table est vide)
        $nutriweb = new NutriwebClient($client->id);
        $status = $nutriweb->status();

        // Enrichit chaque row avec match (computed depuis les colonnes presta_*_id)
        $allRows = $this->enrichRowsWithMatch($client->id, $catalogRows);

        $linkedCount = 0;
        $brandsSet = [];
        foreach ($allRows as $r) {
            if ($r['match'] !== null) {
                $linkedCount++;
            }
            if (!empty($r['brand'])) {
                $brandsSet[(string) $r['brand']] = true;
            }
        }
        $brands = array_keys($brandsSet);
        sort($brands, SORT_NATURAL | SORT_FLAG_CASE);
        $totalCount = count($allRows);
        $unlinkedCount = $totalCount - $linkedCount;

        // Filtre + recherche
        $rows = $allRows;
        if ($filter === 'linked') {
            $rows = array_values(array_filter($rows, fn($r) => $r['match'] !== null));
        } elseif ($filter === 'unlinked') {
            $rows = array_values(array_filter($rows, fn($r) => $r['match'] === null));
        }
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, fn($r) => str_contains(mb_strtolower((string) $r['name']), $needle)));
        }
        if ($brand !== '') {
            $rows = array_values(array_filter($rows, fn($r) => (string) ($r['brand'] ?? '') === $brand));
        }

        // Tri optionnel
        if ($sort !== '') {
            usort($rows, function (array $a, array $b) use ($sort, $dir): int {
                if ($sort === 'size') {
                    $va = $a['size_rank'] ?? null;
                    $vb = $b['size_rank'] ?? null;
                } else {
                    $va = $a['flavor'] ?? null;
                    $vb = $b['flavor'] ?? null;
                }
                $aNull = $va === null || $va === '';
                $bNull = $vb === null || $vb === '';
                if ($aNull && $bNull) return 0;
                if ($aNull) return 1;
                if ($bNull) return -1;
                $cmp = is_int($va) ? ($va <=> $vb) : strnatcasecmp((string) $va, (string) $vb);
                return $dir === 'desc' ? -$cmp : $cmp;
            });
        }

        // Debug info : URL qui serait appelee + diagnostic config
        $nwSettings = (new ClientNutriwebSettingsRepository())->get($client->id);
        $baseUrl = (string) ($nwSettings['catalogue_url'] ?? '');
        parse_str((string) parse_url($baseUrl, PHP_URL_QUERY), $existingParams);
        $urlHasAkey = isset($existingParams['akey']);
        $urlHasFields = isset($existingParams['fields']);

        $debugInfo = [
            'configured_url' => $baseUrl,
            'product_info_url' => $nwSettings['product_info_url'] ?? '',
            'key_set' => $nwSettings['private_key_encrypted'] !== null,
            'key_length' => $nwSettings['private_key_encrypted'] !== null ? strlen((string) $nwSettings['private_key_encrypted']) : 0,
            'url_has_akey' => $urlHasAkey,
            'url_has_fields' => $urlHasFields,
            'full_url_masked' => '',
        ];
        if ($status['configured']) {
            try {
                $paramsToAdd = [];
                if ($urlHasAkey) {
                    // Tronque l'akey pour le display
                    $existingKey = (string) $existingParams['akey'];
                    $maskedExistingKey = mb_substr($existingKey, 0, 6) . '***' . mb_substr($existingKey, -3);
                    $maskedBaseUrl = preg_replace('/(akey=)[^&]+/', '$1' . $maskedExistingKey, $baseUrl);
                } else {
                    $maskedBaseUrl = $baseUrl;
                    if ($nwSettings['private_key_encrypted'] !== null) {
                        $key = \App\Helpers\Encryption::decrypt((string) $nwSettings['private_key_encrypted']);
                        $paramsToAdd['akey'] = mb_substr($key, 0, 6) . '***' . mb_substr($key, -3);
                    }
                }
                if (!$urlHasFields) {
                    $paramsToAdd['fields'] = 'sku,name,brand,price,barcode,size,color,flavor,image,purchase_price';
                }
                $debugInfo['full_url_masked'] = $maskedBaseUrl
                    . ($paramsToAdd !== [] ? (str_contains($maskedBaseUrl, '?') ? '&' : '?') . http_build_query($paramsToAdd) : '');
            } catch (\Throwable $e) {
                $debugInfo['full_url_masked'] = '⚠ Impossible de générer l\'URL : ' . $e->getMessage();
            }
        }

        $this->renderApp('pages.catalogue.index', [
            'configured' => $status['configured'],
            'config_message' => $status['message'] ?? null,
            'rows' => $rows,
            'error' => null,
            'filter' => $filter,
            'search' => $search,
            'brand' => $brand,
            'brands' => $brands,
            'sort' => $sort,
            'dir' => $dir,
            'last_synced_at' => $lastSyncedAt,
            'debug_info' => $debugInfo,
            'stats' => [
                'total' => $totalCount,
                'linked' => $linkedCount,
                'unlinked' => $unlinkedCount,
                'filtered' => count($rows),
            ],
        ], [
            'active' => 'catalogue',
            'page_title' => 'Catalogue Nutriweb',
        ]);
    }

    /**
     * GET AJAX : autocomplete recherche de produits Presta par nom/reference.
     * Lit le cache local presta_products (peuple par /produits/sync).
     */
    public function searchPrestaProducts(): void
    {
        Auth::require();
        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->json(['results' => []], 200);
        }
        $q = trim((string) ($this->input('q') ?? ''));
        if (mb_strlen($q) < 2) {
            $this->json(['results' => []], 200);
        }
        $rows = (new PrestaProductRepository())->searchByQuery($client->id, $q, 20);
        $results = array_map(fn($r) => [
            'id' => (string) $r['id'],
            'presta_id' => (int) $r['presta_id'],
            'name' => (string) $r['name'],
            'reference' => (string) ($r['reference'] ?? ''),
            'supplier_reference' => $r['supplier_reference'] !== null ? (string) $r['supplier_reference'] : null,
        ], $rows);
        $this->json(['results' => $results], 200);
    }

    /**
     * POST : fetch live Nutriweb + upsert dans nutriweb_catalog + recompute matches.
     */
    public function sync(): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->redirect('/dashboard');
        }

        $nutriweb = new NutriwebClient($client->id);
        if (!$nutriweb->status()['configured']) {
            $this->flashError('Configuration Nutriweb incomplète. Voir Settings → Nutriweb.');
            $this->redirect('/catalogue');
        }

        // Libere le verrou de session avant l'appel HTTP long.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        try {
            $rows = $nutriweb->fetchCatalog();
        } catch (\Throwable $e) {
            error_log('CatalogueController::sync fetch FAIL: ' . $e->getMessage());
            $this->flashError('Échec du fetch Nutriweb : ' . $e->getMessage());
            $this->redirect('/catalogue');
        }

        // Cas degenere : l'API a repondu sans erreur mais aucun SKU recupere.
        // Causes possibles : cle privee invalide pour cette boutique, URL pointe sur
        // un autre feed, le shop n'a aucun produit chez le provider, JSON malforme...
        if (count($rows) === 0) {
            error_log('CatalogueController::sync fetch OK mais 0 SKU pour client=' . $client->id);
            $maskedUrl = $nutriweb->getMaskedLastCalledUrl();
            $this->flashError(
                'L\'API Nutriweb a répondu sans erreur mais ne contient aucun produit. '
                . 'URL appelée : ' . $maskedUrl . ' '
                . 'Vérifie : (1) la clé privée est associée à cette boutique, '
                . '(2) l\'URL catalogue est correcte (Settings → Nutriweb), '
                . '(3) le compte Nutriweb a au moins 1 produit pour ce shop.'
            );
            $this->redirect('/catalogue');
        }

        $catalogRepo = new NutriwebCatalogRepository();
        try {
            $count = $catalogRepo->upsertBatch($client->id, $rows);
            // Purge les SKUs supprimes cote Nutriweb
            $currentSkus = array_values(array_unique(array_filter(
                array_map(fn($r) => (string) ($r['sku'] ?? ''), $rows),
                fn($s) => $s !== ''
            )));
            $deleted = $catalogRepo->deleteStale($client->id, $currentSkus);
            $catalogRepo->recomputeMatches($client->id);
        } catch (\Throwable $e) {
            error_log('CatalogueController::sync save FAIL: ' . $e->getMessage());
            $this->flashError('Échec sauvegarde : ' . $e->getMessage());
            $this->redirect('/catalogue');
        }

        error_log("CatalogueController::sync OK: client={$client->id} feed={$count} rows, upserted, deleted_stale={$deleted}");
        $msg = $count . ' SKU' . ($count > 1 ? 's' : '') . ' synchronisé' . ($count > 1 ? 's' : '') . '.';
        if ($deleted > 0) {
            $msg .= ' ' . $deleted . ' obsolète' . ($deleted > 1 ? 's' : '') . ' supprimé' . ($deleted > 1 ? 's' : '') . '.';
        }
        $this->flashSuccess($msg);
        $this->redirect('/catalogue');
    }

    /**
     * Page form : affiche les données Nutriweb du SKU (lues en DB) + choix parent/déclinaison.
     */
    public function showCreate(): void
    {
        Auth::require();
        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->redirect('/dashboard');
        }

        $sku = trim((string) ($this->input('sku') ?? ''));
        if ($sku === '') {
            $this->flashError('SKU manquant.');
            $this->redirect('/catalogue');
        }

        $row = (new NutriwebCatalogRepository())->findBySku($client->id, $sku);
        if ($row === null) {
            $this->flashError('SKU "' . $sku . '" introuvable. Synchronise d\'abord le catalogue.');
            $this->redirect('/catalogue');
        }

        // Charge les groupes d'attributs Presta (pour les selects de combination)
        // + listes Presta pour le form produit parent (categories, manufacturers, tax)
        // + auto-match brand Nutriweb -> manufacturer Presta.
        $attrGroups = [];
        $preselectedIds = [];
        $categoriesFlat = [];
        $manufacturers = [];
        $taxGroups = [];
        $preselectedManufacturerId = 0;

        $service = new PrestaShopClient($client);
        if ($service->isConfigured()) {
            try {
                $attrGroups = $service->fetchAttributeGroupsWithValues();
                if ($client->enabledAttributeGroupIds !== null) {
                    $enabled = $client->enabledAttributeGroupIds;
                    $attrGroups = array_values(array_filter($attrGroups, fn($g) => in_array((int) $g['id'], $enabled, true)));
                }
                $preselectedIds = $this->resolveOptionValueIds($service, [
                    $row['size'] ?? null,
                    $row['flavor'] ?? null,
                    $row['color'] ?? null,
                ]);
            } catch (\Throwable) {
                // Best-effort
            }
            $loadErrors = [];
            try {
                $categoriesFlat = $service->fetchCategoriesFlat();
            } catch (\Throwable $e) {
                $loadErrors[] = 'Categories : ' . $e->getMessage();
                error_log('CatalogueController showCreate fetchCategoriesFlat: ' . $e->getMessage());
            }
            try {
                $manufacturers = $service->fetchManufacturers();
                // Auto-match brand Nutriweb -> manufacturer Presta (insensible casse)
                $brand = trim((string) ($row['brand'] ?? ''));
                if ($brand !== '') {
                    $needle = mb_strtolower($brand);
                    foreach ($manufacturers as $m) {
                        if (mb_strtolower($m['name']) === $needle) {
                            $preselectedManufacturerId = (int) $m['id'];
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $loadErrors[] = 'Marques : ' . $e->getMessage();
                error_log('CatalogueController showCreate fetchManufacturers: ' . $e->getMessage());
            }
            try {
                $taxGroups = $service->fetchTaxRulesGroups();
            } catch (\Throwable $e) {
                $loadErrors[] = 'TVA : ' . $e->getMessage();
                error_log('CatalogueController showCreate fetchTaxRulesGroups: ' . $e->getMessage());
            }
            if ($loadErrors !== []) {
                $this->flashError('Certaines listes Presta n\'ont pas pu être chargées : ' . implode(' | ', $loadErrors));
            }
        }

        // Charge les siblings (autres SKUs avec le meme permalink) pour le mode 'Avec declinaisons'.
        // Pour chaque sibling, on resout ses attributs par groupe (map group_id => value_id)
        // pour pre-remplir les selects per row dans le template.
        $siblings = [];
        $permalink = trim((string) ($row['permalink'] ?? ''));
        if ($permalink !== '' && $service->isConfigured()) {
            $rawSiblings = (new NutriwebCatalogRepository())->listByPermalink($client->id, $permalink);
            foreach ($rawSiblings as $sib) {
                $isCurrent = (string) $sib['sku'] === $sku;
                $isLinked = !empty($sib['presta_product_id']) || !empty($sib['presta_combination_id']);
                $sibAttrsByGroup = $isLinked ? [] : self::resolveAttrsByGroup($attrGroups, [
                    $sib['size'] ?? null,
                    $sib['flavor'] ?? null,
                    $sib['color'] ?? null,
                ]);
                $siblings[] = [
                    'sku' => (string) $sib['sku'],
                    'barcode' => (string) ($sib['barcode'] ?? ''),
                    'size' => (string) ($sib['size'] ?? ''),
                    'flavor' => (string) ($sib['flavor'] ?? ''),
                    'color' => (string) ($sib['color'] ?? ''),
                    'image_url' => (string) ($sib['image_url'] ?? ''),
                    'presta_product_id' => (int) ($sib['presta_product_id'] ?? 0),
                    'presta_combination_id' => (int) ($sib['presta_combination_id'] ?? 0),
                    'is_current' => $isCurrent,
                    'is_linked' => $isLinked,
                    'attrs_by_group' => $sibAttrsByGroup,
                ];
            }
        }

        $this->renderApp('pages.catalogue.create', [
            'row' => $row,
            'client' => $client,
            'attr_groups' => $attrGroups,
            'preselected_ids' => $preselectedIds,
            'categories_flat' => $categoriesFlat,
            'manufacturers' => $manufacturers,
            'tax_groups' => $taxGroups,
            'preselected_manufacturer_id' => $preselectedManufacturerId,
            'siblings' => $siblings,
        ], [
            'active' => 'catalogue',
            'page_title' => 'Créer dans PrestaShop — ' . ($row['name'] ?? $sku),
        ]);
    }

    /**
     * Action POST : crée le produit ou la combination dans PrestaShop.
     * Après succès, met à jour la row nutriweb_catalog avec le lien Presta.
     */
    public function create(): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->redirect('/dashboard');
        }

        $sku = trim((string) ($this->input('sku') ?? ''));
        $type = $this->input('type') === 'combination' ? 'combination' : 'product';
        $parentId = (int) ($this->input('parent_id') ?? 0);

        if ($sku === '') {
            $this->flashError('SKU manquant.');
            $this->redirect('/catalogue');
        }

        // Prefixe optionnel pour les references Presta (Settings -> Preset Reference).
        // S'applique aux references parent + combinations. La supplier_reference reste
        // = sku brut (pas prefixee, c'est l'identifiant Nutriweb du fournisseur).
        $refPrefix = (string) ($client->referencePrefix ?? '');
        $applyPrefix = fn(string $ref): string => $refPrefix . $ref;

        $catalogRepo = new NutriwebCatalogRepository();
        $row = $catalogRepo->findBySku($client->id, $sku);
        if ($row === null) {
            $this->flashError('SKU "' . $sku . '" introuvable en DB. Synchronise d\'abord le catalogue.');
            $this->redirect('/catalogue');
        }

        $service = new PrestaShopClient($client);
        if (!$service->isConfigured()) {
            $this->flashError('Clé API PrestaShop non configurée.');
            $this->redirect('/settings?tab=prestashop');
        }

        try {
            if ($type === 'product') {
                // Recupere tous les champs du form (avec defaults safe)
                $rawCats = $_POST['category_ids'] ?? [];
                $categoryIds = [];
                if (is_array($rawCats)) {
                    foreach ($rawCats as $cid) {
                        $i = (int) $cid;
                        if ($i > 0 && !in_array($i, $categoryIds, true)) $categoryIds[] = $i;
                    }
                }
                if ($categoryIds === []) $categoryIds = [2];

                $weight = $this->input('weight');
                $width = $this->input('width');
                $height = $this->input('height');
                $depth = $this->input('depth');
                $toFloat = fn(?string $v): ?float => ($v === null || trim($v) === '') ? null : (float) str_replace(',', '.', $v);

                // Taux de TVA pour conversion retail (TTC Nutriweb) -> HT (champ price PS)
                $taxRate = (float) ($this->input('tax_rate') ?? 0);
                if ($taxRate < 0) $taxRate = 0;
                $ttcToHt = fn(?float $ttc): float => $ttc === null ? 0.0 : ($ttc / (1 + $taxRate));
                // Prix parent = retail HT du SKU courant
                $currentRetail = isset($row['price_retail']) ? (float) $row['price_retail'] : 0.0;
                $parentPriceHt = round($ttcToHt($currentRetail), 6);
                // Prix d'achat parent = price.selling Nutriweb du SKU courant (= prix d'achat
                // Fitadium chez Nutriweb, HT). purchase_price est l'achat amont Nutriweb (info interne).
                $parentWholesale = isset($row['price_selling']) ? max(0.0, (float) $row['price_selling']) : 0.0;

                // Mode 'simple' vs 'with_combinations' : si avec combis, le parent n'a pas d'EAN
                // et sa ref est generique (basee sur permalink). Les SKUs individuels deviennent combinations.
                $subType = $this->input('product_sub_type') === 'with_combinations' ? 'with_combinations' : 'simple';
                $isWithCombi = $subType === 'with_combinations';

                $parentRefRaw = $isWithCombi
                    ? trim((string) ($row['permalink'] ?? $sku)) ?: $sku
                    : $sku;
                $parentRef = $applyPrefix($parentRefRaw);
                $parentEan = $isWithCombi ? '' : (string) ($row['barcode'] ?? '');
                // Toujours definir le fournisseur par defaut sur le parent (id_supplier).
                // En mode 'with_combinations', on n'ajoute pas de ligne product_supplier
                // pour le parent (=skip supplier_reference) : chaque combination aura la
                // sienne avec sa propre ref. En mode simple, le SKU courant est cette ref.
                $parentSupplierId = $client->supplierId;
                $parentSupplierRef = $isWithCombi ? null : $sku;

                $newId = $service->createProduct([
                    'reference' => $parentRef,
                    'ean13' => $parentEan,
                    'name' => trim((string) ($this->input('name') ?? ($row['name'] ?? $sku))),
                    'linkRewrite' => $row['permalink'] !== null ? (string) $row['permalink'] : null,
                    'categoryIds' => $categoryIds,
                    'manufacturerId' => (int) ($this->input('manufacturer_id') ?? 0),
                    'active' => $this->input('active') === '1',
                    'visibility' => (string) ($this->input('visibility') ?? 'both'),
                    'taxRulesGroupId' => (int) ($this->input('tax_rules_group_id') ?? 0),
                    'price' => $parentPriceHt,
                    'wholesalePrice' => $parentWholesale,
                    'descriptionShort' => (string) ($this->input('description_short') ?? ''),
                    'description' => (string) ($this->input('description') ?? ''),
                    'metaTitle' => (string) ($this->input('meta_title') ?? ''),
                    'metaDescription' => (string) ($this->input('meta_description') ?? ''),
                    'metaKeywords' => (string) ($this->input('meta_keywords') ?? ''),
                    'weight' => $toFloat($weight),
                    'width' => $toFloat($width),
                    'height' => $toFloat($height),
                    'depth' => $toFloat($depth),
                    'supplierId' => $parentSupplierId,
                    'supplierReference' => $parentSupplierRef,
                ]);

                // Push image cover du SKU courant si demande
                $imageNote = '';
                if ($this->input('push_image') === '1' && !empty($row['image_url'])) {
                    try {
                        $imgId = $service->uploadProductImageFromUrl($newId, (string) $row['image_url']);
                        $imageNote = ' Image cover poussée (id ' . $imgId . ').';
                    } catch (\Throwable $e) {
                        $imageNote = ' ⚠ Image cover non poussée : ' . $e->getMessage();
                    }
                }

                $stateLabel = $this->input('active') === '1' ? 'actif' : 'inactif';

                if (!$isWithCombi) {
                    // Insert minimal cote cache presta_products pour que les
                    // invalidations orphans futurs ne tuent pas le lien.
                    // reference = avec prefixe (= ce qu'on pousse a PS) ; supplier_reference = sku brut.
                    (new PrestaProductRepository())->insertMinimal(
                        $client->id,
                        $newId,
                        $applyPrefix($sku),
                        $sku,
                        trim((string) ($this->input('name') ?? ($row['name'] ?? $sku))),
                    );
                    $catalogRepo->setPrestaLink($client->id, $sku, $newId, 0);
                    $this->flashSuccess('Produit Presta #' . $newId . ' créé (' . $stateLabel . ').' . $imageNote);
                } else {
                    // Mode 'avec declinaisons' : loop sur les SKUs coches, creer 1 combination par sibling
                    $rawSiblingSkus = $_POST['sibling_skus'] ?? [];
                    $checkedSkus = [];
                    if (is_array($rawSiblingSkus)) {
                        foreach ($rawSiblingSkus as $s) {
                            $s = trim((string) $s);
                            if ($s !== '') $checkedSkus[] = $s;
                        }
                    }
                    // Au minimum on cree la decli du SKU courant
                    if (!in_array($sku, $checkedSkus, true)) {
                        $checkedSkus[] = $sku;
                    }

                    // Attributs choisis par l'user : sibling_attrs[sku][group_id] = value_id
                    $rawSibAttrs = $_POST['sibling_attrs'] ?? [];
                    $createdCount = 0;
                    $failedSkus = [];
                    foreach ($checkedSkus as $skuToCreate) {
                        $sibRow = $catalogRepo->findBySku($client->id, $skuToCreate);
                        if ($sibRow === null) {
                            $failedSkus[] = $skuToCreate . ' (introuvable en DB)';
                            error_log("CatalogueController create combination skip: sku={$skuToCreate} introuvable en DB");
                            continue;
                        }
                        if (!empty($sibRow['presta_combination_id']) || !empty($sibRow['presta_product_id'])) {
                            $failedSkus[] = $skuToCreate . ' (deja lie)';
                            error_log("CatalogueController create combination skip: sku={$skuToCreate} deja lie (p={$sibRow['presta_product_id']}, c={$sibRow['presta_combination_id']})");
                            continue;
                        }
                        // Lit les selects per-group choisis par l'user (sinon fallback auto-resolve)
                        $userValues = is_array($rawSibAttrs[$skuToCreate] ?? null) ? $rawSibAttrs[$skuToCreate] : [];
                        $sibAttrIds = [];
                        foreach ($userValues as $vid) {
                            $v = (int) $vid;
                            if ($v > 0) $sibAttrIds[] = $v;
                        }
                        // Prix delta vs parent : (sibling retail HT) - (parent retail HT)
                        $sibRetail = isset($sibRow['price_retail']) ? (float) $sibRow['price_retail'] : 0.0;
                        $sibPriceHt = round($ttcToHt($sibRetail), 6);
                        $priceImpact = round($sibPriceHt - $parentPriceHt, 6);
                        // Prix d'achat du sibling = price.selling Nutriweb (HT)
                        $sibWholesale = isset($sibRow['price_selling']) ? max(0.0, (float) $sibRow['price_selling']) : 0.0;
                        try {
                            $combId = $service->createCombination(
                                parentProductId: $newId,
                                reference: $skuToCreate,
                                ean13: (string) ($sibRow['barcode'] ?? ''),
                                optionValueIds: $sibAttrIds,
                                supplierId: $client->supplierId,
                                supplierReference: $skuToCreate,
                                priceImpact: $priceImpact,
                                wholesalePrice: $sibWholesale,
                            );
                            // Insert minimal cote caches presta_products + presta_product_combinations
                            // pour preserver le lien des futures invalidations orphans dans /catalogue/sync.
                            // parentRef est deja prefixe ci-dessus.
                            (new PrestaProductRepository())->insertMinimal(
                                $client->id,
                                $newId,
                                $parentRef,
                                null,
                                trim((string) ($this->input('name') ?? ($row['name'] ?? $sku))),
                            );
                            $attrsLabel = '';
                            foreach (['size', 'flavor', 'color'] as $f) {
                                $v = trim((string) ($sibRow[$f] ?? ''));
                                if ($v !== '') $attrsLabel .= ($attrsLabel === '' ? '' : ' · ') . $v;
                            }
                            (new PrestaProductCombinationRepository())->insertMinimal(
                                $client->id,
                                $newId,
                                $combId,
                                $applyPrefix($skuToCreate),
                                (string) ($sibRow['barcode'] ?? ''),
                                $skuToCreate,
                                $attrsLabel !== '' ? $attrsLabel : null,
                            );
                            $catalogRepo->setPrestaLink($client->id, $skuToCreate, $newId, $combId);
                            $createdCount++;
                            error_log("CatalogueController create combination OK: sku={$skuToCreate} -> combId={$combId} (parent={$newId}, attrs=" . json_encode($sibAttrIds) . ")");

                            // Upload image specifique a cette decli + lien combination -> image
                            $sibImage = (string) ($sibRow['image_url'] ?? '');
                            if ($sibImage !== '') {
                                try {
                                    $sibImageId = $service->uploadProductImageFromUrl($newId, $sibImage);
                                    $service->linkImageToCombination($combId, $sibImageId);
                                    error_log("CatalogueController link image OK: sku={$skuToCreate} -> combId={$combId} image={$sibImageId}");
                                } catch (\Throwable $eImg) {
                                    error_log("CatalogueController link image FAIL: sku={$skuToCreate} comb={$combId} error=" . $eImg->getMessage());
                                }
                            }
                        } catch (\Throwable $e) {
                            $failedSkus[] = $skuToCreate . ' (' . mb_substr($e->getMessage(), 0, 60) . ')';
                            error_log("CatalogueController create combination FAIL: sku={$skuToCreate} (parent={$newId}, attrs=" . json_encode($sibAttrIds) . ") error=" . $e->getMessage());
                        }
                    }

                    $msg = 'Produit Presta #' . $newId . ' créé (' . $stateLabel . ') avec '
                        . $createdCount . ' déclinaison' . ($createdCount > 1 ? 's' : '') . '.' . $imageNote;
                    if ($failedSkus !== []) {
                        $msg .= ' ⚠ Échecs : ' . implode(', ', $failedSkus);
                    }
                    $this->flashSuccess($msg);
                }
            } else {
                if ($parentId <= 0) {
                    $this->flashError('ID du produit parent invalide.');
                    $this->redirect('/catalogue/create?sku=' . urlencode($sku));
                }
                $rawIds = $_POST['option_value_ids'] ?? [];
                $optionValueIds = [];
                if (is_array($rawIds)) {
                    foreach ($rawIds as $rid) {
                        $rid = (int) $rid;
                        if ($rid > 0) $optionValueIds[] = $rid;
                    }
                }
                // Prix d'achat = price.selling Nutriweb du SKU courant (HT).
                // Pas de calcul de delta de prix vente : on push 0 (la decli prend le prix
                // du parent par defaut) car on ne connait pas le prix HT du parent en mode
                // standalone sans 2e appel API. User edite dans PS admin si besoin.
                $skuWholesale = isset($row['price_selling']) ? max(0.0, (float) $row['price_selling']) : 0.0;

                $newId = $service->createCombination(
                    parentProductId: $parentId,
                    reference: $applyPrefix($sku),
                    ean13: (string) ($row['barcode'] ?? ''),
                    optionValueIds: $optionValueIds,
                    supplierId: $client->supplierId,
                    supplierReference: $sku,
                    priceImpact: 0.0,
                    wholesalePrice: $skuWholesale,
                );
                $catalogRepo->setPrestaLink($client->id, $sku, $parentId, $newId);
                // Insert minimal cote cache pour preserver le lien des invalidations orphans
                $attrsLabel = '';
                foreach (['size', 'flavor', 'color'] as $f) {
                    $v = trim((string) ($row[$f] ?? ''));
                    if ($v !== '') $attrsLabel .= ($attrsLabel === '' ? '' : ' · ') . $v;
                }
                (new PrestaProductCombinationRepository())->insertMinimal(
                    $client->id,
                    $parentId,
                    $newId,
                    $applyPrefix($sku),
                    (string) ($row['barcode'] ?? ''),
                    $sku,
                    $attrsLabel !== '' ? $attrsLabel : null,
                );

                // Push image cover de la decli si presente
                $imageNote = '';
                if (!empty($row['image_url'])) {
                    try {
                        $sibImageId = $service->uploadProductImageFromUrl($parentId, (string) $row['image_url']);
                        $service->linkImageToCombination($newId, $sibImageId);
                        $imageNote = ' Image poussée (id ' . $sibImageId . ').';
                    } catch (\Throwable $eImg) {
                        $imageNote = ' ⚠ Image non poussée : ' . $eImg->getMessage();
                        error_log("CatalogueController standalone decli link image FAIL: sku={$sku} comb={$newId} error=" . $eImg->getMessage());
                    }
                }

                $note = $optionValueIds === [] ? ' (aucun attribut matché, à compléter dans Presta admin)' : '';
                $this->flashSuccess('Déclinaison #' . $newId . ' créée sur produit Presta #' . $parentId . $note . $imageNote);
            }
        } catch (\Throwable $e) {
            $this->flashError('Échec de la création : ' . $e->getMessage());
            $this->redirect('/catalogue/create?sku=' . urlencode($sku));
        }

        $this->redirect('/catalogue');
    }

    /**
     * Pour chaque groupe d'attribut Presta donne, trouve la valeur dont le label
     * matche un des labels Nutriweb (insensible casse). Une seule valeur par groupe.
     *
     * @param list<array{id:int, name:string, values:list<array{id:int, label:string}>}> $groups
     * @param list<?string> $labels
     * @return array<int, int> Map group_id => value_id
     */
    private static function resolveAttrsByGroup(array $groups, array $labels): array
    {
        $needles = array_map('mb_strtolower', array_values(array_filter(array_map('strval', $labels), fn($l) => $l !== '')));
        if ($needles === []) return [];
        $map = [];
        foreach ($groups as $g) {
            foreach ($g['values'] as $v) {
                if (in_array(mb_strtolower($v['label']), $needles, true)) {
                    $map[(int) $g['id']] = (int) $v['id'];
                    break;
                }
            }
        }
        return $map;
    }

    /**
     * Matche les labels Nutriweb (size, flavor, color) avec les ids des
     * product_option_values Presta. Strict match insensible à la casse.
     *
     * @param list<?string> $labels
     * @return list<int>
     */
    private function resolveOptionValueIds(PrestaShopClient $service, array $labels): array
    {
        $cleanLabels = array_values(array_filter(array_map('strval', $labels), fn($l) => $l !== ''));
        if ($cleanLabels === []) return [];

        try {
            $index = $service->fetchAttributeIndex();
        } catch (\Throwable) {
            return [];
        }

        $needles = array_map('mb_strtolower', $cleanLabels);
        $matched = [];
        foreach ($index as $id => $info) {
            if (in_array(mb_strtolower($info['label']), $needles, true)) {
                $matched[] = $id;
            }
        }
        return $matched;
    }

    /**
     * Pour chaque row de nutriweb_catalog, calcule la cle 'match' attendue par le template :
     * {type, product_uuid, presta_id, presta_combination_id?, attributes}
     *
     * - Match combination prioritaire (plus specifique)
     * - On lookupe le UUID de presta_products (pour le lien /produits/{uuid})
     * - On lookupe les attributs label pour les combinations
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function enrichRowsWithMatch(string $clientId, array $rows): array
    {
        // Collecte les IDs uniques a resoudre
        $productIds = [];
        $combinationIds = [];
        foreach ($rows as $r) {
            if (!empty($r['presta_combination_id'])) {
                $combinationIds[(int) $r['presta_combination_id']] = true;
            }
            if (!empty($r['presta_product_id'])) {
                $productIds[(int) $r['presta_product_id']] = true;
            }
        }

        $pdo = Database::pdo();
        $uuidByProductId = [];
        $refByProductId = [];
        if ($productIds !== []) {
            $ids = array_keys($productIds);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                'SELECT presta_id, id, reference FROM presta_products WHERE client_id = ? AND presta_id IN (' . $placeholders . ')'
            );
            $stmt->execute(array_merge([$clientId], $ids));
            foreach ($stmt->fetchAll() as $r) {
                $uuidByProductId[(int) $r['presta_id']] = (string) $r['id'];
                $refByProductId[(int) $r['presta_id']] = (string) ($r['reference'] ?? '');
            }
        }

        $attrsByCombId = [];
        $refByCombId = [];
        if ($combinationIds !== []) {
            $ids = array_keys($combinationIds);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                'SELECT presta_combination_id, attributes_label, reference
                   FROM presta_product_combinations
                  WHERE client_id = ? AND presta_combination_id IN (' . $placeholders . ')'
            );
            $stmt->execute(array_merge([$clientId], $ids));
            foreach ($stmt->fetchAll() as $r) {
                $attrsByCombId[(int) $r['presta_combination_id']] = (string) ($r['attributes_label'] ?? '');
                $refByCombId[(int) $r['presta_combination_id']] = (string) ($r['reference'] ?? '');
            }
        }

        // Build enriched rows
        $result = [];
        foreach ($rows as $r) {
            $cid = !empty($r['presta_combination_id']) ? (int) $r['presta_combination_id'] : 0;
            $pid = !empty($r['presta_product_id']) ? (int) $r['presta_product_id'] : 0;
            $match = null;
            if ($cid > 0) {
                // Pour une decli, la ref Presta significative est celle de la combination.
                $combRef = $refByCombId[$cid] ?? '';
                $match = [
                    'type' => 'combination',
                    'product_uuid' => $uuidByProductId[$pid] ?? null,
                    'presta_id' => $pid,
                    'presta_combination_id' => $cid,
                    'attributes' => $attrsByCombId[$cid] ?? null,
                    'reference' => $combRef !== '' ? $combRef : ($refByProductId[$pid] ?? ''),
                ];
            } elseif ($pid > 0) {
                $match = [
                    'type' => 'product',
                    'product_uuid' => $uuidByProductId[$pid] ?? null,
                    'presta_id' => $pid,
                    'attributes' => null,
                    'reference' => $refByProductId[$pid] ?? '',
                ];
            }
            // Normalise les types numeriques pour la template (qui s'attend a int/float pas string)
            $r['size_rank'] = $r['size_rank'] !== null ? (int) $r['size_rank'] : null;
            $r['stock'] = isset($r['stock']) && $r['stock'] !== null && $r['stock'] !== '' ? (int) $r['stock'] : null;
            $r['price_base'] = $r['price_base'] !== null ? (float) $r['price_base'] : null;
            $r['price_selling'] = $r['price_selling'] !== null ? (float) $r['price_selling'] : null;
            $r['price_retail'] = $r['price_retail'] !== null ? (float) $r['price_retail'] : null;
            $r['match'] = $match;
            $result[] = $r;
        }
        return $result;
    }
}
