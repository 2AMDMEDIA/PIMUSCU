<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use Ramsey\Uuid\Uuid;

final class PrestaProductRepository
{
    private function pdo(): PDO
    {
        return Database::pdo();
    }

    /**
     * Récupère nom + reference + uuid + marque + statut actif pour une liste de presta_id.
     * @param list<int> $prestaIds
     * @return array<int, array{id:string, name:string, reference:string, manufacturer_name:string, active:int}> Map presta_id => infos
     */
    public function findByPrestaIds(string $clientId, array $prestaIds): array
    {
        $prestaIds = array_values(array_unique(array_filter(array_map('intval', $prestaIds), fn($i) => $i > 0)));
        if ($prestaIds === []) return [];
        $placeholders = implode(',', array_fill(0, count($prestaIds), '?'));
        $stmt = $this->pdo()->prepare(
            'SELECT id, presta_id, name, reference, manufacturer_name, active FROM presta_products
              WHERE client_id = ? AND presta_id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$clientId], $prestaIds));
        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $map[(int) $r['presta_id']] = [
                'id' => (string) $r['id'],
                'name' => (string) ($r['name'] ?? ''),
                'reference' => (string) ($r['reference'] ?? ''),
                'manufacturer_name' => (string) ($r['manufacturer_name'] ?? ''),
                'active' => (int) ($r['active'] ?? 1),
            ];
        }
        return $map;
    }

    /** @return array<string,mixed>|null */
    public function findById(string $clientId, string $id): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM presta_products WHERE client_id = :client_id AND id = :id LIMIT 1'
        );
        $stmt->execute([':client_id' => $clientId, ':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Construit les conditions WHERE communes aux 2 endpoints (list + count).
     *
     * @return array{0:list<string>, 1:array<string,mixed>}
     */
    private function buildWhere(string $clientId, string $search, string $filter, string $status, int $categoryId = 0, string $catalog = 'all', string $type = 'all'): array
    {
        $where = ['client_id = :client_id'];
        $params = [':client_id' => $clientId];

        if ($search !== '') {
            $where[] = '(name LIKE :search OR reference LIKE :search_ref)';
            $params[':search'] = '%' . $search . '%';
            $params[':search_ref'] = '%' . $search . '%';
        }

        switch ($filter) {
            case 'with_desc':
                $where[] = 'has_description = 1';
                break;
            case 'without_desc':
                $where[] = 'has_description = 0 AND has_cms_content = 0';
                break;
            case 'cms':
                $where[] = 'has_cms_content = 1';
                break;
        }

        if ($status === 'active') {
            $where[] = 'active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'active = 0';
        }

        if ($categoryId > 0) {
            $where[] = 'presta_category_id = :cat_id';
            $params[':cat_id'] = $categoryId;
        }

        // Filtre "présent / absent du catalogue Nutriweb" : EXISTS / NOT EXISTS
        // sur nutriweb_catalog.presta_product_id. Placeholder distinct pour éviter
        // le HY093 (PDO natif : réutilisation d'un même nom interdite).
        if ($catalog === 'in') {
            $where[] = 'EXISTS (SELECT 1 FROM nutriweb_catalog nc WHERE nc.client_id = :cid_cat AND nc.presta_product_id = presta_products.presta_id)';
            $params[':cid_cat'] = $clientId;
        } elseif ($catalog === 'out') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM nutriweb_catalog nc WHERE nc.client_id = :cid_cat AND nc.presta_product_id = presta_products.presta_id)';
            $params[':cid_cat'] = $clientId;
        }

        if (in_array($type, ['standard', 'pack', 'virtual'], true)) {
            $where[] = 'product_type = :ptype';
            $params[':ptype'] = $type;
        }

        return [$where, $params];
    }

    /**
     * Liste paginée + recherche + filtre description + filtre statut + filtre catégorie.
     *
     * @param string $filter  'all' | 'with_desc' | 'without_desc' | 'cms'
     * @param string $status  'all' | 'active' | 'inactive'
     * @param int    $categoryId  presta_id de la catégorie (0 = pas de filtre)
     * @return list<array<string,mixed>>
     */
    public function listForClient(string $clientId, string $search = '', string $filter = 'all', int $page = 1, int $perPage = 60, string $status = 'all', int $categoryId = 0, string $catalog = 'all', string $type = 'all'): array
    {
        [$where, $params] = $this->buildWhere($clientId, $search, $filter, $status, $categoryId, $catalog, $type);
        $offset = max(0, ($page - 1) * $perPage);

        $sql = 'SELECT * FROM presta_products WHERE ' . implode(' AND ', $where) . ' ORDER BY name ASC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countForClient(string $clientId, string $search = '', string $filter = 'all', string $status = 'all', int $categoryId = 0, string $catalog = 'all', string $type = 'all'): int
    {
        [$where, $params] = $this->buildWhere($clientId, $search, $filter, $status, $categoryId, $catalog, $type);
        $sql = 'SELECT COUNT(*) FROM presta_products WHERE ' . implode(' AND ', $where);
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Applique les stats agrégées d'avis (count + moyenne) reçues depuis api_reviews.php.
     * Évite un appel HTTP au chargement de la liste produits — la sync produit met à jour.
     *
     * @param array<int,array{count:int,avg_grade:float}> $statsByPrestaId Map presta_id => stats
     * @return int Nombre de produits mis à jour
     */
    public function applyReviewsStats(string $clientId, array $statsByPrestaId): int
    {
        // 1) Reset de tous les produits du client (pour que ceux qui n'ont plus d'avis repassent à NULL)
        $reset = $this->pdo()->prepare(
            'UPDATE presta_products
                SET reviews_count = NULL, reviews_avg = NULL, reviews_synced_at = NOW()
              WHERE client_id = :client_id'
        );
        $reset->execute([':client_id' => $clientId]);

        // 2) Apply : on n'écrit que pour les produits qui ont au moins 1 avis
        $upd = $this->pdo()->prepare(
            'UPDATE presta_products
                SET reviews_count = :count, reviews_avg = :avg, reviews_synced_at = NOW()
              WHERE client_id = :client_id AND presta_id = :presta_id'
        );
        $count = 0;
        foreach ($statsByPrestaId as $prestaId => $stats) {
            $prestaIdInt = (int) $prestaId;
            $reviewsCount = (int) ($stats['count'] ?? 0);
            $avgGrade = (float) ($stats['avg_grade'] ?? 0);
            if ($prestaIdInt <= 0 || $reviewsCount <= 0) continue;

            $upd->execute([
                ':count' => $reviewsCount,
                ':avg' => $avgGrade,
                ':client_id' => $clientId,
                ':presta_id' => $prestaIdInt,
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * Renvoie la liste des catégories qui ont au moins 1 produit chez ce client.
     * Utilisé pour peupler le dropdown de filtre dans la liste produits.
     *
     * @return list<array{presta_id:int, name:string, product_count:int}>
     */
    public function categoriesWithProductCounts(string $clientId): array
    {
        // LEFT JOIN sur presta_categories : on garde même si le nom n'est pas trouvé (catégorie pas encore sync).
        $sql = 'SELECT p.presta_category_id AS presta_id,
                       COALESCE(c.name, CONCAT("Catégorie #", p.presta_category_id)) AS name,
                       COUNT(*) AS product_count
                  FROM presta_products p
             LEFT JOIN presta_categories c
                    ON c.client_id = p.client_id AND c.presta_id = p.presta_category_id
                 WHERE p.client_id = :client_id
                   AND p.presta_category_id IS NOT NULL
                 GROUP BY p.presta_category_id, c.name
                 ORDER BY name ASC';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([':client_id' => $clientId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'presta_id' => (int) $row['presta_id'],
                'name' => (string) $row['name'],
                'product_count' => (int) $row['product_count'],
            ];
        }
        return $out;
    }

    /**
     * @return array{total:int, with_desc:int, without_desc:int, cms:int}
     */
    public function statsForClient(string $clientId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT
                COUNT(*) AS total,
                COALESCE(SUM(has_description), 0) AS with_desc,
                COALESCE(SUM(CASE WHEN has_description = 0 AND has_cms_content = 0 THEN 1 ELSE 0 END), 0) AS without_desc,
                COALESCE(SUM(has_cms_content), 0) AS cms,
                COALESCE(SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END), 0) AS active_count,
                COALESCE(SUM(CASE WHEN active = 0 THEN 1 ELSE 0 END), 0) AS inactive_count
              FROM presta_products WHERE client_id = :client_id'
        );
        $stmt->execute([':client_id' => $clientId]);
        $row = $stmt->fetch() ?: ['total' => 0, 'with_desc' => 0, 'without_desc' => 0, 'cms' => 0, 'active_count' => 0, 'inactive_count' => 0];

        // Compte des produits présents dans le catalogue Nutriweb (au moins 1 SKU matché).
        $catStmt = $this->pdo()->prepare(
            'SELECT COUNT(DISTINCT nc.presta_product_id)
               FROM nutriweb_catalog nc
               JOIN presta_products pp ON pp.client_id = nc.client_id AND pp.presta_id = nc.presta_product_id
              WHERE nc.client_id = :cid AND nc.presta_product_id IS NOT NULL'
        );
        $catStmt->execute([':cid' => $clientId]);
        $catalogPresent = (int) $catStmt->fetchColumn();

        // Comptages par type (standard / pack / virtual).
        $typeCounts = ['standard' => 0, 'pack' => 0, 'virtual' => 0];
        $typeStmt = $this->pdo()->prepare(
            'SELECT COALESCE(product_type, "standard") AS pt, COUNT(*) AS n
               FROM presta_products
              WHERE client_id = :cid
              GROUP BY COALESCE(product_type, "standard")'
        );
        $typeStmt->execute([':cid' => $clientId]);
        foreach ($typeStmt->fetchAll() as $r) {
            $key = (string) $r['pt'];
            if (isset($typeCounts[$key])) $typeCounts[$key] = (int) $r['n'];
        }

        $total = (int) $row['total'];
        return [
            'total' => $total,
            'with_desc' => (int) $row['with_desc'],
            'without_desc' => (int) $row['without_desc'],
            'cms' => (int) $row['cms'],
            'active' => (int) $row['active_count'],
            'inactive' => (int) $row['inactive_count'],
            'catalog_in' => $catalogPresent,
            'catalog_out' => max(0, $total - $catalogPresent),
            'type_standard' => $typeCounts['standard'],
            'type_pack' => $typeCounts['pack'],
            'type_virtual' => $typeCounts['virtual'],
        ];
    }

    /**
     * Recherche rapide pour autocomplete : matche le terme dans name OU reference
     * OU supplier_reference. Retourne au plus N resultats tries par pertinence
     * basique (preferre les matches exacts sur reference).
     *
     * @return list<array{id:string, presta_id:int, name:string, reference:string, supplier_reference:?string}>
     */
    public function searchByQuery(string $clientId, string $query, int $limit = 20): array
    {
        $q = trim($query);
        if ($q === '') return [];
        $like = '%' . $q . '%';
        $stmt = $this->pdo()->prepare(
            'SELECT id, presta_id, name, reference, supplier_reference
               FROM presta_products
              WHERE client_id = :cid
                AND (name LIKE :name OR reference LIKE :ref OR supplier_reference LIKE :sref)
              ORDER BY
                CASE WHEN reference = :exact THEN 0
                     WHEN supplier_reference = :exact2 THEN 1
                     WHEN reference LIKE :prefix THEN 2
                     ELSE 3 END,
                name ASC
              LIMIT :limit'
        );
        $stmt->bindValue(':cid', $clientId);
        $stmt->bindValue(':name', $like);
        $stmt->bindValue(':ref', $like);
        $stmt->bindValue(':sref', $like);
        $stmt->bindValue(':exact', $q);
        $stmt->bindValue(':exact2', $q);
        $stmt->bindValue(':prefix', $q . '%');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Insert minimal d'un produit fraichement cree (via /catalogue/create) pour que
     * le cache local soit a jour immediatement, sans attendre /produits/sync.
     * Idempotent (ON DUPLICATE KEY UPDATE sur la cle unique client_id+presta_id).
     */
    public function insertMinimal(string $clientId, int $prestaId, string $reference, ?string $supplierReference, string $name = ''): void
    {
        $sql = 'INSERT INTO presta_products
                  (id, client_id, presta_id, reference, supplier_reference, name, price, wholesale_price, active, synced_at)
                VALUES
                  (:id, :client_id, :presta_id, :reference, :supplier_ref, :name, 0, 0, 0, NOW())
                ON DUPLICATE KEY UPDATE
                  reference = VALUES(reference),
                  supplier_reference = VALUES(supplier_reference),
                  name = VALUES(name),
                  synced_at = NOW(),
                  updated_at = NOW()';
        $this->pdo()->prepare($sql)->execute([
            ':id' => Uuid::uuid4()->toString(),
            ':client_id' => $clientId,
            ':presta_id' => $prestaId,
            ':reference' => $reference,
            ':supplier_ref' => $supplierReference,
            ':name' => $name,
        ]);
    }

    /**
     * Supprime les produits du client qui ne sont PAS dans la liste passee.
     * Utilise pour purger les orphelins apres un sync : un produit supprime
     * cote PS ne sera plus dans la liste retournee par /api/products, donc
     * doit etre purge du cache local.
     *
     * @param list<int> $currentPrestaIds Liste des id_product encore presents sur PS
     * @return int Nombre de lignes supprimees
     */
    public function deleteStale(string $clientId, array $currentPrestaIds): int
    {
        if ($currentPrestaIds === []) {
            // Securite : si la liste est vide, on ne purge rien (probable bug de l'API
            // ou pas un seul produit cote PS — on ne va pas wipe tout le cache).
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($currentPrestaIds), '?'));
        $sql = 'DELETE FROM presta_products WHERE client_id = ? AND presta_id NOT IN (' . $placeholders . ')';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(array_merge([$clientId], $currentPrestaIds));
        return $stmt->rowCount();
    }

    /**
     * Upsert d'une liste de produits depuis le Webservice.
     *
     * @param list<array<string,mixed>> $products
     */
    public function upsertBatch(string $clientId, array $products): int
    {
        $sql = 'INSERT INTO presta_products
                  (id, client_id, presta_id, reference, supplier_reference, name, manufacturer_name, price, wholesale_price, active, product_type, presta_category_id,
                   description_short, description, meta_title, meta_description, meta_keywords,
                   has_cms_content, has_description, image_url, link_rewrite, synced_at)
                VALUES
                  (:id, :client_id, :presta_id, :reference, :supplier_ref, :name, :manufacturer, :price, :wholesale, :active, :ptype, :cat_id,
                   :desc_short, :description, :meta_title, :meta_desc, :meta_kw,
                   :has_cms, :has_desc, :image_url, :link_rewrite, NOW())
                ON DUPLICATE KEY UPDATE
                  reference = VALUES(reference),
                  supplier_reference = VALUES(supplier_reference),
                  name = VALUES(name),
                  manufacturer_name = VALUES(manufacturer_name),
                  product_type = VALUES(product_type),
                  price = VALUES(price),
                  wholesale_price = VALUES(wholesale_price),
                  active = VALUES(active),
                  presta_category_id = VALUES(presta_category_id),
                  description_short = VALUES(description_short),
                  description = VALUES(description),
                  meta_title = VALUES(meta_title),
                  meta_description = VALUES(meta_description),
                  meta_keywords = VALUES(meta_keywords),
                  has_cms_content = VALUES(has_cms_content),
                  has_description = VALUES(has_description),
                  image_url = VALUES(image_url),
                  link_rewrite = VALUES(link_rewrite),
                  synced_at = NOW(),
                  updated_at = NOW()';

        $pdo = $this->pdo();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare($sql);
        $count = 0;
        try {
            foreach ($products as $p) {
                $catId = (int) ($p['id_category_default'] ?? 0);
                $stmt->execute([
                    ':id' => Uuid::uuid4()->toString(),
                    ':client_id' => $clientId,
                    ':presta_id' => $p['id'],
                    ':reference' => $p['reference'] ?? '',
                    ':supplier_ref' => $p['supplier_reference'] ?? null,
                    ':name' => $p['name'] ?? '',
                    ':manufacturer' => (!empty($p['manufacturer_name'])) ? $p['manufacturer_name'] : null,
                    ':price' => $p['price'] ?? 0,
                    ':wholesale' => $p['wholesale_price'] ?? 0,
                    ':active' => $p['active'] ?? 1,
                    ':ptype' => (!empty($p['product_type'])) ? $p['product_type'] : 'standard',
                    ':cat_id' => $catId > 0 ? $catId : null,
                    ':desc_short' => $p['description_short'] ?? null,
                    ':description' => $p['description'] ?? null,
                    ':meta_title' => $p['meta_title'] ?? null,
                    ':meta_desc' => $p['meta_description'] ?? null,
                    ':meta_kw' => (!empty($p['meta_keywords'])) ? $p['meta_keywords'] : null,
                    ':has_cms' => !empty($p['has_cms_content']) ? 1 : 0,
                    ':has_desc' => !empty($p['has_description']) ? 1 : 0,
                    ':image_url' => $p['image_url'] ?? null,
                    ':link_rewrite' => $p['link_rewrite'] ?? null,
                ]);
                $count++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $count;
    }

    /**
     * Vide les colonnes promo_* de tous les produits du client.
     * À appeler avant applyActivePromos pour repartir d'une base propre.
     */
    public function clearAllPromos(string $clientId): void
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE presta_products
                SET promo_reduction_type = NULL, promo_reduction = NULL,
                    promo_from = NULL, promo_to = NULL,
                    active_promos_json = NULL
              WHERE client_id = :client_id'
        );
        $stmt->execute([':client_id' => $clientId]);
    }

    /**
     * Applique les promos actives (specific_prices) recues du Webservice.
     * Ne garde que celles dont la fenetre temporelle est ouverte.
     *
     * @param list<array{id_product:int, reduction:float, reduction_type:string, from:string, to:string}> $specificPrices
     * @return int Nombre de promos appliquees
     */
    public function applyActivePromos(string $clientId, array $specificPrices): int
    {
        $now = date('Y-m-d H:i:s');
        $sqlCols = 'UPDATE presta_products
                       SET promo_reduction_type = :type, promo_reduction = :reduction,
                           promo_from = :from, promo_to = :to
                     WHERE client_id = :client_id AND presta_id = :presta_id';
        $stmtCols = $this->pdo()->prepare($sqlCols);
        // Groupé par produit pour l'active_promos_json (une seule UPDATE par produit)
        $byProduct = [];
        $count = 0;
        foreach ($specificPrices as $sp) {
            $type = (string) ($sp['reduction_type'] ?? '');
            if (!in_array($type, ['percentage', 'amount'], true)) continue;
            $reduction = (float) ($sp['reduction'] ?? 0);
            if ($reduction <= 0) continue;
            $prestaId = (int) ($sp['id_product'] ?? 0);
            if ($prestaId <= 0) continue;
            $from = (string) ($sp['from'] ?? '');
            $to = (string) ($sp['to'] ?? '');
            $fromOk = ($from === '' || $from === '0000-00-00 00:00:00' || $from <= $now);
            $toOk   = ($to === '' || $to === '0000-00-00 00:00:00' || $to >= $now);
            if (!$fromOk || !$toOk) continue;

            $stmtCols->execute([
                ':type' => $type,
                ':reduction' => $reduction,
                ':from' => ($from !== '' && $from !== '0000-00-00 00:00:00') ? $from : null,
                ':to' => ($to !== '' && $to !== '0000-00-00 00:00:00') ? $to : null,
                ':client_id' => $clientId,
                ':presta_id' => $prestaId,
            ]);
            // Accumule la promo (raw) pour l'active_promos_json — même shape que
            // ce que listSpecificPricesForProduct retourne, pour rester compatible
            // avec le template detail.php.
            $byProduct[$prestaId][] = [
                'id' => (int) ($sp['id'] ?? 0),
                'reduction' => $reduction,
                'reduction_type' => $type,
                'reduction_tax' => (int) ($sp['reduction_tax'] ?? 1),
                'from' => $from,
                'to' => $to,
                'price' => (float) ($sp['price'] ?? -1),
            ];
            $count++;
        }

        // Update active_promos_json par produit (une requete par produit, mais on
        // n'update que ceux qui ont une promo active -> minimal).
        if ($byProduct !== []) {
            $stmtJson = $this->pdo()->prepare(
                'UPDATE presta_products SET active_promos_json = :json
                  WHERE client_id = :client_id AND presta_id = :presta_id'
            );
            foreach ($byProduct as $prestaId => $promos) {
                $stmtJson->execute([
                    ':json' => json_encode(array_values($promos), JSON_UNESCAPED_UNICODE),
                    ':client_id' => $clientId,
                    ':presta_id' => $prestaId,
                ]);
            }
        }

        return $count;
    }

    /**
     * Retourne les presta_id des produits du client dont image_ids est NULL
     * (jamais fetché). Sert au sync produits pour ne fetcher que ceux-là.
     * @return list<int>
     */
    public function listPrestaIdsMissingImages(string $clientId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT presta_id FROM presta_products
              WHERE client_id = :client_id AND (image_ids IS NULL OR image_ids = "")'
        );
        $stmt->execute([':client_id' => $clientId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Enregistre la liste des id_image d'un produit (CSV) pour affichage instantané
     * dans la fiche produit sans appel Presta live.
     * NULL = a re-fetcher a la prochaine ouverture de fiche.
     */
    public function saveImageIds(string $clientId, int $prestaId, ?array $imageIds): void
    {
        $csv = $imageIds !== null ? implode(',', array_map('intval', $imageIds)) : null;
        $stmt = $this->pdo()->prepare(
            'UPDATE presta_products SET image_ids = :ids
              WHERE client_id = :client_id AND presta_id = :presta_id'
        );
        $stmt->execute([
            ':ids' => $csv,
            ':client_id' => $clientId,
            ':presta_id' => $prestaId,
        ]);
    }

    /**
     * Invalide (NULL) l'active_promos_json d'un produit pour forcer un re-fetch
     * ou vidage apres suppression manuelle des promos.
     */
    public function clearActivePromosJson(string $clientId, int $prestaId): void
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE presta_products SET active_promos_json = NULL
              WHERE client_id = :client_id AND presta_id = :presta_id'
        );
        $stmt->execute([':client_id' => $clientId, ':presta_id' => $prestaId]);
    }

    public function saveOptimized(
        string $clientId,
        string $id,
        ?string $descriptionShort,
        ?string $description,
        ?string $metaTitle,
        ?string $metaDescription,
        ?string $metaKeywords = null,
    ): void {
        $stmt = $this->pdo()->prepare(
            'UPDATE presta_products
                SET optimized_description_short = :ds,
                    optimized_description = :d,
                    optimized_meta_title = :mt,
                    optimized_meta_description = :md,
                    optimized_meta_keywords = :mk,
                    optimized_at = NOW(),
                    updated_at = NOW()
              WHERE client_id = :client_id AND id = :id'
        );
        $stmt->execute([
            ':client_id' => $clientId,
            ':id' => $id,
            ':ds' => $descriptionShort,
            ':d' => $description,
            ':mt' => $metaTitle,
            ':md' => $metaDescription,
            ':mk' => $metaKeywords,
        ]);
    }

    /**
     * Met à jour la "Version actuelle" (cache local) après un push réussi.
     * Les versions optimisées sont CONSERVÉES pour permettre de retrouver
     * le contenu pré-rempli au prochain chargement et continuer à l'éditer.
     */
    public function applyAfterPush(
        string $clientId,
        string $id,
        ?string $descriptionShort,
        ?string $description,
        ?string $metaTitle,
        ?string $metaDescription,
        ?string $metaKeywords = null,
    ): void {
        $sets = [];
        $params = [':client_id' => $clientId, ':id' => $id];

        if ($descriptionShort !== null) {
            $sets[] = 'description_short = :ds';
            $params[':ds'] = $descriptionShort;
        }
        if ($description !== null) {
            $sets[] = 'description = :d, has_description = 1, has_cms_content = 0';
            $params[':d'] = $description;
        }
        if ($metaTitle !== null) {
            $sets[] = 'meta_title = :mt';
            $params[':mt'] = $metaTitle;
        }
        if ($metaDescription !== null) {
            $sets[] = 'meta_description = :md';
            $params[':md'] = $metaDescription;
        }
        if ($metaKeywords !== null) {
            $sets[] = 'meta_keywords = :mk';
            $params[':mk'] = $metaKeywords;
        }
        if ($sets === []) {
            return;
        }
        $sets[] = 'synced_at = NOW()';
        $sets[] = 'updated_at = NOW()';

        $sql = 'UPDATE presta_products SET ' . implode(', ', $sets)
            . ' WHERE client_id = :client_id AND id = :id';
        $this->pdo()->prepare($sql)->execute($params);
    }
}
