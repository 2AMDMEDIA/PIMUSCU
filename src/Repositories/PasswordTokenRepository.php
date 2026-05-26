<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use Ramsey\Uuid\Uuid;

final class PasswordTokenRepository
{
    private function pdo(): PDO
    {
        return Database::pdo();
    }

    /**
     * Crée un token et retourne sa valeur en clair (à inclure dans l'URL).
     * Le token n'est PAS hashé en DB ici (table déjà cloisonnée par UNIQUE et expiration).
     */
    public function create(string $userId, string $type, int $lifetimeSeconds): string
    {
        if (!in_array($type, ['reset', 'invitation'], true)) {
            throw new \InvalidArgumentException('Type de token invalide.');
        }
        $token = bin2hex(random_bytes(32));
        $stmt = $this->pdo()->prepare(
            'INSERT INTO password_tokens (id, user_id, token, type, expires_at)
             VALUES (:id, :user_id, :token, :type, DATE_ADD(NOW(), INTERVAL :seconds SECOND))'
        );
        $stmt->execute([
            ':id' => Uuid::uuid4()->toString(),
            ':user_id' => $userId,
            ':token' => $token,
            ':type' => $type,
            ':seconds' => $lifetimeSeconds,
        ]);
        return $token;
    }

    /**
     * Trouve un token valide (non utilisé, non expiré). Retourne la ligne ou null.
     *
     * @return array<string,mixed>|null
     */
    public function findValid(string $token, string $type): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM password_tokens
             WHERE token = :token AND type = :type AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':token' => $token, ':type' => $type]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function markUsed(string $tokenId): void
    {
        $stmt = $this->pdo()->prepare('UPDATE password_tokens SET used_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $tokenId]);
    }

    /** Supprime tous les tokens d'un user pour un type donné (utile au reset). */
    public function purgeForUser(string $userId, string $type): void
    {
        $stmt = $this->pdo()->prepare(
            'DELETE FROM password_tokens WHERE user_id = :user_id AND type = :type'
        );
        $stmt->execute([':user_id' => $userId, ':type' => $type]);
    }
}
