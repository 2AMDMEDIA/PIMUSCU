-- ============================================================================
-- 028 — Stockage des résultats d'étude de prix SerpApi par produit.
-- Chaque clic sur "Comparer les prix" crée une nouvelle ligne (historique).
-- Au chargement de la fiche produit, on affiche la plus récente.
-- Idempotente.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `product_price_analyses` (
    `id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `presta_product_id` INT UNSIGNED NOT NULL,
    `search_query` VARCHAR(500) NOT NULL,
    `current_price_ttc` DECIMAL(10, 2) NULL DEFAULT NULL,
    `avg_price_eur` DECIMAL(10, 2) NULL DEFAULT NULL,
    `min_price_eur` DECIMAL(10, 2) NULL DEFAULT NULL,
    `max_price_eur` DECIMAL(10, 2) NULL DEFAULT NULL,
    `median_price_eur` DECIMAL(10, 2) NULL DEFAULT NULL,
    `found_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `summary` TEXT NULL DEFAULT NULL,
    `results_json` LONGTEXT NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_price_analyses_product_created` (`client_id`, `presta_product_id`, `created_at` DESC),
    CONSTRAINT `fk_price_analyses_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
