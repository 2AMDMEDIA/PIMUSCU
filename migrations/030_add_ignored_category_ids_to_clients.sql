-- ============================================================================
-- 030 — Ajout de `ignored_category_ids` (JSON) a `clients`.
-- Liste d'ids de categories PrestaShop dont les produits doivent etre IGNORES
-- a la synchronisation (non importes dans le PIM). Choisi dans
-- Parametres -> PrestaShop. NULL = aucune categorie ignoree.
-- Idempotente : skip si colonne deja presente.
-- ============================================================================

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'clients'
               AND COLUMN_NAME = 'ignored_category_ids');
SET @sql := IF(@col = 0,
    'ALTER TABLE `clients` ADD COLUMN `ignored_category_ids` JSON NULL DEFAULT NULL AFTER `enabled_attribute_group_ids`',
    'SELECT "clients.ignored_category_ids deja presente, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
