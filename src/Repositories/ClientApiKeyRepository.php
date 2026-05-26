<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Helpers\Encryption;
use PDO;
use Ramsey\Uuid\Uuid;

final class ClientApiKeyRepository
{
    private function pdo(): PDO
    {
        return Database::pdo();
    }

    /**
     * Liste les clés (avec preview masqué) — la valeur en clair n'est jamais exposée.
     *
     * @return array<string,array{has_key:bool,masked:?string}>
     */
    public function listForClient(string $clientId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT provider, api_key_encrypted FROM client_api_keys
              WHERE client_id = :client_id AND is_active = 1'
        );
        $stmt->execute([':client_id' => $clientId]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $masked = null;
            try {
                $clear = Encryption::decrypt($row['api_key_encrypted']);
                $masked = $this->mask($clear);
            } catch (\Throwable) {
                $masked = null;
            }
            $result[$row['provider']] = ['has_key' => true, 'masked' => $masked];
        }
        return $result;
    }

    public function get(string $clientId, string $provider): ?string
    {
        $stmt = $this->pdo()->prepare(
            'SELECT api_key_encrypted FROM client_api_keys
              WHERE client_id = :client_id AND provider = :provider AND is_active = 1
              LIMIT 1'
        );
        $stmt->execute([':client_id' => $clientId, ':provider' => $provider]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        try {
            return Encryption::decrypt($row['api_key_encrypted']);
        } catch (\Throwable) {
            return null;
        }
    }

    public function save(string $clientId, string $provider, string $apiKey): void
    {
        $encrypted = Encryption::encrypt($apiKey);
        // Note : placeholders nommés DISTINCTS car PDO emulate=false n'autorise pas la réutilisation.
        $stmt = $this->pdo()->prepare(
            'INSERT INTO client_api_keys (id, client_id, provider, api_key_encrypted, is_active)
             VALUES (:id, :client_id, :provider, :enc_insert, 1)
             ON DUPLICATE KEY UPDATE api_key_encrypted = :enc_update, is_active = 1, updated_at = NOW()'
        );
        $stmt->execute([
            ':id' => Uuid::uuid4()->toString(),
            ':client_id' => $clientId,
            ':provider' => $provider,
            ':enc_insert' => $encrypted,
            ':enc_update' => $encrypted,
        ]);
    }

    public function delete(string $clientId, string $provider): void
    {
        $stmt = $this->pdo()->prepare(
            'DELETE FROM client_api_keys WHERE client_id = :client_id AND provider = :provider'
        );
        $stmt->execute([':client_id' => $clientId, ':provider' => $provider]);
    }

    private function mask(string $value): string
    {
        $len = strlen($value);
        if ($len <= 8) {
            return str_repeat('*', max(0, $len));
        }
        return substr($value, 0, 4) . str_repeat('*', $len - 8) . substr($value, -4);
    }
}
