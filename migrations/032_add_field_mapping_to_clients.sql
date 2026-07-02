-- ============================================================================
-- 032 — Ajout de `field_mapping` (JSON) a `clients`.
-- Table de correspondance champs Nutriweb (source) -> champs PrestaShop
-- (destination : produit natif / declinaison / champ custom aw_customproductfield).
-- Format : {"<source_key>": "<destination_spec>", ...}
--   ex : {"sku":"product.reference", "price_selling":"custom.price_nutriweb"}
-- Configuree dans Parametres -> Mapping. NULL = aucun mapping.
-- Idempotente : skip si colonne deja presente.
-- ============================================================================

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'clients'
               AND COLUMN_NAME = 'field_mapping');
SET @sql := IF(@col = 0,
    'ALTER TABLE `clients` ADD COLUMN `field_mapping` JSON NULL DEFAULT NULL AFTER `ignored_category_ids`',
    'SELECT "clients.field_mapping deja presente, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
