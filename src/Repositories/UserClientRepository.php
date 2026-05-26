<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use Ramsey\Uuid\Uuid;

final class UserClientRepository
{
    private function pdo(): PDO
    {
        return Database::pdo();
    }

    public function link(string $userId, string $clientId): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT IGNORE INTO user_clients (id, user_id, client_id)
             VALUES (:id, :user_id, :client_id)'
        );
        $stmt->execute([
            ':id' => Uuid::uuid4()->toString(),
            ':user_id' => $userId,
            ':client_id' => $clientId,
        ]);
    }

    public function unlink(string $userId, string $clientId): void
    {
        $stmt = $this->pdo()->prepare(
            'DELETE FROM user_clients WHERE user_id = :user_id AND client_id = :client_id'
        );
        $stmt->execute([':user_id' => $userId, ':client_id' => $clientId]);
    }

    /** @return list<string> Liste des client_ids pour un user. */
    public function clientIdsForUser(string $userId): array
    {
        $stmt = $this->pdo()->prepare('SELECT client_id FROM user_clients WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);
        return array_map(fn ($r) => $r['client_id'], $stmt->fetchAll());
    }
}
