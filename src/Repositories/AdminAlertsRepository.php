<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use Ramsey\Uuid\Uuid;

final class AdminAlertsRepository
{
    private function pdo(): PDO
    {
        return Database::pdo();
    }

    /**
     * Crée une alerte si aucune alerte du même type/client n'existe déjà non-lue.
     * Évite le spam quand le seuil est dépassé plusieurs fois en peu de temps.
     */
    public function pushIfNew(string $clientId, string $type, string $message): void
    {
        $stmt = $this->pdo()->prepare(
            'SELECT id FROM admin_alerts
              WHERE client_id = :client_id AND type = :type AND read_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([':client_id' => $clientId, ':type' => $type]);
        if ($stmt->fetch()) {
            return;
        }

        $insert = $this->pdo()->prepare(
            'INSERT INTO admin_alerts (id, client_id, type, message) VALUES (:id, :client_id, :type, :msg)'
        );
        $insert->execute([
            ':id' => Uuid::uuid4()->toString(),
            ':client_id' => $clientId,
            ':type' => $type,
            ':msg' => $message,
        ]);
    }
}
