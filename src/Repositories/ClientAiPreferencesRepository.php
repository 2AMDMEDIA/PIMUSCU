<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use Ramsey\Uuid\Uuid;

final class ClientAiPreferencesRepository
{
    private function pdo(): PDO
    {
        return Database::pdo();
    }

    /** @return array{default_text_provider:string,default_image_provider:string} */
    public function get(string $clientId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT default_text_provider, default_image_provider
               FROM client_ai_preferences
              WHERE client_id = :client_id LIMIT 1'
        );
        $stmt->execute([':client_id' => $clientId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['default_text_provider' => 'openrouter', 'default_image_provider' => 'kie'];
        }
        return [
            'default_text_provider' => $row['default_text_provider'],
            'default_image_provider' => $row['default_image_provider'],
        ];
    }

    public function save(string $clientId, string $textProvider, string $imageProvider): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO client_ai_preferences (id, client_id, default_text_provider, default_image_provider)
             VALUES (:id, :client_id, :text_ins, :image_ins)
             ON DUPLICATE KEY UPDATE default_text_provider = :text_upd, default_image_provider = :image_upd, updated_at = NOW()'
        );
        $stmt->execute([
            ':id' => Uuid::uuid4()->toString(),
            ':client_id' => $clientId,
            ':text_ins' => $textProvider,
            ':image_ins' => $imageProvider,
            ':text_upd' => $textProvider,
            ':image_upd' => $imageProvider,
        ]);
    }
}
