<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use Ramsey\Uuid\Uuid;

final class ClientEditorialRepository
{
    private function pdo(): PDO
    {
        return Database::pdo();
    }

    /**
     * @return array{
     *     media_name:string,
     *     industry_sector:string,
     *     editorial_line:string,
     *     target_audience:string,
     *     editorial_forbidden:string,
     *     image_prompt_instructions:string,
     * }
     */
    public function get(string $clientId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM client_editorial WHERE client_id = :client_id LIMIT 1');
        $stmt->execute([':client_id' => $clientId]);
        $row = $stmt->fetch();
        return [
            'media_name' => (string) ($row['media_name'] ?? ''),
            'industry_sector' => (string) ($row['industry_sector'] ?? ''),
            'editorial_line' => (string) ($row['editorial_line'] ?? ''),
            'target_audience' => (string) ($row['target_audience'] ?? ''),
            'editorial_forbidden' => (string) ($row['editorial_forbidden'] ?? ''),
            'image_prompt_instructions' => (string) ($row['image_prompt_instructions'] ?? ''),
        ];
    }

    public function save(string $clientId, array $data): void
    {
        $values = [
            'media' => $data['media_name'] ?? '',
            'sector' => $data['industry_sector'] ?? '',
            'line' => $data['editorial_line'] ?? '',
            'audience' => $data['target_audience'] ?? '',
            'forbidden' => $data['editorial_forbidden'] ?? '',
            'image' => $data['image_prompt_instructions'] ?? '',
        ];

        // PDO emulate=false n'autorise pas la réutilisation de placeholders : on duplique ins/upd.
        $stmt = $this->pdo()->prepare(
            'INSERT INTO client_editorial
                (id, client_id, media_name, industry_sector, editorial_line,
                 target_audience, editorial_forbidden, image_prompt_instructions)
             VALUES (:id, :client_id, :media_ins, :sector_ins, :line_ins, :audience_ins, :forbidden_ins, :image_ins)
             ON DUPLICATE KEY UPDATE
                media_name = :media_upd,
                industry_sector = :sector_upd,
                editorial_line = :line_upd,
                target_audience = :audience_upd,
                editorial_forbidden = :forbidden_upd,
                image_prompt_instructions = :image_upd,
                updated_at = NOW()'
        );
        $params = [
            ':id' => Uuid::uuid4()->toString(),
            ':client_id' => $clientId,
        ];
        foreach ($values as $k => $v) {
            $params[":{$k}_ins"] = $v;
            $params[":{$k}_upd"] = $v;
        }
        $stmt->execute($params);
    }
}
