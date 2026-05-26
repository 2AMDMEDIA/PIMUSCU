-- ============================================================================
-- 002 — Ajout du prix d'achat (wholesale_price) sur les produits.
-- Idempotent : skip silencieusement si la colonne existe déjà
-- (cas des fresh installs où 001 contient déjà la colonne).
-- ============================================================================

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'presta_products'
      AND COLUMN_NAME = 'wholesale_price'
);

SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `presta_products` ADD COLUMN `wholesale_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER `price`',
    'SELECT "wholesale_price already exists, skipped" AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
