<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use Ramsey\Uuid\Uuid;

final class PrestaCategoryRepository
{
    private function pdo(): PDO
    {
        return Database::pdo();
    }

    /** @return list<array<string,mixed>> */
    public function listForClient(string $clientId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM presta_categories WHERE client_id = :client_id ORDER BY parent_id ASC, name ASC'
        );
        $stmt->execute([':client_id' => $clientId]);
        return $stmt->fetchAll();
    }

    public function countForClient(string $clientId): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM presta_categories WHERE client_id = :client_id');
        $stmt->execute([':client_id' => $clientId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function findById(string $clientId, string $id): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM presta_categories WHERE client_id = :client_id AND id = :id LIMIT 1'
        );
        $stmt->execute([':client_id' => $clientId, ':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Enregistre la version optimisée (édition manuelle ou IA).
     */
    public function saveOptimized(
        string $clientId,
        string $id,
        ?string $description,
        ?string $metaTitle,
        ?string $metaDescription,
        ?string $metaKeywords = null,
        ?string $name = null,
    ): void {
        $stmt = $this->pdo()->prepare(
            'UPDATE presta_categories
                SET optimized_name = :n,
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
            ':n' => $name,
            ':d' => $description,
            ':mt' => $metaTitle,
            ':md' => $metaDescription,
            ':mk' => $metaKeywords,
        ]);
    }

    /**
     * Met à jour la "Version actuelle" (cache local) après un push réussi sur PrestaShop.
     * Vide aussi la version optimisée puisqu'elle est maintenant en ligne.
     */
    /**
     * Met à jour la "Version actuelle" (cache local) après un push réussi.
     * Les versions optimisées sont CONSERVÉES pour permettre de retrouver
     * le contenu pré-rempli au prochain chargement et continuer à l'éditer.
     */
    public function applyAfterPush(
        string $clientId,
        string $id,
        ?string $description,
        ?string $metaTitle,
        ?string $metaDescription,
        ?string $metaKeywords = null,
        ?string $name = null,
    ): void {
        $sets = [];
        $params = [':client_id' => $clientId, ':id' => $id];

        if ($name !== null) {
            $sets[] = 'name = :n';
            $params[':n'] = $name;
        }
        if ($description !== null) {
            $sets[] = 'description = :d';
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

        $sql = 'UPDATE presta_categories SET ' . implode(', ', $sets)
            . ' WHERE client_id = :client_id AND id = :id';
        $this->pdo()->prepare($sql)->execute($params);
    }

    /**
     * Upsert d'une liste de catégories (depuis une sync Webservice).
     *
     * @param list<array{
     *     id:int, parent_id:int, name:string, description:string,
     *     meta_title:string, meta_description:string, meta_keywords?:string,
     *     link_rewrite:string, active:int, is_root_category:int,
     * }> $categories
     * @param array<int,int> $productsCount Map presta_id => products_count
     * @return int Nombre de lignes insérées/mises à jour
     */
    public function upsertBatch(string $clientId, array $categories, array $productsCount = []): int
    {
        $sql = 'INSERT INTO presta_categories
                  (id, client_id, presta_id, parent_id, name, description, meta_title, meta_description, meta_keywords,
                   link_rewrite, active, products_count, synced_at)
                VALUES
                  (:id, :client_id, :presta_id, :parent_id, :name, :description, :meta_title, :meta_description, :meta_keywords,
                   :link_rewrite, :active, :products_count, NOW())
                ON DUPLICATE KEY UPDATE
                  parent_id = VALUES(parent_id),
                  name = VALUES(name),
                  description = VALUES(description),
                  meta_title = VALUES(meta_title),
                  meta_description = VALUES(meta_description),
                  meta_keywords = VALUES(meta_keywords),
                  link_rewrite = VALUES(link_rewrite),
                  active = VALUES(active),
                  products_count = VALUES(products_count),
                  synced_at = NOW(),
                  updated_at = NOW()';

        $pdo = $this->pdo();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare($sql);
        $count = 0;
        try {
            foreach ($categories as $cat) {
                $stmt->execute([
                    ':id' => Uuid::uuid4()->toString(),
                    ':client_id' => $clientId,
                    ':presta_id' => $cat['id'],
                    ':parent_id' => $cat['parent_id'] ?: null,
                    ':name' => $cat['name'],
                    ':description' => $cat['description'],
                    ':meta_title' => $cat['meta_title'] !== '' ? $cat['meta_title'] : null,
                    ':meta_description' => $cat['meta_description'] !== '' ? $cat['meta_description'] : null,
                    ':meta_keywords' => (!empty($cat['meta_keywords'])) ? $cat['meta_keywords'] : null,
                    ':link_rewrite' => $cat['link_rewrite'] !== '' ? $cat['link_rewrite'] : null,
                    ':active' => $cat['active'] ? 1 : 0,
                    ':products_count' => $productsCount[$cat['id']] ?? 0,
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
