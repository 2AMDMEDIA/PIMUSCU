<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Bootstrap;
use App\Database;
use App\Models\User;
use PDO;
use Ramsey\Uuid\Uuid;

final class UserRepository
{
    private function pdo(): PDO
    {
        return Database::pdo();
    }

    public function findById(string $id): ?User
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ? User::fromRow($row) : null;
    }

    public function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => mb_strtolower($email)]);
        $row = $stmt->fetch();
        return $row ? User::fromRow($row) : null;
    }

    public function create(string $email, ?string $plainPassword, string $fullName, bool $isSuperAdmin = false, bool $needsPasswordSetup = false): User
    {
        $id = Uuid::uuid4()->toString();
        $cost = (int) (Bootstrap::config('app.security.bcrypt_cost') ?? 12);
        $hash = $plainPassword !== null
            ? password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => $cost])
            : null;

        $stmt = $this->pdo()->prepare(
            'INSERT INTO users (id, email, password_hash, full_name, is_super_admin, needs_password_setup)
             VALUES (:id, :email, :hash, :name, :super_admin, :needs_setup)'
        );
        $stmt->execute([
            ':id' => $id,
            ':email' => mb_strtolower($email),
            ':hash' => $hash,
            ':name' => $fullName,
            ':super_admin' => $isSuperAdmin ? 1 : 0,
            ':needs_setup' => $needsPasswordSetup ? 1 : 0,
        ]);

        $user = $this->findById($id);
        if ($user === null) {
            throw new \RuntimeException('Création utilisateur échouée.');
        }
        return $user;
    }

    public function updatePassword(string $userId, string $plainPassword): void
    {
        $cost = (int) (Bootstrap::config('app.security.bcrypt_cost') ?? 12);
        $hash = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => $cost]);
        $stmt = $this->pdo()->prepare(
            'UPDATE users SET password_hash = :hash, needs_password_setup = 0, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':hash' => $hash, ':id' => $userId]);
    }

    public function touchLastLogin(string $userId): void
    {
        $stmt = $this->pdo()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $userId]);
    }

    public function updateFullName(string $userId, string $fullName): void
    {
        $stmt = $this->pdo()->prepare('UPDATE users SET full_name = :name, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':name' => $fullName, ':id' => $userId]);
    }
}
