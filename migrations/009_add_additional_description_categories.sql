-- ============================================================================
-- 009 — Ajout des colonnes additional_description + optimized_additional_description
-- sur presta_categories. Idempotente.
-- ============================================================================

SET @c1 := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'presta_categories'
              AND COLUMN_NAME = 'additional_description');
SET @sql := IF(@c1 = 0,
    'ALTER TABLE `presta_categories` ADD COLUMN `additional_description` LONGTEXT NULL DEFAULT NULL AFTER `description`',
    'SELECT "additional_description already exists, skip." AS msg'
);
PREPARE s1 FROM @sql; EXECUTE s1; DEALLOCATE PREPARE s1;

SET @c2 := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'presta_categories'
              AND COLUMN_NAME = 'optimized_additional_description');
SET @sql := IF(@c2 = 0,
    'ALTER TABLE `presta_categories` ADD COLUMN `optimized_additional_description` LONGTEXT NULL DEFAULT NULL AFTER `optimized_description`',
    'SELECT "optimized_additional_description already exists, skip." AS msg'
);
PREPARE s2 FROM @sql; EXECUTE s2; DEALLOCATE PREPARE s2;
