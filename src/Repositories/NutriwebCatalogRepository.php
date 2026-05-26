<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Cache local du catalogue Nutriweb par client.
 * Synchronise via le bouton Synchroniser sur /catalogue.
 */
final class NutriwebCatalogRepository
{
    private function pdo(): PDO
    {
        return Database::pdo();
    }

    /**
     * Retourne toutes les lignes du catalogue pour un client.
     * @return list<array<string,mixed>>
     */
    public function listForClient(string $clientId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM nutriweb_catalog
              WHERE client_id = :client_id
              ORDER BY name ASC, sku ASC'
        );
        $stmt->execute([':client_id' => $clientId]);
        return $stmt->fetchAll();
    }

    /**
     * Retourne tous les SKUs partageant le meme permalink (siblings d'un produit Nutriweb).
     * @return list<array<string,mixed>>
     */
    public function listByPermalink(string $clientId, string $permalink): array
    {
        if ($permalink === '') return [];
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM nutriweb_catalog
              WHERE client_id = :client_id AND permalink = :permalink
              ORDER BY size_rank ASC, sku ASC'
        );
        $stmt->execute([':client_id' => $clientId, ':permalink' => $permalink]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findBySku(string $clientId, string $sku): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM nutriweb_catalog WHERE client_id = :client_id AND sku = :sku LIMIT 1'
        );
        $stmt->execute([':client_id' => $clientId, ':sku' => $sku]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Renvoie le timestamp de la derniere synchronisation pour ce client.
     */
    public function lastSyncedAt(string $clientId): ?string
    {
        $stmt = $this->pdo()->prepare(
            'SELECT MAX(last_synced_at) AS t FROM nutriweb_catalog WHERE client_id = :client_id'
        );
        $stmt->execute([':client_id' => $clientId]);
        $row = $stmt->fetch();
        return $row && $row['t'] !== null ? (string) $row['t'] : null;
    }

    /**
     * Upsert d'un batch de rows venant du feed Nutriweb.
     *
     * @param list<array<string,mixed>> $rows
     * @return int Nombre de rows traitees
     */
    public function upsertBatch(string $clientId, array $rows): int
    {
        $sql = 'INSERT INTO nutriweb_catalog
                  (id, client_id, sku, name, brand, barcode, size, size_rank,
                   color, flavor, permalink, image_url,
                   price_base, price_selling, price_retail, purchase_price,
                   last_synced_at)
                VALUES
                  (:id, :client_id, :sku, :name, :brand, :barcode, :size, :size_rank,
                   :color, :flavor, :permalink, :image_url,
                   :pb, :ps, :pr, :pp, NOW())
                ON DUPLICATE KEY UPDATE
                  name = VALUES(name),
                  brand = VALUES(brand),
                  barcode = VALUES(barcode),
                  size = VALUES(size),
                  size_rank = VALUES(size_rank),
                  color = VALUES(color),
                  flavor = VALUES(flavor),
                  permalink = VALUES(permalink),
                  image_url = VALUES(image_url),
                  price_base = VALUES(price_base),
                  price_selling = VALUES(price_selling),
                  price_retail = VALUES(price_retail),
                  purchase_price = VALUES(purchase_price),
                  last_synced_at = NOW(),
                  updated_at = NOW()';

        $pdo = $this->pdo();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare($sql);
        $count = 0;
        try {
            foreach ($rows as $r) {
                $sku = trim((string) ($r['sku'] ?? ''));
                if ($sku === '') continue;
                $stmt->execute([
                    ':id' => Uuid::uuid4()->toString(),
                    ':client_id' => $clientId,
                    ':sku' => $sku,
                    ':name' => $r['name'] !== '' ? $r['name'] : null,
                    ':brand' => $r['brand'] !== '' ? $r['brand'] : null,
                    ':barcode' => $r['barcode'] !== '' ? $r['barcode'] : null,
                    ':size' => $r['size'] ?? null,
                    ':size_rank' => $r['size_rank'] ?? null,
                    ':color' => $r['color'] ?? null,
                    ':flavor' => $r['flavor'] ?? null,
                    ':permalink' => $r['permalink'] ?? null,
                    ':image_url' => $r['image_url'] ?? null,
                    ':pb' => $r['price_base'] ?? null,
                    ':ps' => $r['price_selling'] ?? null,
                    ':pr' => $r['price_retail'] ?? null,
                    ':pp' => $r['purchase_price'] ?? null,
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
     * Supprime les SKUs qui n'apparaissent plus dans le feed (purge orphelins).
     * @param list<string> $currentSkus  SKUs encore présents dans le feed
     * @return int Nombre de lignes supprimees
     */
    public function deleteStale(string $clientId, array $currentSkus): int
    {
        if ($currentSkus === []) {
            // Pas de SKUs : on ne purge rien (probable bug du feed).
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($currentSkus), '?'));
        $sql = 'DELETE FROM nutriweb_catalog WHERE client_id = ? AND sku NOT IN (' . $placeholders . ')';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(array_merge([$clientId], $currentSkus));
        return $stmt->rowCount();
    }

    /**
     * Recalcule les colonnes presta_product_id / presta_combination_id pour TOUS
     * les SKUs du client, en joignant presta_products + presta_product_combinations
     * sur supplier_reference = sku. Les combinations sont prioritaires.
     *
     * IMPORTANT : ne WIPE PAS avant le recompute. Les liens directs ecrits par
     * setPrestaLink() (apres /catalogue/create) sont conserves meme si presta_products
     * n'a pas encore ete sync. Sinon un /catalogue/sync apres une creation produit
     * mettait tout en 'non lie' tant que /produits/sync n'avait pas tourne.
     */
    public function recomputeMatches(string $clientId): void
    {
        $pdo = $this->pdo();

        // 1) Match produits racine : supplier_reference (presta_products) = sku
        //    Reset presta_combination_id si on trouve un match produit racine
        //    (car le row precedent pouvait pointer sur une decli qui n'existe plus).
        $pdo->prepare(
            'UPDATE nutriweb_catalog nc
               JOIN presta_products pp
                 ON pp.client_id = nc.client_id
                AND pp.supplier_reference IS NOT NULL
                AND pp.supplier_reference <> ""
                AND pp.supplier_reference = nc.sku
                SET nc.presta_product_id = pp.presta_id,
                    nc.presta_combination_id = NULL
              WHERE nc.client_id = :cid'
        )->execute([':cid' => $clientId]);

        // 2) Match combinations : supplier_reference (presta_product_combinations) = sku
        //    Override le match produit racine si plus specifique.
        $pdo->prepare(
            'UPDATE nutriweb_catalog nc
               JOIN presta_product_combinations pc
                 ON pc.client_id = nc.client_id
                AND pc.supplier_reference IS NOT NULL
                AND pc.supplier_reference <> ""
                AND pc.supplier_reference = nc.sku
                SET nc.presta_product_id = pc.presta_product_id,
                    nc.presta_combination_id = pc.presta_combination_id
              WHERE nc.client_id = :cid'
        )->execute([':cid' => $clientId]);

        // 3) Invalidation des liens orphelins : si presta_product_id pointe sur un
        //    produit qui n'existe plus dans le cache presta_products (ex supprime
        //    cote PS + /produits/sync a purge), on wipe le lien.
        //    Idem pour les combinations.
        $pdo->prepare(
            'UPDATE nutriweb_catalog nc
        LEFT JOIN presta_products pp
               ON pp.client_id = nc.client_id AND pp.presta_id = nc.presta_product_id
              SET nc.presta_product_id = NULL,
                  nc.presta_combination_id = NULL
            WHERE nc.client_id = :cid
              AND nc.presta_product_id IS NOT NULL
              AND pp.id IS NULL'
        )->execute([':cid' => $clientId]);
        $pdo->prepare(
            'UPDATE nutriweb_catalog nc
        LEFT JOIN presta_product_combinations pc
               ON pc.client_id = nc.client_id AND pc.presta_combination_id = nc.presta_combination_id
              SET nc.presta_combination_id = NULL
            WHERE nc.client_id = :cid
              AND nc.presta_combination_id IS NOT NULL
              AND pc.id IS NULL'
        )->execute([':cid' => $clientId]);
    }

    /**
     * Met a jour le lien Presta d'un SKU apres creation depuis /catalogue/create.
     * Si combinationId > 0 : lie a une declination. Sinon : produit racine.
     */
    public function setPrestaLink(string $clientId, string $sku, int $prestaProductId, int $prestaCombinationId = 0): void
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE nutriweb_catalog
                SET presta_product_id = :pid,
                    presta_combination_id = :cid,
                    updated_at = NOW()
              WHERE client_id = :client_id AND sku = :sku'
        );
        $stmt->execute([
            ':pid' => $prestaProductId,
            ':cid' => $prestaCombinationId > 0 ? $prestaCombinationId : null,
            ':client_id' => $clientId,
            ':sku' => $sku,
        ]);
    }
}
