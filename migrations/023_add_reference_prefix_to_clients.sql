-- ============================================================================
-- 023 — Prefixe de reference produit par client
--
-- L'user saisit un prefixe dans Settings -> PrestaShop (ex 'MUSCU-').
-- A la creation produit/decli depuis /catalogue/create, ce prefixe est colle
-- devant la reference Presta (ex SKU '1004' -> reference 'MUSCU-1004').
-- supplier_reference reste = sku brut (pas prefixe, c'est l'id du fournisseur
-- chez Nutriweb).
-- Idempotente.
-- ============================================================================

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'clients'
               AND COLUMN_NAME = 'reference_prefix');
SET @sql := IF(@col = 0,
    'ALTER TABLE `clients` ADD COLUMN `reference_prefix` VARCHAR(20) NULL DEFAULT NULL AFTER `supplier_id`',
    'SELECT "clients.reference_prefix already exists, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
