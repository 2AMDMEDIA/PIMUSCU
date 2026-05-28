-- ============================================================================
-- 027 — Ajout de `manufacturer_name` (marque) a `presta_products`
-- Renseigne au sync /produits depuis le champ manufacturer_name expose par
-- le Webservice PrestaShop. Sert au Controle (colonne Marque + filtre marque)
-- et peut servir ailleurs.
-- Idempotente : skip si colonne deja presente.
-- ============================================================================

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'presta_products'
               AND COLUMN_NAME = 'manufacturer_name');
SET @sql := IF(@col = 0,
    'ALTER TABLE `presta_products` ADD COLUMN `manufacturer_name` VARCHAR(255) NULL DEFAULT NULL AFTER `name`',
    'SELECT "presta_products.manufacturer_name deja presente, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
