<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Historique des analyses de prix concurrents (SerpApi Google Shopping)
 * par produit. Chaque appel "Comparer les prix" crée un row.
 */
final class ProductPriceAnalysisRepository
{
    private function pdo(): PDO
    {
        return Database::pdo();
    }

    /**
     * @param list<array<string,mixed>> $results
     */
    public function create(
        string $clientId,
        int $prestaProductId,
        string $searchQuery,
        ?float $currentPriceTTC,
        ?float $avg,
        ?float $min,
        ?float $max,
        ?float $median,
        int $foundCount,
        string $summary,
        array $results,
    ): string {
        $id = Uuid::uuid4()->toString();
        $stmt = $this->pdo()->prepare(
            'INSERT INTO product_price_analyses
                (id, client_id, presta_product_id, search_query, current_price_ttc,
                 avg_price_eur, min_price_eur, max_price_eur, median_price_eur,
                 found_count, summary, results_json)
             VALUES
                (:id, :client_id, :presta_product_id, :search_query, :current_price,
                 :avg, :min, :max, :median,
                 :found, :summary, :results)'
        );
        $stmt->execute([
            ':id' => $id,
            ':client_id' => $clientId,
            ':presta_product_id' => $prestaProductId,
            ':search_query' => mb_substr($searchQuery, 0, 500),
            ':current_price' => $currentPriceTTC,
            ':avg' => $avg,
            ':min' => $min,
            ':max' => $max,
            ':median' => $median,
            ':found' => $foundCount,
            ':summary' => $summary,
            ':results' => json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return $id;
    }

    /**
     * Récupère la dernière analyse pour ce produit. Renvoie null si aucune.
     *
     * @return array{
     *   id:string, search_query:string,
     *   current_price_ttc:?float, avg_price_eur:?float, min_price_eur:?float,
     *   max_price_eur:?float, median_price_eur:?float,
     *   found_count:int, summary:string,
     *   results:list<array<string,mixed>>,
     *   created_at:string,
     * }|null
     */
    public function findLatest(string $clientId, int $prestaProductId): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM product_price_analyses
              WHERE client_id = :client_id AND presta_product_id = :presta_product_id
              ORDER BY created_at DESC
              LIMIT 1'
        );
        $stmt->execute([
            ':client_id' => $clientId,
            ':presta_product_id' => $prestaProductId,
        ]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $results = [];
        if (!empty($row['results_json'])) {
            $decoded = json_decode((string) $row['results_json'], true);
            if (is_array($decoded)) $results = $decoded;
        }

        return [
            'id' => (string) $row['id'],
            'search_query' => (string) $row['search_query'],
            'current_price_ttc' => $row['current_price_ttc'] !== null ? (float) $row['current_price_ttc'] : null,
            'avg_price_eur' => $row['avg_price_eur'] !== null ? (float) $row['avg_price_eur'] : null,
            'min_price_eur' => $row['min_price_eur'] !== null ? (float) $row['min_price_eur'] : null,
            'max_price_eur' => $row['max_price_eur'] !== null ? (float) $row['max_price_eur'] : null,
            'median_price_eur' => $row['median_price_eur'] !== null ? (float) $row['median_price_eur'] : null,
            'found_count' => (int) $row['found_count'],
            'summary' => (string) ($row['summary'] ?? ''),
            'results' => $results,
            'created_at' => (string) $row['created_at'],
        ];
    }
}
