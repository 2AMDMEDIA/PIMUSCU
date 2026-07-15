<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Encryption;
use App\Models\Client;
use RuntimeException;

/**
 * Client REST minimaliste pour le Webservice natif de PrestaShop.
 *
 * Auth : Basic Auth, username = clé API, password vide.
 * Format : on demande JSON via header Output-Format / paramètre output_format=JSON
 *          (depuis Presta 1.7.x, certains shops ne renvoient que XML — on parse XML en fallback).
 */
final class PrestaShopClient
{
    public function __construct(
        private readonly Client $client,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->client->prestashopUrl !== '' && $this->client->prestashopApiKeyEncrypted !== null;
    }

    /**
     * Test de connexion : appelle /api et vérifie que la réponse contient les ressources Webservice.
     *
     * @return array{ok:bool,message:string,api_version?:?string}
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'URL ou clé API non configurée.'];
        }

        try {
            $body = $this->get('/api', [], asJson: false);
            // Réponse XML : <prestashop><api>...</api></prestashop>
            $xml = @simplexml_load_string($body);
            if ($xml === false || !isset($xml->api)) {
                return ['ok' => false, 'message' => 'Réponse PrestaShop invalide. Vérifiez la clé API et que le Webservice est activé.'];
            }
            $version = isset($xml->api['shopVersion']) ? (string) $xml->api['shopVersion'] : null;
            return ['ok' => true, 'message' => 'Connexion réussie.', 'api_version' => $version];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Échec : ' . $e->getMessage()];
        }
    }

    /**
     * Récupère toutes les catégories avec les champs essentiels.
     *
     * @return list<array{
     *     id:int, parent_id:int, name:string, description:string,
     *     meta_title:string, meta_description:string, meta_keywords:string,
     *     link_rewrite:string, active:int, is_root_category:int,
     * }>
     */
    public function fetchAllCategories(): array
    {
        $display = '[id,id_parent,name,description,meta_title,meta_description,meta_keywords,link_rewrite,active,is_root_category]';
        $body = $this->get('/api/categories', [
            'display' => $display,
            'limit' => '0,5000',
        ], asJson: true);

        $data = $this->decodeJsonOrXml($body, 'categories', 'category');

        $result = [];
        foreach ($data as $row) {
            $result[] = [
                'id' => (int) ($row['id'] ?? 0),
                'parent_id' => (int) ($row['id_parent'] ?? 0),
                'name' => $this->extractLanguageValue($row['name'] ?? ''),
                'description' => $this->extractLanguageValue($row['description'] ?? ''),
                'meta_title' => $this->extractLanguageValue($row['meta_title'] ?? ''),
                'meta_description' => $this->extractLanguageValue($row['meta_description'] ?? ''),
                'meta_keywords' => $this->extractLanguageValue($row['meta_keywords'] ?? ''),
                'link_rewrite' => $this->extractLanguageValue($row['link_rewrite'] ?? ''),
                'active' => (int) ($row['active'] ?? 1),
                'is_root_category' => (int) ($row['is_root_category'] ?? 0),
            ];
        }
        return $result;
    }

    /**
     * Récupère une catégorie complète (XML, indispensable pour le PUT qui suit).
     */
    public function fetchCategoryXml(int $prestaId): \SimpleXMLElement
    {
        $body = $this->get('/api/categories/' . $prestaId, [], asJson: false);
        $xml = @simplexml_load_string($body);
        if ($xml === false || !isset($xml->category)) {
            throw new RuntimeException('Impossible de récupérer la catégorie #' . $prestaId);
        }
        return $xml;
    }

    /**
     * Champs read-only de la ressource `category` qui font échouer le PUT s'ils sont
     * laissés dans le payload. La liste vient de l'erreur Presta « parameter X not writable ».
     */
    private const CATEGORY_READONLY_FIELDS = [
        // 'id' DOIT rester — requis par Presta pour identifier la ressource modifiée
        'level_depth',             // calculé par Presta
        'nb_products_recursive',   // calculé
        'nleft', 'nright',         // nested set tree (calculés)
        'date_add', 'date_upd',    // timestamps gérés par Presta
        'position',                // géré séparément via /api/categories?display=...
    ];

    /**
     * Met à jour les champs description/meta_title/meta_description (langue par défaut)
     * d'une catégorie PrestaShop via PUT /api/categories/{id}.
     *
     * On récupère le XML complet, on supprime les champs read-only, on modifie les champs
     * cibles, on renvoie le tout — façon canonique de patcher une ressource via Webservice.
     */
    public function updateCategoryFields(int $prestaId, array $fields): void
    {
        $xml = $this->fetchCategoryXml($prestaId);
        $category = $xml->category;

        // Strip read-only fields
        $dom = dom_import_simplexml($category);
        foreach (self::CATEGORY_READONLY_FIELDS as $field) {
            $nodes = [];
            foreach ($dom->childNodes as $node) {
                if ($node->nodeName === $field) {
                    $nodes[] = $node;
                }
            }
            foreach ($nodes as $node) {
                $dom->removeChild($node);
            }
        }

        // Refresh la référence SimpleXML après les modifications DOM
        $xmlString = $dom->ownerDocument->saveXML();
        $xml = simplexml_load_string($xmlString);
        $category = $xml->category;

        // Champs cibles : pour les multilingues, on met à jour la première langue.
        foreach (['name', 'description', 'meta_title', 'meta_description', 'meta_keywords'] as $field) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            $value = (string) $fields[$field];
            if (isset($category->{$field}->language)) {
                $category->{$field}->language[0] = $value;
            } elseif (isset($category->{$field})) {
                $category->{$field} = $value;
            }
            // Sinon : le champ n'existe pas dans le XML retourné par Presta → on skip silencieusement.
        }

        $body = $xml->asXML();
        if ($body === false) {
            throw new RuntimeException('Sérialisation XML échouée.');
        }

        $this->put('/api/categories/' . $prestaId, $body);
    }

    /**
     * Champs read-only de la ressource `product`.
     */
    private const PRODUCT_READONLY_FIELDS = [
        'manufacturer_name',
        'quantity',
        'position_in_category',
        'date_add',
        'date_upd',
        'pack_stock_type',
        // 'id' DOIT rester
    ];

    /**
     * Détection de contenu enrichi (Elementor / hooks / images CMS custom) sur la description longue.
     * Identique à la détection 2AMD-MEDIA-HUB. Hardcodé pour le MVP, sortable en constante config plus tard.
     */
    private const CMS_CONTENT_REGEX = '/\{hook\s|elementor|\/img\/cms\/produit_cms\//i';

    /**
     * Récupère UN batch de produits (pour pagination cote appelant). Voir streamAllProducts().
     *
     * @return list<array{
     *     id:int, reference:string, name:string, price:float, active:int,
     *     description:string, description_short:string,
     *     meta_title:string, meta_description:string, link_rewrite:string,
     *     id_default_image:?int, id_category_default:int,
     *     has_cms_content:bool, has_description:bool, image_url:?string,
     * }>
     */
    public function fetchProductsBatch(int $offset, int $limit): array
    {
        $display = '[id,reference,name,manufacturer_name,price,wholesale_price,active,description,description_short,'
            . 'meta_title,meta_description,meta_keywords,link_rewrite,id_default_image,id_category_default]';
        $body = $this->get('/api/products', [
            'display' => $display,
            'filter[active]' => '[0,1]',
            'limit' => $offset . ',' . $limit,
        ], asJson: true);

        $rows = $this->decodeJsonOrXml($body, 'products', 'product');
        // Libère le buffer source avant le mapping (économise la mémoire pic).
        unset($body);

        $shopUrl = rtrim($this->client->prestashopUrl, '/');
        $result = [];
        foreach ($rows as $row) {
            $description = $this->extractLanguageValue($row['description'] ?? '');
            $descriptionShort = $this->extractLanguageValue($row['description_short'] ?? '');
            $hasCmsContent = $description !== '' && preg_match(self::CMS_CONTENT_REGEX, $description) === 1;
            $hasDescription = !$hasCmsContent && trim(strip_tags($description)) !== '';
            $imageId = isset($row['id_default_image']) ? (int) $row['id_default_image'] : 0;
            $productId = (int) ($row['id'] ?? 0);
            $linkRewrite = $this->extractLanguageValue($row['link_rewrite'] ?? '');

            $imageUrl = null;
            if ($imageId > 0) {
                if ($linkRewrite !== '') {
                    $imageUrl = $shopUrl . '/' . $imageId . '-medium_default/' . $linkRewrite . '.jpg';
                } else {
                    $split = implode('/', str_split((string) $imageId));
                    $imageUrl = $shopUrl . '/img/p/' . $split . '/' . $imageId . '-medium_default.jpg';
                }
            }

            $result[] = [
                'id' => $productId,
                'reference' => (string) ($row['reference'] ?? ''),
                'name' => $this->extractLanguageValue($row['name'] ?? ''),
                'manufacturer_name' => trim((string) ($row['manufacturer_name'] ?? '')),
                'price' => (float) ($row['price'] ?? 0),
                'wholesale_price' => (float) ($row['wholesale_price'] ?? 0),
                'active' => (int) ($row['active'] ?? 1),
                'description' => $description,
                'description_short' => $descriptionShort,
                'meta_title' => $this->extractLanguageValue($row['meta_title'] ?? ''),
                'meta_description' => $this->extractLanguageValue($row['meta_description'] ?? ''),
                'meta_keywords' => $this->extractLanguageValue($row['meta_keywords'] ?? ''),
                'link_rewrite' => $linkRewrite,
                'id_default_image' => $imageId > 0 ? $imageId : null,
                'id_category_default' => (int) ($row['id_category_default'] ?? 0),
                'has_cms_content' => $hasCmsContent,
                'has_description' => $hasDescription,
                'image_url' => $imageUrl,
            ];
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Combinations (declinaisons)
    // -------------------------------------------------------------------------

    /**
     * Index des labels d'attributs (taille / saveur / couleur). Pré-charge tous les
     * product_options (groupes) + product_option_values (valeurs) en une fois,
     * pour permettre la résolution rapide des combinations.
     *
     * @return array<int, array{label:string, group:string}> Map id_attribute_value => {label, group}
     */
    public function fetchAttributeIndex(): array
    {
        // 1) Groupes d'attributs (taille, saveur, etc.)
        $body = $this->get('/api/product_options', [
            'display' => 'full',
            'limit' => '0,500',
        ], asJson: true);
        $groupsRows = $this->decodeJsonOrXml($body, 'product_options', 'product_option');
        unset($body);
        $groups = [];
        foreach ($groupsRows as $g) {
            $id = (int) ($g['id'] ?? 0);
            if ($id <= 0) continue;
            $groups[$id] = $this->extractLanguageValue($g['name'] ?? '');
        }

        // 2) Valeurs d'attributs (1820g, Chocolate, Rouge, etc.)
        $body = $this->get('/api/product_option_values', [
            'display' => 'full',
            'limit' => '0,5000',
        ], asJson: true);
        $valuesRows = $this->decodeJsonOrXml($body, 'product_option_values', 'product_option_value');
        unset($body);
        $map = [];
        foreach ($valuesRows as $v) {
            $id = (int) ($v['id'] ?? 0);
            $groupId = (int) ($v['id_attribute_group'] ?? 0);
            if ($id <= 0) continue;
            $map[$id] = [
                'label' => $this->extractLanguageValue($v['name'] ?? ''),
                'group' => $groups[$groupId] ?? '',
            ];
        }
        return $map;
    }

    /**
     * Variante de fetchAttributeIndex qui retourne les attributs groupés
     * (utilisé pour les selects par groupe sur le form de création combination).
     *
     * @return list<array{id:int, name:string, values:list<array{id:int, label:string}>}>
     *   Trie : groupes alpha, valeurs alpha dans chaque groupe.
     */
    public function fetchAttributeGroupsWithValues(): array
    {
        $body = $this->get('/api/product_options', [
            'display' => 'full',
            'limit' => '0,500',
        ], asJson: true);
        $groupsRows = $this->decodeJsonOrXml($body, 'product_options', 'product_option');
        unset($body);
        $groups = [];
        foreach ($groupsRows as $g) {
            $id = (int) ($g['id'] ?? 0);
            if ($id <= 0) continue;
            $groups[$id] = [
                'id' => $id,
                'name' => $this->extractLanguageValue($g['name'] ?? ''),
                'values' => [],
            ];
        }

        $body = $this->get('/api/product_option_values', [
            'display' => 'full',
            'limit' => '0,5000',
        ], asJson: true);
        $valuesRows = $this->decodeJsonOrXml($body, 'product_option_values', 'product_option_value');
        unset($body);
        foreach ($valuesRows as $v) {
            $id = (int) ($v['id'] ?? 0);
            $groupId = (int) ($v['id_attribute_group'] ?? 0);
            $label = $this->extractLanguageValue($v['name'] ?? '');
            if ($id <= 0 || $label === '' || !isset($groups[$groupId])) continue;
            $groups[$groupId]['values'][] = ['id' => $id, 'label' => $label];
        }

        foreach ($groups as &$g) {
            usort($g['values'], fn($a, $b) => strnatcasecmp($a['label'], $b['label']));
        }
        unset($g);

        $result = array_values($groups);
        usort($result, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return $result;
    }

    /**
     * Récupère UN batch de combinaisons (pagination cote appelant).
     *
     * @return list<array{
     *     id:int, id_product:int, reference:string, ean13:string,
     *     option_value_ids:list<int>,
     * }>
     */
    public function fetchCombinationsBatch(int $offset, int $limit): array
    {
        // display=full pour récupérer associations.product_option_values (pas dispo avec display=[...])
        $body = $this->get('/api/combinations', [
            'display' => 'full',
            'limit' => $offset . ',' . $limit,
        ], asJson: true);
        $rows = $this->decodeJsonOrXml($body, 'combinations', 'combination');
        unset($body);

        $result = [];
        foreach ($rows as $row) {
            $optionValueIds = [];
            $assoc = $row['associations']['product_option_values'] ?? null;
            if (is_array($assoc)) {
                // L'API renvoie soit ['product_option_value' => [...]], soit directement la liste
                $vals = $assoc['product_option_value'] ?? $assoc;
                if (is_array($vals)) {
                    // Cas 1 valeur : {id: X}. Cas N valeurs : [{id:X}, {id:Y}].
                    if (isset($vals['id'])) {
                        $optionValueIds[] = (int) $vals['id'];
                    } else {
                        foreach ($vals as $v) {
                            if (is_array($v) && isset($v['id'])) {
                                $optionValueIds[] = (int) $v['id'];
                            }
                        }
                    }
                }
            }
            $result[] = [
                'id' => (int) ($row['id'] ?? 0),
                'id_product' => (int) ($row['id_product'] ?? 0),
                'reference' => trim((string) ($row['reference'] ?? '')),
                'ean13' => trim((string) ($row['ean13'] ?? '')),
                'option_value_ids' => $optionValueIds,
            ];
        }
        return $result;
    }

    /**
     * Stream toutes les combinaisons par batches. Mémoire plate (idem streamAllProducts).
     *
     * @param callable(list<array<string,mixed>>):void $onBatch
     */
    public function streamAllCombinations(callable $onBatch, int $batchSize = 200): int
    {
        $offset = 0;
        $total = 0;
        $safetyCap = 200000;
        while ($total < $safetyCap) {
            $batch = $this->fetchCombinationsBatch($offset, $batchSize);
            $count = count($batch);
            if ($count === 0) break;
            $onBatch($batch);
            $total += $count;
            unset($batch);
            if ($count < $batchSize) break;
            $offset += $batchSize;
        }
        return $total;
    }

    /**
     * Récupère les refs fournisseur AU NIVEAU COMBINAISON (id_product_attribute > 0)
     * pour un id_supplier donné. Complémentaire à fetchProductSuppliersBySupplier()
     * qui couvre les produits racine (id_product_attribute = 0).
     *
     * @return array<int, string> Map presta_combination_id => product_supplier_reference
     */
    public function fetchCombinationSuppliersBySupplier(int $supplierId): array
    {
        if ($supplierId <= 0) return [];
        $body = $this->get('/api/product_suppliers', [
            'display' => '[id_product_attribute,product_supplier_reference]',
            'filter[id_supplier]' => (string) $supplierId,
            'limit' => '0,20000',
        ], asJson: true);
        $rows = $this->decodeJsonOrXml($body, 'product_suppliers', 'product_supplier');
        unset($body);

        $map = [];
        foreach ($rows as $row) {
            $attrId = (int) ($row['id_product_attribute'] ?? 0);
            $ref = trim((string) ($row['product_supplier_reference'] ?? ''));
            if ($attrId <= 0 || $ref === '') continue;
            $map[$attrId] = $ref;
        }
        return $map;
    }

    /**
     * Récupère les références fournisseur pour TOUS les produits d'un id_supplier donné.
     * Privilégie la ligne id_product_attribute = 0 (produit racine, sans déclinaison).
     *
     * @return array<int, string> Map presta_product_id => product_supplier_reference
     */
    public function fetchProductSuppliersBySupplier(int $supplierId): array
    {
        if ($supplierId <= 0) return [];
        $body = $this->get('/api/product_suppliers', [
            'display' => '[id_product,id_product_attribute,product_supplier_reference]',
            'filter[id_supplier]' => (string) $supplierId,
            'limit' => '0,20000',
        ], asJson: true);

        $rows = $this->decodeJsonOrXml($body, 'product_suppliers', 'product_supplier');
        unset($body);

        $map = [];
        foreach ($rows as $row) {
            $productId = (int) ($row['id_product'] ?? 0);
            $attrId = (int) ($row['id_product_attribute'] ?? 0);
            $ref = trim((string) ($row['product_supplier_reference'] ?? ''));
            if ($productId <= 0 || $ref === '') continue;
            // attr=0 = racine produit, prioritaire. Sinon on prend la 1ere déclinaison vue.
            if ($attrId === 0 || !isset($map[$productId])) {
                $map[$productId] = $ref;
            }
        }
        return $map;
    }

    /**
     * Stream tous les produits par batches. $onBatch reçoit chaque batch (1 fois par requête HTTP).
     * Le client n'accumule rien — la mémoire reste plate quel que soit le nombre total de produits.
     *
     * @param callable(list<array<string,mixed>>):void $onBatch
     * @param int $batchSize Taille de batch (defaut 100 — bon compromis bande passante/memoire)
     * @return int Nombre total de produits traités
     */
    public function streamAllProducts(callable $onBatch, int $batchSize = 100): int
    {
        $offset = 0;
        $total = 0;
        // Cap dur de sécurité pour éviter une boucle infinie si l'API renvoie toujours autant.
        $safetyCap = 100000;
        while ($total < $safetyCap) {
            $batch = $this->fetchProductsBatch($offset, $batchSize);
            $count = count($batch);
            if ($count === 0) break;

            $onBatch($batch);
            $total += $count;
            unset($batch);

            if ($count < $batchSize) break;
            $offset += $batchSize;
        }
        return $total;
    }

    /**
     * Retourne tous les IDs d'images associées à un produit (cover + galerie).
     * Le premier élément du tableau est l'image de couverture si elle est marquée comme telle.
     *
     * @return list<int>
     */
    public function fetchProductImageIds(int $prestaId): array
    {
        try {
            $xml = $this->fetchProductXml($prestaId);
        } catch (\Throwable) {
            return [];
        }
        $product = $xml->product;
        if (!isset($product->associations->images->image)) {
            return [];
        }
        $ids = [];
        foreach ($product->associations->images->image as $img) {
            $id = (int) $img->id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Construit l'URL friendly d'une image produit pour un type donné (medium_default, large_default, home_default…).
     */
    public function buildProductImageUrl(int $imageId, string $linkRewrite, string $imageType = 'medium_default'): string
    {
        $shopUrl = rtrim($this->client->prestashopUrl, '/');
        if ($linkRewrite !== '') {
            return $shopUrl . '/' . $imageId . '-' . $imageType . '/' . $linkRewrite . '.jpg';
        }
        $split = implode('/', str_split((string) $imageId));
        return $shopUrl . '/img/p/' . $split . '/' . $imageId . '-' . $imageType . '.jpg';
    }

    /**
     * Récupère le XML complet d'un produit (pour PUT).
     */
    public function fetchProductXml(int $prestaId): \SimpleXMLElement
    {
        $body = $this->get('/api/products/' . $prestaId, [], asJson: false);
        $xml = @simplexml_load_string($body);
        if ($xml === false || !isset($xml->product)) {
            throw new RuntimeException('Impossible de récupérer le produit #' . $prestaId);
        }
        return $xml;
    }

    /**
     * Met à jour des champs d'un produit via PUT XML (strip des champs read-only).
     *
     * @param array<string,string> $fields  Clés acceptées : description, description_short, meta_title, meta_description
     */
    public function updateProductFields(int $prestaId, array $fields): void
    {
        $xml = $this->fetchProductXml($prestaId);
        $product = $xml->product;

        // Strip read-only fields
        $dom = dom_import_simplexml($product);
        foreach (self::PRODUCT_READONLY_FIELDS as $field) {
            $nodes = [];
            foreach ($dom->childNodes as $node) {
                if ($node->nodeName === $field) {
                    $nodes[] = $node;
                }
            }
            foreach ($nodes as $node) {
                $dom->removeChild($node);
            }
        }

        $xmlString = $dom->ownerDocument->saveXML();
        $xml = simplexml_load_string($xmlString);
        $product = $xml->product;

        foreach (['description', 'description_short', 'meta_title', 'meta_description', 'meta_keywords'] as $field) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            $value = (string) $fields[$field];
            if (isset($product->{$field}->language)) {
                $product->{$field}->language[0] = $value;
            } else {
                $product->{$field} = $value;
            }
        }

        $body = $xml->asXML();
        if ($body === false) {
            throw new RuntimeException('Sérialisation XML échouée.');
        }
        $this->put('/api/products/' . $prestaId, $body);
    }

    /**
     * Compte les produits associés à chaque catégorie via la ressource /categories/{id}.
     * Pour un MVP rapide, on récupère les associations en une passe sur /products?display=[id,id_category_default].
     *
     * @return array<int,int> Map presta_category_id => count
     */
    public function fetchProductsCountByCategory(): array
    {
        $body = $this->get('/api/products', [
            'display' => '[id,id_category_default]',
            'filter[active]' => '[0,1]',
            'limit' => '0,10000',
        ], asJson: true);

        $data = $this->decodeJsonOrXml($body, 'products', 'product');

        $counts = [];
        foreach ($data as $row) {
            $catId = (int) ($row['id_category_default'] ?? 0);
            if ($catId > 0) {
                $counts[$catId] = ($counts[$catId] ?? 0) + 1;
            }
        }
        return $counts;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Cherche un cacert.pem utilisable, dans l'ordre :
     *  1. APP_CA_BUNDLE (override .env)
     *  2. composer/ca-bundle (Mozilla bundle embarqué avec le projet — toujours présent en prod)
     *  3. ini curl.cainfo / openssl.cafile (fallback si la lib n'est pas dispo)
     *  Retourne null en dernier recours → cURL utilise ses propres défauts système.
     */
    private function resolveCaBundlePath(): ?string
    {
        $override = $_ENV['APP_CA_BUNDLE'] ?? null;
        if (is_string($override) && $override !== '' && is_file($override)) {
            return $override;
        }

        if (class_exists(\Composer\CaBundle\CaBundle::class)) {
            $path = \Composer\CaBundle\CaBundle::getBundledCaBundlePath();
            if (is_file($path)) {
                return $path;
            }
        }

        foreach ([ini_get('curl.cainfo'), ini_get('openssl.cafile')] as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    private function apiKey(): string
    {
        if ($this->client->prestashopApiKeyEncrypted === null) {
            throw new RuntimeException('Clé API PrestaShop non configurée.');
        }
        return Encryption::decrypt($this->client->prestashopApiKeyEncrypted);
    }

    /**
     * Liste les specific_prices actives pour un produit donné.
     * @return list<array{id:int,reduction:float,reduction_type:string,reduction_tax:int,from:string,to:string,price:float}>
     */
    public function listSpecificPricesForProduct(int $productId): array
    {
        $body = $this->get('/api/specific_prices', [
            'display' => '[id,id_product,reduction,reduction_type,reduction_tax,from,to,price]',
            'filter[id_product]' => (string) $productId,
            'limit' => '0,200',
        ], asJson: true);
        $rows = $this->decodeJsonOrXml($body, 'specific_prices', 'specific_price');
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int) ($row['id'] ?? 0),
                'reduction' => (float) ($row['reduction'] ?? 0),
                'reduction_type' => (string) ($row['reduction_type'] ?? 'percentage'),
                'reduction_tax' => (int) ($row['reduction_tax'] ?? 1),
                'from' => (string) ($row['from'] ?? ''),
                'to' => (string) ($row['to'] ?? ''),
                'price' => (float) ($row['price'] ?? -1),
            ];
        }
        return $result;
    }

    /**
     * Liste toutes les specific_prices du shop (tous produits confondus).
     * @return list<array{id:int, id_product:int, reduction:float, reduction_type:string, reduction_tax:int, from:string, to:string, price:float}>
     */
    public function fetchAllSpecificPrices(int $limit = 500): array
    {
        $body = $this->get('/api/specific_prices', [
            'display' => '[id,id_product,reduction,reduction_type,reduction_tax,from,to,price]',
            'limit' => '0,' . $limit,
        ], asJson: true);
        $rows = $this->decodeJsonOrXml($body, 'specific_prices', 'specific_price');
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int) ($row['id'] ?? 0),
                'id_product' => (int) ($row['id_product'] ?? 0),
                'reduction' => (float) ($row['reduction'] ?? 0),
                'reduction_type' => (string) ($row['reduction_type'] ?? 'percentage'),
                'reduction_tax' => (int) ($row['reduction_tax'] ?? 1),
                'from' => (string) ($row['from'] ?? ''),
                'to' => (string) ($row['to'] ?? ''),
                'price' => (float) ($row['price'] ?? -1),
            ];
        }
        return $result;
    }

    /** Supprime une specific_price par son id. */
    public function deleteSpecificPrice(int $id): void
    {
        $this->delete('/api/specific_prices/' . $id);
    }

    /**
     * Crée une nouvelle specific_price (promo flash).
     * @param array{id_product:int, reduction:float, reduction_type:'percentage'|'amount', from:string, to:string} $fields
     * @return int L'id Presta de la specific_price créée
     */
    public function createSpecificPrice(array $fields): int
    {
        $reduction = number_format($fields['reduction'], 6, '.', '');
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<prestashop xmlns:xlink="http://www.w3.org/1999/xlink">
    <specific_price>
        <id_specific_price_rule>0</id_specific_price_rule>
        <id_cart>0</id_cart>
        <id_product>{$fields['id_product']}</id_product>
        <id_shop>1</id_shop>
        <id_shop_group>0</id_shop_group>
        <id_currency>0</id_currency>
        <id_country>0</id_country>
        <id_group>0</id_group>
        <id_customer>0</id_customer>
        <id_product_attribute>0</id_product_attribute>
        <price>-1</price>
        <from_quantity>1</from_quantity>
        <reduction>{$reduction}</reduction>
        <reduction_tax>1</reduction_tax>
        <reduction_type>{$fields['reduction_type']}</reduction_type>
        <from>{$fields['from']}</from>
        <to>{$fields['to']}</to>
    </specific_price>
</prestashop>
XML;
        $body = $this->post('/api/specific_prices', $xml);
        $xmlResp = @simplexml_load_string($body);
        if ($xmlResp === false) {
            throw new RuntimeException('Réponse Presta invalide après création specific_price.');
        }
        $id = isset($xmlResp->specific_price->id) ? (int) $xmlResp->specific_price->id : 0;
        if ($id === 0) {
            throw new RuntimeException('Impossible de récupérer l\'id de la specific_price créée.');
        }
        return $id;
    }

    /**
     * CONTRÔLE : retourne les id_product qui ont une ligne ps_product_supplier
     * AU NIVEAU PRODUIT (id_product_attribute = 0) pour le fournisseur donné.
     * Map id_product => product_supplier_reference (attr=0).
     *
     * @return array<int, string>
     */
    public function fetchProductLevelSupplierRefs(int $supplierId): array
    {
        if ($supplierId <= 0) return [];
        $body = $this->get('/api/product_suppliers', [
            'display' => '[id_product,id_product_attribute,product_supplier_reference]',
            'filter[id_supplier]' => (string) $supplierId,
            'filter[id_product_attribute]' => '0',
            'limit' => '0,50000',
        ], asJson: true);
        $rows = $this->decodeJsonOrXml($body, 'product_suppliers', 'product_supplier');
        unset($body);
        $map = [];
        foreach ($rows as $row) {
            $attrId = (int) ($row['id_product_attribute'] ?? -1);
            if ($attrId !== 0) continue; // securite : on ne garde QUE le niveau produit
            $productId = (int) ($row['id_product'] ?? 0);
            if ($productId <= 0) continue;
            $map[$productId] = trim((string) ($row['product_supplier_reference'] ?? ''));
        }
        return $map;
    }

    /**
     * Retourne l'ensemble des id_product appartenant aux catégories données
     * (associations.products de chaque catégorie). Sert à IGNORER ces produits
     * à la synchronisation. Best-effort : une catégorie illisible est ignorée.
     *
     * @param list<int> $categoryIds
     * @return array<int,true> Set d'id_product (clé = id) pour lookup O(1)
     */
    public function fetchProductIdsInCategories(array $categoryIds): array
    {
        $set = [];
        foreach ($categoryIds as $catId) {
            $catId = (int) $catId;
            if ($catId <= 0) continue;
            try {
                $xml = $this->fetchCategoryXml($catId);
            } catch (\Throwable) {
                continue;
            }
            $cat = $xml->category;
            if (!isset($cat->associations->products->product)) continue;
            foreach ($cat->associations->products->product as $p) {
                $pid = (int) $p->id;
                if ($pid > 0) {
                    $set[$pid] = true;
                }
            }
        }
        return $set;
    }

    /**
     * @param array<string,string> $query
     */
    private function get(string $path, array $query = [], bool $asJson = true): string
    {
        $url = rtrim($this->client->prestashopUrl, '/') . $path;
        if ($asJson) {
            $query['output_format'] = 'JSON';
            $query['ws_key'] = $this->apiKey(); // certains hosts préfèrent ws_key au Basic Auth
        }
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERPWD => $this->apiKey() . ':',
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER => [
                'Accept: ' . ($asJson ? 'application/json, application/xml' : 'application/xml'),
            ],
            CURLOPT_USERAGENT => 'PIM-Musculation/0.1',
        ];

        // CA bundle : on tente plusieurs sources dans l'ordre (env, ini, default Composer/curl)
        // Permet de fonctionner même si php.ini n'a pas été rechargé après une mise à jour.
        $caPath = $this->resolveCaBundlePath();
        if ($caPath !== null) {
            $options[CURLOPT_CAINFO] = $caPath;
        }

        // Opt-in dev local : désactivation de la vérification TLS (utile derrière un antivirus
        // qui intercepte HTTPS comme Avast). NE JAMAIS activer en production.
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
            // Erreur SSL spécifique → message d'aide
            if (str_contains($error, 'SSL certificate') || str_contains($error, 'certificate verify failed')) {
                $hint = ' (En dev local : redémarrez le serveur PHP pour recharger php.ini, ou désactivez le scan HTTPS de votre antivirus.)';
                throw new RuntimeException('Erreur SSL : ' . $error . $hint);
            }
            throw new RuntimeException('Erreur réseau cURL : ' . $error);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $snippet = mb_substr((string) $body, 0, 200);
            throw new RuntimeException("HTTP {$httpCode} sur {$path} : {$snippet}");
        }
        return (string) $body;
    }

    private function post(string $path, string $xmlBody): string
    {
        return $this->xmlRequest('POST', $path, $xmlBody);
    }

    private function delete(string $path): void
    {
        $this->xmlRequest('DELETE', $path, null);
    }

    // -------------------------------------------------------------------------
    // Listes pour les selecteurs de creation produit
    // -------------------------------------------------------------------------

    /**
     * Liste les manufacturers (marques). Pas de filtre active (laisse passer
     * tous, l'user choisira). Necessite ressource 'manufacturers' cochee
     * dans la cle Webservice PS.
     * @return list<array{id:int, name:string, active:int}>
     */
    public function fetchManufacturers(): array
    {
        $body = $this->get('/api/manufacturers', [
            'display' => '[id,name,active]',
            'limit' => '0,5000',
        ], asJson: true);
        $rows = $this->decodeJsonOrXml($body, 'manufacturers', 'manufacturer');
        unset($body);
        $result = [];
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            $name = trim((string) ($r['name'] ?? ''));
            if ($id <= 0 || $name === '') continue;
            $result[] = ['id' => $id, 'name' => $name, 'active' => (int) ($r['active'] ?? 1)];
        }
        usort($result, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return $result;
    }

    /**
     * Liste les tax_rules_groups actifs et non soft-deleted.
     * @return list<array{id:int, name:string}>
     */
    public function fetchTaxRulesGroups(): array
    {
        $body = $this->get('/api/tax_rule_groups', [
            'display' => '[id,name,active,deleted]',
            'filter[active]' => '1',
            'filter[deleted]' => '0',
            'limit' => '0,500',
        ], asJson: true);
        $rows = $this->decodeJsonOrXml($body, 'tax_rule_groups', 'tax_rule_group');
        unset($body);
        $result = [];
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            $name = trim((string) ($r['name'] ?? ''));
            $deleted = (int) ($r['deleted'] ?? 0);
            if ($id <= 0 || $name === '' || $deleted === 1) continue;
            $result[] = ['id' => $id, 'name' => $name];
        }
        usort($result, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return $result;
    }

    /**
     * Aplatit l'arborescence des categories en liste indentee (visuel "—— Boissons").
     * Trie hierarchiquement (parent puis enfants, recursif).
     * @return list<array{id:int, name:string, depth:int, indented_name:string}>
     */
    public function fetchCategoriesFlat(): array
    {
        $raw = $this->fetchAllCategories();
        // Indexe par id pour reconstruire l'arbre
        $byId = [];
        foreach ($raw as $c) {
            $byId[(int) $c['id']] = [
                'id' => (int) $c['id'],
                'parent_id' => (int) $c['parent_id'],
                'name' => trim((string) ($c['name'] ?? '')),
                'children' => [],
            ];
        }
        // Lien parent -> children
        $roots = [];
        foreach ($byId as $id => &$node) {
            $pid = $node['parent_id'];
            if ($pid > 0 && isset($byId[$pid])) {
                $byId[$pid]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);

        $result = [];
        $walk = function (array &$node, int $depth) use (&$walk, &$result): void {
            // Skip root virtuel "Root" (parent_id=0 + name "Root") si besoin ?
            // On laisse pour le moment, l'user choisit ce qu'il veut.
            $indent = str_repeat('— ', $depth);
            $result[] = [
                'id' => $node['id'],
                'name' => $node['name'],
                'depth' => $depth,
                'indented_name' => $indent . $node['name'],
            ];
            usort($node['children'], fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
            foreach ($node['children'] as &$child) {
                $walk($child, $depth + 1);
            }
            unset($child);
        };
        usort($roots, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        foreach ($roots as &$root) {
            $walk($root, 0);
        }
        unset($root);
        return $result;
    }

    // -------------------------------------------------------------------------
    // Création produits / combinaisons (utilisé par /catalogue/create)
    // -------------------------------------------------------------------------

    /**
     * Crée un produit racine PrestaShop. Tous les champs sont optionnels sauf reference/name.
     *
     * @param array<string,mixed> $data Champs :
     *   reference (string, req), ean13 (string), name (string, req), linkRewrite (?string),
     *   categoryIds (list<int>, def [2]), manufacturerId (?int),
     *   active (bool, def false), visibility ('both'|'catalog'|'search'|'none', def 'both'),
     *   taxRulesGroupId (int, def 0), price (?float HT, def 0), wholesalePrice (?float HT, def 0),
     *   descriptionShort (string), description (string),
     *   metaTitle (string), metaDescription (string), metaKeywords (string),
     *   weight (?float kg), width/height/depth (?float cm),
     *   supplierId (?int), supplierReference (?string)
     * @return int presta_id du produit créé
     */
    public function createProduct(array $data): int
    {
        $cdata = static fn(string $s): string => str_replace(']]>', ']]]]><![CDATA[>', $s);
        $dec = static fn(?float $v): ?string => $v === null ? null : number_format($v, 6, '.', '');

        $reference = (string) ($data['reference'] ?? '');
        $name = (string) ($data['name'] ?? '');
        if ($reference === '' || $name === '') {
            throw new RuntimeException('createProduct : reference et name obligatoires.');
        }
        $ean13 = (string) ($data['ean13'] ?? '');
        $linkRewrite = $data['linkRewrite'] ?? null;
        $slug = self::slugify((string) ($linkRewrite ?? '')) ?: self::slugify($name) ?: ('prod-' . time());

        $categoryIds = is_array($data['categoryIds'] ?? null) ? array_values(array_unique(array_map('intval', $data['categoryIds']))) : [];
        $categoryIds = array_values(array_filter($categoryIds, fn($i) => $i > 0));
        if ($categoryIds === []) $categoryIds = [2];
        $defaultCatId = $categoryIds[0];

        $manufacturerId = isset($data['manufacturerId']) && $data['manufacturerId'] > 0 ? (int) $data['manufacturerId'] : 0;
        $active = !empty($data['active']);
        $visibility = in_array($data['visibility'] ?? 'both', ['both', 'catalog', 'search', 'none'], true) ? $data['visibility'] : 'both';
        $taxRulesGroupId = max(0, (int) ($data['taxRulesGroupId'] ?? 0));

        $descShort = (string) ($data['descriptionShort'] ?? '');
        $description = (string) ($data['description'] ?? '');
        $metaTitle = (string) ($data['metaTitle'] ?? '');
        $metaDesc = (string) ($data['metaDescription'] ?? '');
        $metaKw = (string) ($data['metaKeywords'] ?? '');

        $weight = $dec($data['weight'] ?? null);
        $width = $dec($data['width'] ?? null);
        $height = $dec($data['height'] ?? null);
        $depth = $dec($data['depth'] ?? null);
        $priceHt = isset($data['price']) ? max(0.0, (float) $data['price']) : 0.0;
        $priceStr = number_format($priceHt, 6, '.', '');
        $wholesaleHt = isset($data['wholesalePrice']) ? max(0.0, (float) $data['wholesalePrice']) : 0.0;
        $wholesaleStr = number_format($wholesaleHt, 6, '.', '');

        $supplierId = $data['supplierId'] ?? null;
        $supplierReference = $data['supplierReference'] ?? null;

        // Build des nodes optionnels (HEREDOC simple ne suit pas la condition)
        $manufacturerNode = $manufacturerId > 0 ? "<id_manufacturer>{$manufacturerId}</id_manufacturer>" : '';
        $supplierNode = ($supplierId !== null && (int) $supplierId > 0) ? "<id_supplier>" . (int) $supplierId . "</id_supplier>" : '';
        $weightNode = $weight !== null ? "<weight>{$weight}</weight>" : '';
        $widthNode = $width !== null ? "<width>{$width}</width>" : '';
        $heightNode = $height !== null ? "<height>{$height}</height>" : '';
        $depthNode = $depth !== null ? "<depth>{$depth}</depth>" : '';

        $catsXml = '';
        foreach ($categoryIds as $cid) {
            $catsXml .= "<category><id>{$cid}</id></category>";
        }

        $refEsc = $cdata($reference);
        $eanEsc = $cdata($ean13);
        $nameEsc = $cdata($name);
        $slugEsc = $cdata($slug);
        $descShortEsc = $cdata($descShort);
        $descEsc = $cdata($description);
        $metaTitleEsc = $cdata($metaTitle);
        $metaDescEsc = $cdata($metaDesc);
        $metaKwEsc = $cdata($metaKw);
        $activeStr = $active ? '1' : '0';

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<prestashop xmlns:xlink="http://www.w3.org/1999/xlink">
    <product>
        <reference><![CDATA[{$refEsc}]]></reference>
        <ean13><![CDATA[{$eanEsc}]]></ean13>
        <price>{$priceStr}</price>
        <wholesale_price>{$wholesaleStr}</wholesale_price>
        <active>{$activeStr}</active>
        <state>1</state>
        <visibility>{$visibility}</visibility>
        <id_category_default>{$defaultCatId}</id_category_default>
        <id_tax_rules_group>{$taxRulesGroupId}</id_tax_rules_group>
        <id_shop_default>1</id_shop_default>
        <minimal_quantity>1</minimal_quantity>
        {$manufacturerNode}
        {$supplierNode}
        {$weightNode}
        {$widthNode}
        {$heightNode}
        {$depthNode}
        <name>
            <language id="1"><![CDATA[{$nameEsc}]]></language>
        </name>
        <link_rewrite>
            <language id="1"><![CDATA[{$slugEsc}]]></language>
        </link_rewrite>
        <description_short>
            <language id="1"><![CDATA[{$descShortEsc}]]></language>
        </description_short>
        <description>
            <language id="1"><![CDATA[{$descEsc}]]></language>
        </description>
        <meta_title>
            <language id="1"><![CDATA[{$metaTitleEsc}]]></language>
        </meta_title>
        <meta_description>
            <language id="1"><![CDATA[{$metaDescEsc}]]></language>
        </meta_description>
        <meta_keywords>
            <language id="1"><![CDATA[{$metaKwEsc}]]></language>
        </meta_keywords>
        <associations>
            <categories>
                {$catsXml}
            </categories>
        </associations>
    </product>
</prestashop>
XML;

        $body = $this->post('/api/products', $xml);
        $xmlResp = @simplexml_load_string($body);
        if ($xmlResp === false || !isset($xmlResp->product->id)) {
            throw new RuntimeException('Réponse Presta invalide après création produit.');
        }
        $newId = (int) $xmlResp->product->id;
        if ($newId === 0) {
            throw new RuntimeException('Id produit créé manquant dans la réponse.');
        }

        if ($supplierId !== null && $supplierId > 0 && $supplierReference !== null && $supplierReference !== '') {
            $this->linkProductSupplier($newId, 0, (int) $supplierId, (string) $supplierReference);
        }

        return $newId;
    }

    /**
     * Crée une combination (déclinaison) sur un produit parent existant.
     * Lie aussi product_supplier si supplierId fourni.
     *
     * @param list<int> $optionValueIds  Ids des product_option_values à associer (taille, saveur)
     * @return int presta_id de la combination (id_product_attribute)
     */
    public function createCombination(int $parentProductId, string $reference, string $ean13, array $optionValueIds = [], ?int $supplierId = null, ?string $supplierReference = null, float $priceImpact = 0.0, float $wholesalePrice = 0.0): int
    {
        $cdata = static fn(string $s): string => str_replace(']]>', ']]]]><![CDATA[>', $s);
        $refEsc = $cdata($reference);
        $eanEsc = $cdata($ean13);
        // priceImpact = delta HT vs prix parent. Peut etre negatif.
        $priceStr = number_format($priceImpact, 6, '.', '');
        $wholesaleStr = number_format(max(0.0, $wholesalePrice), 6, '.', '');

        $optValuesXml = '';
        foreach ($optionValueIds as $valId) {
            $valId = (int) $valId;
            if ($valId <= 0) continue;
            $optValuesXml .= "<product_option_value><id>{$valId}</id></product_option_value>";
        }

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<prestashop xmlns:xlink="http://www.w3.org/1999/xlink">
    <combination>
        <id_product>{$parentProductId}</id_product>
        <reference><![CDATA[{$refEsc}]]></reference>
        <ean13><![CDATA[{$eanEsc}]]></ean13>
        <price>{$priceStr}</price>
        <wholesale_price>{$wholesaleStr}</wholesale_price>
        <minimal_quantity>1</minimal_quantity>
        <default_on>0</default_on>
        <associations>
            <product_option_values>
                {$optValuesXml}
            </product_option_values>
        </associations>
    </combination>
</prestashop>
XML;

        $body = $this->post('/api/combinations', $xml);
        $xmlResp = @simplexml_load_string($body);
        if ($xmlResp === false || !isset($xmlResp->combination->id)) {
            throw new RuntimeException('Réponse Presta invalide après création combination.');
        }
        $newId = (int) $xmlResp->combination->id;
        if ($newId === 0) {
            throw new RuntimeException('Id combination créé manquant dans la réponse.');
        }

        if ($supplierId !== null && $supplierId > 0 && $supplierReference !== null && $supplierReference !== '') {
            $this->linkProductSupplier($parentProductId, $newId, $supplierId, $supplierReference);
        }

        return $newId;
    }

    /**
     * Associe une image (deja uploadee sur le produit parent) a une combination.
     * GET la combination, ajoute l'image dans associations.images.image[], PUT.
     * Idempotent : si l'image est deja liee, no-op.
     */
    public function linkImageToCombination(int $combinationId, int $imageId): void
    {
        $body = $this->get('/api/combinations/' . $combinationId, [], asJson: false);
        $xml = @simplexml_load_string($body);
        if ($xml === false || !isset($xml->combination)) {
            throw new RuntimeException('Impossible de récupérer la combination #' . $combinationId);
        }
        $combination = $xml->combination;

        // Strip champs read-only qui font echouer le PUT (idem updateCategoryFields)
        $dom = dom_import_simplexml($combination);
        foreach (['date_add', 'date_upd'] as $field) {
            foreach ($dom->childNodes as $node) {
                if ($node->nodeName === $field) {
                    $dom->removeChild($node);
                    break;
                }
            }
        }
        $xmlString = $dom->ownerDocument->saveXML();
        $xml = simplexml_load_string($xmlString);
        $combination = $xml->combination;

        // Verifie si l'image est deja liee
        if (isset($combination->associations->images)) {
            $images = $combination->associations->images;
            foreach ($images->image ?? [] as $img) {
                if ((int) $img->id === $imageId) {
                    return; // deja liee
                }
            }
        } else {
            // Cree le noeud associations.images s'il manque
            if (!isset($combination->associations)) {
                $combination->addChild('associations');
            }
            $combination->associations->addChild('images');
        }
        $newImg = $combination->associations->images->addChild('image');
        $newImg->addChild('id', (string) $imageId);

        $finalXml = $xml->asXML();
        if ($finalXml === false) {
            throw new RuntimeException('Sérialisation XML échouée pour la combination.');
        }
        $this->put('/api/combinations/' . $combinationId, $finalXml);
    }

    /**
     * Crée une ligne dans product_supplier pour lier un produit/combination à un fournisseur.
     */
    private function linkProductSupplier(int $productId, int $combinationId, int $supplierId, string $supplierReference): void
    {
        $cdata = static fn(string $s): string => str_replace(']]>', ']]]]><![CDATA[>', $s);
        $refEsc = $cdata($supplierReference);

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<prestashop xmlns:xlink="http://www.w3.org/1999/xlink">
    <product_supplier>
        <id_product>{$productId}</id_product>
        <id_product_attribute>{$combinationId}</id_product_attribute>
        <id_supplier>{$supplierId}</id_supplier>
        <id_currency>1</id_currency>
        <product_supplier_reference><![CDATA[{$refEsc}]]></product_supplier_reference>
        <product_supplier_price_te>0.000000</product_supplier_price_te>
    </product_supplier>
</prestashop>
XML;

        $this->post('/api/product_suppliers', $xml);
    }

    /**
     * Slugify simple — ASCII, minuscules, séparateur -, max 128 chars.
     */
    private static function slugify(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        return substr($s, 0, 128);
    }

    /**
     * Réordonne les images d'un produit en modifiant l'ordre des entrées <image>
     * dans la section <associations><images> du XML produit, puis PUT le tout.
     *
     * Important : l'endpoint /api/images/products/{X}/{Y} renvoie le binaire, pas du XML.
     * Le seul endroit où l'ordre des images est éditable côté WS est dans le produit lui-même.
     *
     * @param list<int> $orderedImageIds Liste des id_image dans le nouvel ordre désiré (1er = position 1)
     * @return array{updated:int, skipped:int, errors:list<string>}
     */
    public function reorderProductImages(int $prestaProductId, array $orderedImageIds): array
    {
        try {
            // 1. Fetch le XML complet du produit
            $body = $this->get('/api/products/' . $prestaProductId, [], asJson: false);
            $xml = @simplexml_load_string($body);
            if ($xml === false || !isset($xml->product)) {
                return ['updated' => 0, 'skipped' => 0, 'errors' => ['XML produit invalide.']];
            }
            $product = $xml->product;

            // 2. Strip les champs read-only (sinon PUT renvoie "X not writable")
            $dom = dom_import_simplexml($product);
            foreach (self::PRODUCT_READONLY_FIELDS as $field) {
                $nodes = [];
                foreach ($dom->childNodes as $node) {
                    if ($node->nodeName === $field) $nodes[] = $node;
                }
                foreach ($nodes as $node) $dom->removeChild($node);
            }

            // 3. Re-charge le XML après modif DOM
            $xmlString = $dom->ownerDocument->saveXML();
            $xml = simplexml_load_string($xmlString);
            $product = $xml->product;

            // 4. Vérifie qu'on a bien la section associations.images
            if (!isset($product->associations->images)) {
                return ['updated' => 0, 'skipped' => 0, 'errors' => ['Le produit n\'a pas de section associations.images.']];
            }

            // 5. Récupère les <image> existantes indexées par id
            $imagesNode = $product->associations->images;
            $existingByid = [];
            foreach ($imagesNode->image as $img) {
                $imgId = (int) $img->id;
                if ($imgId > 0) {
                    // Clone DOM du noeud avant suppression
                    $existingByid[$imgId] = dom_import_simplexml($img)->cloneNode(true);
                }
            }

            $foundIds = array_keys($existingByid);
            $missing = array_diff(array_map('intval', $orderedImageIds), $foundIds);

            // 6. Vide le noeud images et rajoute dans le nouvel ordre demandé
            $imagesDom = dom_import_simplexml($imagesNode);
            // Supprime tous les enfants
            while ($imagesDom->firstChild) {
                $imagesDom->removeChild($imagesDom->firstChild);
            }
            // Réinsère dans l'ordre demandé
            $reinserted = 0;
            foreach ($orderedImageIds as $imgId) {
                $imgIdInt = (int) $imgId;
                if (isset($existingByid[$imgIdInt])) {
                    $imported = $imagesDom->ownerDocument->importNode($existingByid[$imgIdInt], true);
                    $imagesDom->appendChild($imported);
                    $reinserted++;
                }
            }
            // Et on rajoute à la fin celles qu'on a oubliées (sécurité — ne pas perdre d'images)
            $skipped = 0;
            foreach ($existingByid as $imgId => $node) {
                if (!in_array($imgId, array_map('intval', $orderedImageIds), true)) {
                    $imported = $imagesDom->ownerDocument->importNode($node, true);
                    $imagesDom->appendChild($imported);
                    $skipped++;
                }
            }

            // 7. Sérialise et PUT
            $newXml = $imagesDom->ownerDocument->saveXML();
            $this->put('/api/products/' . $prestaProductId, $newXml);

            $errors = [];
            if (!empty($missing)) {
                $errors[] = count($missing) . ' image(s) ID inconnu(es) côté Presta : ' . implode(', ', $missing);
            }

            return ['updated' => $reinserted, 'skipped' => $skipped, 'errors' => $errors];
        } catch (\Throwable $e) {
            return ['updated' => 0, 'skipped' => 0, 'errors' => [$e->getMessage()]];
        }
    }

    /**
     * Télécharge une image depuis $sourceUrl puis l'upload comme image produit
     * via POST multipart sur /api/images/products/{prestaId}.
     *
     * @return int L'id_image créé côté PrestaShop
     */
    public function uploadProductImageFromUrl(int $prestaProductId, string $sourceUrl): int
    {
        // 1. Télécharge l'image en mémoire
        $imageBytes = $this->downloadBinary($sourceUrl);
        if ($imageBytes === '') {
            throw new RuntimeException('Image source vide ou URL morte : ' . $sourceUrl);
        }

        // 2. Détermine l'extension à partir de l'URL ou par défaut jpg
        $ext = strtolower(pathinfo((string) parse_url($sourceUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'jpg';
        }

        // 3. Fichier temporaire pour CURLFile (PHP curl ne sait pas faire du multipart depuis une string sans fichier)
        $tmpPath = tempnam(sys_get_temp_dir(), 'prestaimg_') . '.' . $ext;
        if (file_put_contents($tmpPath, $imageBytes) === false) {
            throw new RuntimeException('Impossible d\'écrire le fichier temporaire pour upload.');
        }

        try {
            // 4. POST multipart à PrestaShop
            $url = rtrim($this->client->prestashopUrl, '/') . '/api/images/products/' . $prestaProductId;
            $ch = curl_init($url);
            $mime = match ($ext) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                default => 'image/jpeg',
            };
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 90,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => ['image' => new \CURLFile($tmpPath, $mime, 'variant.' . $ext)],
                CURLOPT_USERPWD => $this->apiKey() . ':',
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERAGENT => 'PIM-Musculation/0.1',
            ];
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
                throw new RuntimeException('Erreur réseau upload image : ' . $error);
            }
            if ($httpCode < 200 || $httpCode >= 300) {
                $snippet = mb_substr((string) $body, 0, 500);
                throw new RuntimeException("HTTP {$httpCode} sur upload image : {$snippet}");
            }

            // 5. Parse la réponse XML pour récupérer l'id de la nouvelle image
            $xml = @simplexml_load_string((string) $body);
            if ($xml === false || !isset($xml->image->id)) {
                throw new RuntimeException('Réponse Presta XML invalide après upload image.');
            }
            return (int) $xml->image->id;
        } finally {
            @unlink($tmpPath);
        }
    }

    /**
     * Télécharge une URL en mémoire (binary safe). Renvoie une string vide en cas d'erreur.
     */
    private function downloadBinary(string $url): string
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'PIM-Musculation/0.1',
        ];
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
        curl_close($ch);
        if ($body === false || $httpCode < 200 || $httpCode >= 300) {
            return '';
        }
        return (string) $body;
    }

    private function xmlRequest(string $method, string $path, ?string $xmlBody): string
    {
        $url = rtrim($this->client->prestashopUrl, '/') . $path;

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERPWD => $this->apiKey() . ':',
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/xml',
                'Accept: application/xml',
            ],
            CURLOPT_USERAGENT => 'PIM-Musculation/0.1',
        ];
        if ($xmlBody !== null) {
            $options[CURLOPT_POSTFIELDS] = $xmlBody;
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
            throw new RuntimeException("Erreur réseau cURL ({$method}) : " . $error);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $snippet = mb_substr((string) $body, 0, 300);
            throw new RuntimeException("HTTP {$httpCode} sur {$method} {$path} : {$snippet}");
        }
        return (string) $body;
    }

    private function put(string $path, string $xmlBody): string
    {
        $url = rtrim($this->client->prestashopUrl, '/') . $path;

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $xmlBody,
            CURLOPT_USERPWD => $this->apiKey() . ':',
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/xml',
                'Accept: application/xml',
            ],
            CURLOPT_USERAGENT => 'PIM-Musculation/0.1',
        ];

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
            throw new RuntimeException('Erreur réseau cURL (PUT) : ' . $error);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $snippet = mb_substr((string) $body, 0, 300);
            throw new RuntimeException("HTTP {$httpCode} sur PUT {$path} : {$snippet}");
        }
        return (string) $body;
    }

    /**
     * Décode une réponse JSON ou XML PrestaShop. Si JSON : { "categories": [...] }.
     * Si XML : <prestashop><categories><category>...</category></categories></prestashop>
     *
     * @return list<array<string,mixed>>
     */
    private function decodeJsonOrXml(string $body, string $listKey, string $itemKey): array
    {
        $trimmed = ltrim($body);
        if ($trimmed !== '' && $trimmed[0] === '{') {
            $decoded = json_decode($body, true);
            if (!is_array($decoded) || !isset($decoded[$listKey])) {
                return [];
            }
            $items = $decoded[$listKey];
            if (!is_array($items)) {
                return [];
            }
            // Cas où l'API renvoie un dict { "0": {...}, "1": {...} } → on liste les valeurs
            return array_values(array_map(fn ($v) => is_array($v) ? $v : [], $items));
        }

        // Fallback XML
        $xml = @simplexml_load_string($body);
        if ($xml === false) {
            return [];
        }
        $result = [];
        foreach ($xml->{$listKey}->{$itemKey} ?? [] as $node) {
            $row = [];
            foreach ($node->children() as $child) {
                $name = $child->getName();
                if ($child->count() > 0 && isset($child->language)) {
                    // Champ multilingue : on prend la première langue
                    $row[$name] = (string) $child->language[0];
                } else {
                    $row[$name] = (string) $child;
                }
            }
            // Attributs (id souvent en attribut)
            foreach ($node->attributes() as $aName => $aVal) {
                $row[$aName] = (string) $aVal;
            }
            $result[] = $row;
        }
        return $result;
    }

    /**
     * Les champs multilingues PrestaShop arrivent souvent sous forme :
     *   [{"id":"1","value":"Texte FR"}, {"id":"2","value":"Texte EN"}]
     * On retourne la première valeur non vide (langue par défaut du shop).
     */
    private function extractLanguageValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            // Cas { "language": [...] } enveloppé
            if (isset($value['language'])) {
                $value = $value['language'];
            }
            foreach ($value as $entry) {
                if (is_array($entry) && isset($entry['value']) && $entry['value'] !== '') {
                    return (string) $entry['value'];
                }
                if (is_string($entry) && $entry !== '') {
                    return $entry;
                }
            }
        }
        return '';
    }
}
