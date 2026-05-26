-- ============================================================================
-- 014 — Ajout colonnes meta_keywords + optimized_meta_keywords sur presta_products
-- Idempotente.
-- ============================================================================

SET @c1 := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'presta_products'
              AND COLUMN_NAME = 'meta_keywords');
SET @sql := IF(@c1 = 0,
    'ALTER TABLE `presta_products` ADD COLUMN `meta_keywords` VARCHAR(1000) NULL DEFAULT NULL AFTER `meta_description`',
    'SELECT "meta_keywords already exists, skip." AS msg'
);
PREPARE s1 FROM @sql; EXECUTE s1; DEALLOCATE PREPARE s1;

SET @c2 := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'presta_products'
              AND COLUMN_NAME = 'optimized_meta_keywords');
SET @sql := IF(@c2 = 0,
    'ALTER TABLE `presta_products` ADD COLUMN `optimized_meta_keywords` VARCHAR(1000) NULL DEFAULT NULL AFTER `optimized_meta_description`',
    'SELECT "optimized_meta_keywords already exists, skip." AS msg'
);
PREPARE s2 FROM @sql; EXECUTE s2; DEALLOCATE PREPARE s2;
