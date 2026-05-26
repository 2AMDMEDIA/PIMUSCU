-- ============================================================================
-- 012 — Ajout colonne presta_category_id à presta_products
-- Stocke l'id_category_default de PrestaShop pour permettre le filtrage par catégorie.
-- Idempotente.
-- ============================================================================

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'presta_products'
             AND COLUMN_NAME = 'presta_category_id');

SET @sql := IF(@c = 0,
    'ALTER TABLE `presta_products` ADD COLUMN `presta_category_id` INT UNSIGNED NULL DEFAULT NULL AFTER `active`, ADD INDEX `idx_presta_products_category` (`client_id`, `presta_category_id`)',
    'SELECT "presta_category_id already exists, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
