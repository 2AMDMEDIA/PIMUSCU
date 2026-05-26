-- ============================================================================
-- 011 — Ajout colonne optimized_name à presta_categories.
-- Permet d'optimiser et de pousser le nom d'une catégorie sur PrestaShop.
-- (la colonne `name` actuelle est lue lors du sync depuis Presta.)
-- Idempotente.
-- ============================================================================

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'presta_categories'
             AND COLUMN_NAME = 'optimized_name');

SET @sql := IF(@c = 0,
    'ALTER TABLE `presta_categories` ADD COLUMN `optimized_name` VARCHAR(500) NULL DEFAULT NULL AFTER `name`',
    'SELECT "optimized_name already exists, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
