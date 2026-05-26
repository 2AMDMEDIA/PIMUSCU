-- ============================================================================
-- 016 — Renomme additional_description → aw_description_2 sur presta_categories
-- (alignement avec le nom du champ custom expose par le module aw_* cote Presta)
-- Idempotente : skip si les colonnes ont deja ete renommees.
-- ============================================================================

-- 1) additional_description -> aw_description_2
SET @old_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'presta_categories'
                      AND COLUMN_NAME = 'additional_description');
SET @sql := IF(@old_exists > 0,
    'ALTER TABLE `presta_categories` CHANGE `additional_description` `aw_description_2` LONGTEXT NULL DEFAULT NULL',
    'SELECT "additional_description deja renommee, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) optimized_additional_description -> optimized_aw_description_2
SET @old_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'presta_categories'
                      AND COLUMN_NAME = 'optimized_additional_description');
SET @sql := IF(@old_exists > 0,
    'ALTER TABLE `presta_categories` CHANGE `optimized_additional_description` `optimized_aw_description_2` LONGTEXT NULL DEFAULT NULL',
    'SELECT "optimized_additional_description deja renommee, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
