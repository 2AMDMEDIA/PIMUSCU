-- ============================================================================
-- 034 — Ajout de `product_type` (VARCHAR) a `presta_products`.
-- Type PrestaShop : 'standard' / 'pack' / 'virtual'. Sur PS < 1.7, on tombe
-- back sur is_virtual/cache_is_pack lors du sync (deja mappe cote PHP).
-- NULL = non renseigne (sync a refaire).
-- Idempotente : skip si colonne deja presente.
-- ============================================================================

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'presta_products'
               AND COLUMN_NAME = 'product_type');
SET @sql := IF(@col = 0,
    'ALTER TABLE `presta_products` ADD COLUMN `product_type` VARCHAR(20) NULL DEFAULT NULL AFTER `active`,
        ADD INDEX `idx_presta_products_type` (`client_id`, `product_type`)',
    'SELECT "presta_products.product_type deja presente, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
