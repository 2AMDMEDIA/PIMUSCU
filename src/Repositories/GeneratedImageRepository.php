<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Historique des générations d'images IA (Kie.AI) attachées à un produit.
 */
final class GeneratedImageRepository
{
    private function pdo(): PDO
    {
        return Database::pdo();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForProduct(string $clientId, string $productId, int $limit = 20): array
    {
        $sql = 'SELECT * FROM generated_images
                 WHERE client_id = :client_id
                   AND product_id = :product_id
                 ORDER BY created_at DESC
                 LIMIT :limit';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->bindValue(':client_id', $clientId);
        $stmt->bindValue(':product_id', $productId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findById(string $clientId, string $id): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM generated_images WHERE client_id = :client_id AND id = :id LIMIT 1'
        );
        $stmt->execute([':client_id' => $clientId, ':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Crée un enregistrement de génération en statut pending.
     *
     * @param list<string> $inputUrls
     * @return string UUID de la génération créée
     */
    public function create(
        string $clientId,
        string $productId,
        int $prestaProductId,
        string $prompt,
        array $inputUrls,
        string $model,
        string $taskId,
        ?string $parentGenerationId = null,
    ): string {
        $id = Uuid::uuid4()->toString();
        $stmt = $this->pdo()->prepare(
            'INSERT INTO generated_images
                (id, client_id, product_id, presta_product_id, prompt, input_urls,
                 model, task_id, status, parent_generation_id)
             VALUES
                (:id, :client_id, :product_id, :presta_id, :prompt, :urls,
                 :model, :task_id, "pending", :parent)'
        );
        $stmt->execute([
            ':id' => $id,
            ':client_id' => $clientId,
            ':product_id' => $productId,
            ':presta_id' => $prestaProductId,
            ':prompt' => $prompt,
            ':urls' => json_encode(array_values($inputUrls), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':model' => $model,
            ':task_id' => $taskId,
            ':parent' => $parentGenerationId,
        ]);
        return $id;
    }

    public function updateStatus(string $clientId, string $id, string $status, ?string $imageUrl = null, ?string $errorMessage = null): void
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE generated_images
                SET status = :status,
                    image_url = :image_url,
                    error_message = :error,
                    updated_at = NOW()
              WHERE client_id = :client_id AND id = :id'
        );
        $stmt->execute([
            ':status' => $status,
            ':image_url' => $imageUrl,
            ':error' => $errorMessage,
            ':client_id' => $clientId,
            ':id' => $id,
        ]);
    }

    public function markPushedToGallery(string $clientId, string $id, int $prestaImageId): void
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE generated_images
                SET pushed_image_id = :img_id,
                    updated_at = NOW()
              WHERE client_id = :client_id AND id = :id'
        );
        $stmt->execute([
            ':img_id' => $prestaImageId,
            ':client_id' => $clientId,
            ':id' => $id,
        ]);
    }

    public function delete(string $clientId, string $id): void
    {
        $stmt = $this->pdo()->prepare(
            'DELETE FROM generated_images WHERE client_id = :client_id AND id = :id'
        );
        $stmt->execute([':client_id' => $clientId, ':id' => $id]);
    }
}
