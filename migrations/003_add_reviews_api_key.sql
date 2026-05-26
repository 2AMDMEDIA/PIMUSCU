-- ============================================================================
-- 003 — Ajout de la clé API du module ws_productreviews sur les clients.
-- Idempotent : skip silencieusement si la colonne existe déjà
-- (cas des fresh installs où 001 contient déjà la colonne).
-- ============================================================================

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'clients'
      AND COLUMN_NAME = 'prestashop_reviews_api_key_encrypted'
);

SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `clients` ADD COLUMN `prestashop_reviews_api_key_encrypted` TEXT NULL DEFAULT NULL AFTER `prestashop_blog_api_key_encrypted`',
    'SELECT "prestashop_reviews_api_key_encrypted already exists, skipped" AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
