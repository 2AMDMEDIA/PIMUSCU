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
    public function insertMinimal(string $clientId, int $prestaProductId, int $prestaCombinationId, string $reference, ?string $barcode, ?string $supplierReference, ?string $attributesLabel = null): void
    {
        $sql = 'INSERT INTO presta_product_combinations
                    (id, client_id, presta_product_id, presta_combination_id,
                     reference, barcode, supplier_reference, attributes_label, synced_at)
                VALUES
                    (:id, :client_id, :pid, :cid, :ref, :barcode, :sref, :attrs, NOW())
                ON DUPLICATE KEY UPDATE
                    presta_product_id = VALUES(presta_product_id),
                    reference = VALUES(reference),
                    barcode = VALUES(barcode),
                    supplier_reference = VALUES(supplier_reference),
                    attributes_label = VALUES(attributes_label),
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
        ]);
    }

    /**
     * Upsert d'un batch de combinaisons.
     *
     * @param list<array{
     *     id:int, id_product:int, reference:string, ean13:string,
     *     attributes_label?:string, supplier_reference?:?string,
     * }> $combinations
     */
    public function upsertBatch(string $clientId, array $combinations): int
    {
        $sql = 'INSERT INTO presta_product_combinations
                    (id, client_id, presta_product_id, presta_combination_id,
                     reference, barcode, supplier_reference, attributes_label, synced_at)
                VALUES
                    (:id, :client_id, :pid, :cid,
                     :ref, :barcode, :sref, :attrs, NOW())
                ON DUPLICATE KEY UPDATE
                    presta_product_id = VALUES(presta_product_id),
                    reference = VALUES(reference),
                    barcode = VALUES(barcode),
                    supplier_reference = VALUES(supplier_reference),
                    attributes_label = VALUES(attributes_label),
                    synced_at = NOW(),
                    updated_at = NOW()';

        $pdo = $this->pdo();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare($sql);
        $count = 0;
        try {
            foreach ($combinations as $c) {
                $stmt->execute([
                    ':id' => Uuid::uuid4()->toString(),
                    ':client_id' => $clientId,
                    ':pid' => (int) $c['id_product'],
                    ':cid' => (int) $c['id'],
                    ':ref' => $c['reference'] !== '' ? $c['reference'] : null,
                    ':barcode' => $c['ean13'] !== '' ? $c['ean13'] : null,
                    ':sref' => $c['supplier_reference'] ?? null,
                    ':attrs' => $c['attributes_label'] ?? null,
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
