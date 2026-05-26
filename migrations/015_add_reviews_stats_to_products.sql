-- ============================================================================
-- 015 — Ajout colonnes reviews_count + reviews_avg sur presta_products.
-- Stocke les stats agrégées du module ws_productreviews pour éviter un appel HTTP
-- à chaque chargement de la liste produits. Mis à jour au sync produits.
-- Idempotente.
-- ============================================================================

SET @c1 := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'presta_products'
              AND COLUMN_NAME = 'reviews_count');
SET @sql := IF(@c1 = 0,
    'ALTER TABLE `presta_products`
        ADD COLUMN `reviews_count` INT UNSIGNED NULL DEFAULT NULL AFTER `meta_keywords`,
        ADD COLUMN `reviews_avg` DECIMAL(3, 2) NULL DEFAULT NULL AFTER `reviews_count`,
        ADD COLUMN `reviews_synced_at` DATETIME NULL DEFAULT NULL AFTER `reviews_avg`,
        ADD INDEX `idx_presta_products_reviews_count` (`client_id`, `reviews_count`)',
    'SELECT "reviews columns already exist, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
