<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Réglages Nutriweb par client : clé privée (chiffrée) + URLs API.
 */
final class ClientNutriwebSettingsRepository
{
    private function pdo(): PDO
    {
        return Database::pdo();
    }

    /**
     * @return array{
     *     private_key_encrypted:?string,
     *     catalogue_url:string,
     *     product_info_url:string,
     * }
     */
    public function get(string $clientId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT private_key_encrypted, catalogue_url, product_info_url
               FROM client_nutriweb_settings
              WHERE client_id = :client_id
              LIMIT 1'
        );
        $stmt->execute([':client_id' => $clientId]);
        $row = $stmt->fetch();
        return [
            'private_key_encrypted' => $row['private_key_encrypted'] ?? null,
            'catalogue_url' => (string) ($row['catalogue_url'] ?? ''),
            'product_info_url' => (string) ($row['product_info_url'] ?? ''),
        ];
    }

    /**
     * Sauvegarde les 2 URLs et, optionnellement, la clé privée chiffrée.
     * Si $privateKeyEncrypted est null, on ne touche pas à la valeur existante.
     */
    public function save(
        string $clientId,
        ?string $privateKeyEncrypted,
        string $catalogueUrl,
        string $productInfoUrl,
    ): void {
        // 2 chemins : avec ou sans mise à jour de la clé chiffrée (sinon on ne l'écrase pas).
        if ($privateKeyEncrypted !== null) {
            $sql = 'INSERT INTO client_nutriweb_settings
                        (id, client_id, private_key_encrypted, catalogue_url, product_info_url)
                    VALUES
                        (:id, :client_id, :pk_ins, :cat_ins, :prod_ins)
                    ON DUPLICATE KEY UPDATE
                        private_key_encrypted = :pk_upd,
                        catalogue_url = :cat_upd,
                        product_info_url = :prod_upd,
                        updated_at = NOW()';
            $params = [
                ':id' => Uuid::uuid4()->toString(),
                ':client_id' => $clientId,
                ':pk_ins' => $privateKeyEncrypted,
                ':pk_upd' => $privateKeyEncrypted,
                ':cat_ins' => $catalogueUrl,
                ':cat_upd' => $catalogueUrl,
                ':prod_ins' => $productInfoUrl,
                ':prod_upd' => $productInfoUrl,
            ];
        } else {
            $sql = 'INSERT INTO client_nutriweb_settings
                        (id, client_id, catalogue_url, product_info_url)
                    VALUES
                        (:id, :client_id, :cat_ins, :prod_ins)
                    ON DUPLICATE KEY UPDATE
                        catalogue_url = :cat_upd,
                        product_info_url = :prod_upd,
                        updated_at = NOW()';
            $params = [
                ':id' => Uuid::uuid4()->toString(),
                ':client_id' => $clientId,
                ':cat_ins' => $catalogueUrl,
                ':cat_upd' => $catalogueUrl,
                ':prod_ins' => $productInfoUrl,
                ':prod_upd' => $productInfoUrl,
            ];
        }

        $this->pdo()->prepare($sql)->execute($params);
    }
}
