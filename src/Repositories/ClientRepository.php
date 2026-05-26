<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Models\Client;
use PDO;
use Ramsey\Uuid\Uuid;

final class ClientRepository
{
    private function pdo(): PDO
    {
        return Database::pdo();
    }

    public function findById(string $id): ?Client
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM clients WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ? Client::fromRow($row) : null;
    }

    public function findFirstForUser(string $userId): ?Client
    {
        $stmt = $this->pdo()->prepare(
            'SELECT c.* FROM clients c
             INNER JOIN user_clients uc ON uc.client_id = c.id
             WHERE uc.user_id = :user_id
             ORDER BY c.created_at ASC
             LIMIT 1'
        );
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ? Client::fromRow($row) : null;
    }

    /** @return list<array<string,mixed>> Lignes brutes pour l'admin (avec compteurs) */
    public function listAllWithStats(): array
    {
        $sql = 'SELECT
                    c.*,
                    (SELECT COUNT(*) FROM user_clients uc WHERE uc.client_id = c.id) AS user_count,
                    (SELECT COALESCE(SUM(total_tokens), 0) FROM ai_usage_logs aul
                        WHERE aul.client_id = c.id
                          AND aul.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS tokens_30d
                FROM clients c
                ORDER BY c.created_at DESC';
        return $this->pdo()->query($sql)->fetchAll();
    }

    /**
     * Crée un nouveau client. Retourne l'objet créé.
     */
    public function create(string $name, string $prestashopUrl, ?string $logoUrl, ?string $footerName, int $tokenMonthlyLimit): Client
    {
        $id = Uuid::uuid4()->toString();
        $stmt = $this->pdo()->prepare(
            'INSERT INTO clients (id, name, prestashop_url, logo_url, footer_name, token_monthly_limit, enabled_modules)
             VALUES (:id, :name, :url, :logo, :footer, :limit, :modules)'
        );
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':url' => $prestashopUrl,
            ':logo' => $logoUrl,
            ':footer' => $footerName,
            ':limit' => $tokenMonthlyLimit,
            ':modules' => json_encode(['blog' => false], JSON_UNESCAPED_UNICODE),
        ]);

        $client = $this->findById($id);
        if ($client === null) {
            throw new \RuntimeException('Création client échouée.');
        }
        return $client;
    }

    public function update(string $id, string $name, string $prestashopUrl, ?string $logoUrl, ?string $footerName, int $tokenMonthlyLimit, int $tokenAlertThreshold): void
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE clients
                SET name = :name,
                    prestashop_url = :url,
                    logo_url = :logo,
                    footer_name = :footer,
                    token_monthly_limit = :limit,
                    token_alert_threshold = :threshold,
                    updated_at = NOW()
              WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':url' => $prestashopUrl,
            ':logo' => $logoUrl,
            ':footer' => $footerName,
            ':limit' => $tokenMonthlyLimit,
            ':threshold' => $tokenAlertThreshold,
        ]);
    }

    public function updateLogoUrl(string $id, string $logoUrl): void
    {
        $stmt = $this->pdo()->prepare('UPDATE clients SET logo_url = :logo, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $id, ':logo' => $logoUrl]);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo()->prepare('DELETE FROM clients WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /** @return list<array{id:string,email:string,full_name:string,last_login_at:?string}> */
    public function usersForClient(string $clientId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT u.id, u.email, u.full_name, u.last_login_at
               FROM users u
               INNER JOIN user_clients uc ON uc.user_id = u.id
              WHERE uc.client_id = :client_id
              ORDER BY u.created_at ASC'
        );
        $stmt->execute([':client_id' => $clientId]);
        return $stmt->fetchAll();
    }
}
