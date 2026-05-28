-- ============================================================================
-- 029 — Ajout des colonnes promo_* à presta_products (promo flash).
-- Permet d'afficher le prix barré + remise sur la miniature produit.
-- Peuplé au sync depuis /api/specific_prices, filtré sur dates actives.
-- Idempotente.
-- ============================================================================

SET @c1 := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'presta_products'
              AND COLUMN_NAME = 'promo_reduction_type');
SET @sql := IF(@c1 = 0,
    'ALTER TABLE `presta_products`
        ADD COLUMN `promo_reduction_type` ENUM("percentage","amount") NULL DEFAULT NULL AFTER `presta_category_id`,
        ADD COLUMN `promo_reduction` DECIMAL(10, 6) NULL DEFAULT NULL AFTER `promo_reduction_type`,
        ADD COLUMN `promo_from` DATETIME NULL DEFAULT NULL AFTER `promo_reduction`,
        ADD COLUMN `promo_to` DATETIME NULL DEFAULT NULL AFTER `promo_from`,
        ADD INDEX `idx_presta_products_promo` (`client_id`, `promo_reduction_type`)',
    'SELECT "promo columns already exist, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
