<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

/**
 * Requêtes spécifiques à la zone admin : super-admins, alertes, agrégats.
 */
final class AdminRepository
{
    private function pdo(): PDO
    {
        return Database::pdo();
    }

    /** @return list<array{id:string,email:string,full_name:string,created_at:string,last_login_at:?string}> */
    public function listSuperAdmins(): array
    {
        $sql = 'SELECT id, email, full_name, created_at, last_login_at
                  FROM users
                 WHERE is_super_admin = 1
                 ORDER BY created_at ASC';
        return $this->pdo()->query($sql)->fetchAll();
    }

    public function setSuperAdmin(string $userId, bool $value): void
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE users SET is_super_admin = :v, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':id' => $userId, ':v' => $value ? 1 : 0]);
    }

    /**
     * Stats d'usage tokens par client sur les 30 derniers jours.
     *
     * @return array{
     *     total_calls:int,
     *     tokens_total:int,
     *     prompt_tokens:int,
     *     completion_tokens:int,
     *     cost_eur:float,
     * }
     */
    public function usageStatsForClient(string $clientId, int $days = 30): array
    {
        $sql = 'SELECT
                    COUNT(*) AS total_calls,
                    COALESCE(SUM(total_tokens), 0) AS tokens_total,
                    COALESCE(SUM(prompt_tokens), 0) AS prompt_tokens,
                    COALESCE(SUM(completion_tokens), 0) AS completion_tokens,
                    COALESCE(SUM(cost_eur), 0) AS cost_eur
                  FROM ai_usage_logs
                 WHERE client_id = :client_id
                   AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->bindValue(':client_id', $clientId);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch() ?: ['total_calls' => 0, 'tokens_total' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0, 'cost_eur' => 0];
        return [
            'total_calls' => (int) $row['total_calls'],
            'tokens_total' => (int) $row['tokens_total'],
            'prompt_tokens' => (int) $row['prompt_tokens'],
            'completion_tokens' => (int) $row['completion_tokens'],
            'cost_eur' => (float) $row['cost_eur'],
        ];
    }

    /** @return list<array{provider:string,tokens_total:int,calls:int,cost_eur:float}> */
    public function usageByProviderForClient(string $clientId, int $days = 30): array
    {
        $sql = 'SELECT provider,
                       COALESCE(SUM(total_tokens), 0) AS tokens_total,
                       COUNT(*) AS calls,
                       COALESCE(SUM(cost_eur), 0) AS cost_eur
                  FROM ai_usage_logs
                 WHERE client_id = :client_id
                   AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                 GROUP BY provider
                 ORDER BY tokens_total DESC';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->bindValue(':client_id', $clientId);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
