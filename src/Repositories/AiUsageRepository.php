<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use Ramsey\Uuid\Uuid;

final class AiUsageRepository
{
    private function pdo(): PDO
    {
        return Database::pdo();
    }

    public function log(
        string $clientId,
        string $provider,
        ?string $model,
        int $promptTokens,
        int $completionTokens,
        float $costEur,
        ?string $entityType = null,
        ?string $entityId = null,
    ): void {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO ai_usage_logs
                (id, client_id, provider, model, prompt_tokens, completion_tokens, total_tokens, cost_eur, entity_type, entity_id)
             VALUES
                (:id, :client_id, :provider, :model, :pt, :ct, :tt, :cost, :etype, :eid)'
        );
        $stmt->execute([
            ':id' => Uuid::uuid4()->toString(),
            ':client_id' => $clientId,
            ':provider' => $provider,
            ':model' => $model,
            ':pt' => $promptTokens,
            ':ct' => $completionTokens,
            ':tt' => $promptTokens + $completionTokens,
            ':cost' => $costEur,
            ':etype' => $entityType,
            ':eid' => $entityId,
        ]);
    }

    /**
     * Total des tokens consommés par le client sur la fenêtre mensuelle glissante (30 derniers jours).
     */
    public function tokensLast30Days(string $clientId): int
    {
        $stmt = $this->pdo()->prepare(
            'SELECT COALESCE(SUM(total_tokens), 0) FROM ai_usage_logs
              WHERE client_id = :client_id
                AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
        );
        $stmt->execute([':client_id' => $clientId]);
        return (int) $stmt->fetchColumn();
    }
}
