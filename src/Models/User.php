<?php

declare(strict_types=1);

namespace App\Models;

final class User
{
    public function __construct(
        public string $id,
        public string $email,
        public ?string $passwordHash,
        public string $fullName,
        public bool $isSuperAdmin,
        public bool $needsPasswordSetup,
        public ?string $lastLoginAt,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            email: $row['email'],
            passwordHash: $row['password_hash'],
            fullName: $row['full_name'] ?? '',
            isSuperAdmin: (bool) $row['is_super_admin'],
            needsPasswordSetup: (bool) $row['needs_password_setup'],
            lastLoginAt: $row['last_login_at'],
            createdAt: $row['created_at'],
            updatedAt: $row['updated_at'],
        );
    }

    public function verifyPassword(string $plain): bool
    {
        if ($this->passwordHash === null || $this->passwordHash === '') {
            return false;
        }
        return password_verify($plain, $this->passwordHash);
    }

    public function displayName(): string
    {
        return $this->fullName !== '' ? $this->fullName : $this->email;
    }
}
