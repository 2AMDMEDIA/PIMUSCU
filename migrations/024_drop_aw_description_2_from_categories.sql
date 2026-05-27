-- ============================================================================
-- 024 — Suppression des colonnes aw_description_2 / optimized_aw_description_2
-- Pimuscu (Musculation.com) n'a pas le champ custom aw_description_2 de Fitadium
-- (qui venait d'un module aw_*). On retire la feature partout.
-- Idempotente : skip si colonnes deja absentes.
-- ============================================================================

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'presta_categories'
               AND COLUMN_NAME = 'aw_description_2');
SET @sql := IF(@col > 0,
    'ALTER TABLE `presta_categories` DROP COLUMN `aw_description_2`',
    'SELECT "presta_categories.aw_description_2 deja absente, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'presta_categories'
               AND COLUMN_NAME = 'optimized_aw_description_2');
SET @sql := IF(@col > 0,
    'ALTER TABLE `presta_categories` DROP COLUMN `optimized_aw_description_2`',
    'SELECT "presta_categories.optimized_aw_description_2 deja absente, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
