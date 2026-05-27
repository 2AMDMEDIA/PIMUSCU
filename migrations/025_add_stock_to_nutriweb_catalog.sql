-- ============================================================================
-- 025 — Ajout de la colonne `stock` a `nutriweb_catalog`
-- Le flux Nutriweb expose un stock par SKU (par variant).
-- On le stocke pour l'afficher dans la table /catalogue.
-- Idempotente : skip si colonne deja presente.
-- ============================================================================

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'nutriweb_catalog'
               AND COLUMN_NAME = 'stock');
SET @sql := IF(@col = 0,
    'ALTER TABLE `nutriweb_catalog` ADD COLUMN `stock` INT NULL DEFAULT NULL AFTER `flavor`',
    'SELECT "nutriweb_catalog.stock deja presente, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
