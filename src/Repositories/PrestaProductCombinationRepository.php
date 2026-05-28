<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use Ramsey\Uuid\Uuid;

final class PrestaProductCombinationRepository
{
    private function pdo(): PDO
    {
        return Database::pdo();
    }

    /**
     * Liste les combinaisons d'un produit (par presta_product_id) pour un client.
     *
     * @return list<array<string,mixed>>
     */
    public function listForProduct(string $clientId, int $prestaProductId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT presta_combination_id, reference, barcode, supplier_reference, attributes_label
               FROM presta_product_combinations
              WHERE client_id = :client_id
                AND presta_product_id = :pid
              ORDER BY attributes_label ASC, presta_combination_id ASC'
        );
        $stmt->execute([
            ':client_id' => $clientId,
            ':pid' => $prestaProductId,
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Retourne les presta_product_id distincts qui ont au moins 1 combination,
     * avec le nombre de combinations. Map presta_product_id => nb_combinations.
     *
     * @return array<int, int>
     */
    public function productIdsWithCombinations(string $clientId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT presta_product_id, COUNT(*) AS nb
               FROM presta_product_combinations
              WHERE client_id = :cid
              GROUP BY presta_product_id'
        );
        $stmt->execute([':cid' => $clientId]);
        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $map[(int) $r['presta_product_id']] = (int) $r['nb'];
        }
        return $map;
    }

    /**
     * Construit le WHERE + params communs aux requêtes "déclinaisons multi-attributs".
     * Une décli multi-attributs = attributes_label contient au moins un séparateur ' · '.
     * Filtres optionnels : recherche (nom produit / réf / réf fournisseur) + marque.
     *
     * @return array{0:string, 1:array<string,mixed>}
     */
    private function multiAttrWhere(string $clientId, string $search = '', string $brand = '', string $active = ''): array
    {
        $where = ['c.client_id = :cid', "c.attributes_label LIKE '% · %'"];
        $params = [':cid' => $clientId];

        if ($search !== '') {
            // Placeholders distincts : PDO (prepares natifs) interdit la réutilisation
            // d'un même nom -> sinon SQLSTATE[HY093] Invalid parameter number.
            $where[] = '(p.name LIKE :s_name OR c.reference LIKE :s_ref OR c.supplier_reference LIKE :s_sref)';
            $like = '%' . $search . '%';
            $params[':s_name'] = $like;
            $params[':s_ref'] = $like;
            $params[':s_sref'] = $like;
        }
        if ($brand !== '') {
            $where[] = 'p.manufacturer_name = :brand';
            $params[':brand'] = $brand;
        }
        if ($active === '1' || $active === '0') {
            $where[] = 'p.active = :active';
            $params[':active'] = (int) $active;
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * CONTRÔLE : compte les déclinaisons multi-attributs (avec filtres).
     */
    public function countWithMultipleAttributes(string $clientId, string $search = '', string $brand = '', string $active = ''): int
    {
        [$where, $params] = $this->multiAttrWhere($clientId, $search, $brand, $active);
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*)
               FROM presta_product_combinations c
               LEFT JOIN presta_products p
                 ON p.client_id = c.client_id AND p.presta_id = c.presta_product_id
              WHERE ' . $where
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * CONTRÔLE : nombre de produits DISTINCTS parmi les déclinaisons multi-attributs (avec filtres).
     */
    public function countDistinctProductsWithMultipleAttributes(string $clientId, string $search = '', string $brand = '', string $active = ''): int
    {
        [$where, $params] = $this->multiAttrWhere($clientId, $search, $brand, $active);
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(DISTINCT c.presta_product_id)
               FROM presta_product_combinations c
               LEFT JOIN presta_products p
                 ON p.client_id = c.client_id AND p.presta_id = c.presta_product_id
              WHERE ' . $where
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * CONTRÔLE : liste paginée des déclinaisons multi-attributs (avec filtres).
     * Jointe au produit parent (nom / uuid / réf / marque).
     *
     * @return list<array{
     *     presta_product_id:int, presta_combination_id:int, reference:?string,
     *     supplier_reference:?string, attributes_label:?string, option_value_ids:?string,
     *     product_uuid:?string, product_name:?string, product_reference:?string, brand:?string
     * }>
     */
    public function listWithMultipleAttributes(string $clientId, string $search = '', string $brand = '', int $limit = 50, int $offset = 0, string $active = ''): array
    {
        [$where, $params] = $this->multiAttrWhere($clientId, $search, $brand, $active);
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $stmt = $this->pdo()->prepare(
            'SELECT c.presta_product_id, c.presta_combination_id, c.reference,
                    c.supplier_reference, c.attributes_label, c.option_value_ids,
                    p.id AS product_uuid, p.name AS product_name, p.reference AS product_reference,
                    p.manufacturer_name AS brand
               FROM presta_product_combinations c
               LEFT JOIN presta_products p
                 ON p.client_id = c.client_id AND p.presta_id = c.presta_product_id
              WHERE ' . $where . '
              ORDER BY p.name ASC, c.attributes_label ASC
              LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * CONTRÔLE : marques distinctes parmi les déclinaisons multi-attributs (pour le filtre).
     * @return list<string>
     */
    public function distinctBrandsWithMultipleAttributes(string $clientId): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT DISTINCT p.manufacturer_name AS brand
               FROM presta_product_combinations c
               JOIN presta_products p
                 ON p.client_id = c.client_id AND p.presta_id = c.presta_product_id
              WHERE c.client_id = :cid
                AND c.attributes_label LIKE '% · %'
                AND p.manufacturer_name IS NOT NULL
                AND p.manufacturer_name <> ''
              ORDER BY p.manufacturer_name ASC"
        );
        $stmt->execute([':cid' => $clientId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = (string) $r['brand'];
        }
        return $out;
    }

    /**
     * Construit le bloc FROM/WHERE des "produits avec exactement 1 déclinaison".
     * Sous-requête : produits dont COUNT(combinations) = 1, puis on rejoint la
     * combination unique + le produit. Filtres optionnels recherche + marque.
     * NB : client_id apparaît 2x (sous-requête + extérieur) -> noms de params
     * distincts (:cid_sub / :cid_out) pour éviter SQLSTATE[HY093].
     *
     * @param array<string,mixed> $params (rempli par référence)
     */
    private function singleComboFrom(string $clientId, string $search, string $brand, array &$params, string $active = ''): string
    {
        $params[':cid_sub'] = $clientId;
        $params[':cid_out'] = $clientId;
        $extra = '';
        if ($search !== '') {
            $like = '%' . $search . '%';
            $extra .= ' AND (p.name LIKE :s_name OR c.reference LIKE :s_ref OR c.supplier_reference LIKE :s_sref)';
            $params[':s_name'] = $like;
            $params[':s_ref'] = $like;
            $params[':s_sref'] = $like;
        }
        if ($brand !== '') {
            $extra .= ' AND p.manufacturer_name = :brand';
            $params[':brand'] = $brand;
        }
        if ($active === '1' || $active === '0') {
            $extra .= ' AND p.active = :active';
            $params[':active'] = (int) $active;
        }
        return 'FROM presta_product_combinations c
                JOIN (
                    SELECT presta_product_id
                      FROM presta_product_combinations
                     WHERE client_id = :cid_sub
                     GROUP BY presta_product_id
                    HAVING COUNT(*) = 1
                ) one ON one.presta_product_id = c.presta_product_id
                LEFT JOIN presta_products p
                       ON p.client_id = c.client_id AND p.presta_id = c.presta_product_id
                 WHERE c.client_id = :cid_out' . $extra;
    }

    /**
     * CONTRÔLE : compte les produits ayant exactement 1 déclinaison (avec filtres).
     */
    public function countProductsWithSingleCombination(string $clientId, string $search = '', string $brand = '', string $active = ''): int
    {
        $params = [];
        $from = $this->singleComboFrom($clientId, $search, $brand, $params, $active);
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) ' . $from);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * CONTRÔLE : liste paginée des produits ayant exactement 1 déclinaison (avec filtres).
     *
     * @return list<array{
     *     presta_product_id:int, presta_combination_id:int, reference:?string,
     *     supplier_reference:?string, attributes_label:?string, option_value_ids:?string,
     *     product_uuid:?string, product_name:?string, product_reference:?string, brand:?string
     * }>
     */
    public function listProductsWithSingleCombination(string $clientId, string $search = '', string $brand = '', int $limit = 50, int $offset = 0, string $active = ''): array
    {
        $params = [];
        $from = $this->singleComboFrom($clientId, $search, $brand, $params, $active);
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $stmt = $this->pdo()->prepare(
            'SELECT c.presta_product_id, c.presta_combination_id, c.reference,
                    c.supplier_reference, c.attributes_label, c.option_value_ids,
                    p.id AS product_uuid, p.name AS product_name, p.reference AS product_reference,
                    p.manufacturer_name AS brand '
            . $from
            . ' ORDER BY p.name ASC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * CONTRÔLE : marques distinctes parmi les produits à 1 seule déclinaison (pour le filtre).
     * @return list<string>
     */
    public function distinctBrandsWithSingleCombination(string $clientId): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT DISTINCT p.manufacturer_name AS brand
               FROM presta_product_combinations c
               JOIN (
                    SELECT presta_product_id
                      FROM presta_product_combinations
                     WHERE client_id = :cid_sub
                     GROUP BY presta_product_id
                    HAVING COUNT(*) = 1
               ) one ON one.presta_product_id = c.presta_product_id
               JOIN presta_products p
                 ON p.client_id = c.client_id AND p.presta_id = c.presta_product_id
              WHERE c.client_id = :cid_out
                AND p.manufacturer_name IS NOT NULL
                AND p.manufacturer_name <> ''
              ORDER BY p.manufacturer_name ASC"
        );
        $stmt->execute([':cid_sub' => $clientId, ':cid_out' => $clientId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = (string) $r['brand'];
        }
        return $out;
    }

    /**
     * Supprime toutes les combinaisons d'un client (avant resync complet).
     */
    public function clearForClient(string $clientId): void
    {
        $stmt = $this->pdo()->prepare(
            'DELETE FROM presta_product_combinations WHERE client_id = :client_id'
        );
        $stmt->execute([':client_id' => $clientId]);
    }

    /**
     * Insert minimal d'une combination fraichement creee (via /catalogue/create) pour
     * que le cache local soit a jour immediatement, sans attendre /produits/sync.
     */
    /**
     * @param list<int> $optionValueIds Ids des product_option_values composant la décli.
     */
    public function insertMinimal(string $clientId, int $prestaProductId, int $prestaCombinationId, string $reference, ?string $barcode, ?string $supplierReference, ?string $attributesLabel = null, array $optionValueIds = []): void
    {
        $clean = array_values(array_filter(array_map('intval', $optionValueIds), fn($i) => $i > 0));
        $ovIds = $clean !== [] ? implode(',', $clean) : null;
        $sql = 'INSERT INTO presta_product_combinations
                    (id, client_id, presta_product_id, presta_combination_id,
                     reference, barcode, supplier_reference, attributes_label, option_value_ids, synced_at)
                VALUES
                    (:id, :client_id, :pid, :cid, :ref, :barcode, :sref, :attrs, :ovids, NOW())
                ON DUPLICATE KEY UPDATE
                    presta_product_id = VALUES(presta_product_id),
                    reference = VALUES(reference),
                    barcode = VALUES(barcode),
                    supplier_reference = VALUES(supplier_reference),
                    attributes_label = VALUES(attributes_label),
                    option_value_ids = VALUES(option_value_ids),
                    synced_at = NOW(),
                    updated_at = NOW()';
        $this->pdo()->prepare($sql)->execute([
            ':id' => Uuid::uuid4()->toString(),
            ':client_id' => $clientId,
            ':pid' => $prestaProductId,
            ':cid' => $prestaCombinationId,
            ':ref' => $reference !== '' ? $reference : null,
            ':barcode' => $barcode !== '' ? $barcode : null,
            ':sref' => $supplierReference,
            ':attrs' => $attributesLabel,
            ':ovids' => $ovIds,
        ]);
    }

    /**
     * Upsert d'un batch de combinaisons.
     *
     * @param list<array{
     *     id:int, id_product:int, reference:string, ean13:string,
     *     attributes_label?:string, supplier_reference?:?string,
     *     option_value_ids?:list<int>,
     * }> $combinations
     */
    public function upsertBatch(string $clientId, array $combinations): int
    {
        $sql = 'INSERT INTO presta_product_combinations
                    (id, client_id, presta_product_id, presta_combination_id,
                     reference, barcode, supplier_reference, attributes_label, option_value_ids, synced_at)
                VALUES
                    (:id, :client_id, :pid, :cid,
                     :ref, :barcode, :sref, :attrs, :ovids, NOW())
                ON DUPLICATE KEY UPDATE
                    presta_product_id = VALUES(presta_product_id),
                    reference = VALUES(reference),
                    barcode = VALUES(barcode),
                    supplier_reference = VALUES(supplier_reference),
                    attributes_label = VALUES(attributes_label),
                    option_value_ids = VALUES(option_value_ids),
                    synced_at = NOW(),
                    updated_at = NOW()';

        $pdo = $this->pdo();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare($sql);
        $count = 0;
        try {
            foreach ($combinations as $c) {
                $ovIds = '';
                if (isset($c['option_value_ids']) && is_array($c['option_value_ids'])) {
                    $clean = array_values(array_filter(array_map('intval', $c['option_value_ids']), fn($i) => $i > 0));
                    $ovIds = implode(',', $clean);
                }
                $stmt->execute([
                    ':id' => Uuid::uuid4()->toString(),
                    ':client_id' => $clientId,
                    ':pid' => (int) $c['id_product'],
                    ':cid' => (int) $c['id'],
                    ':ref' => $c['reference'] !== '' ? $c['reference'] : null,
                    ':barcode' => $c['ean13'] !== '' ? $c['ean13'] : null,
                    ':sref' => $c['supplier_reference'] ?? null,
                    ':attrs' => $c['attributes_label'] ?? null,
                    ':ovids' => $ovIds !== '' ? $ovIds : null,
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
}
