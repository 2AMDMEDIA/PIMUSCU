-- ============================================================================
-- 018 — ID fournisseur par client + supplier_reference par produit
--
-- Le client renseigne son id_supplier PrestaShop dans Parametres -> PrestaShop.
-- Au sync produits, on recupere /api/product_suppliers?filter[id_supplier]=X
-- et on stocke product_supplier_reference dans presta_products.
-- Idempotente.
-- ============================================================================

-- clients.supplier_id (INT NULL)
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'clients'
               AND COLUMN_NAME = 'supplier_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE `clients` ADD COLUMN `supplier_id` INT UNSIGNED NULL DEFAULT NULL AFTER `prestashop_reviews_api_key_encrypted`',
    'SELECT "clients.supplier_id already exists, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- presta_products.supplier_reference (VARCHAR NULL)
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'presta_products'
               AND COLUMN_NAME = 'supplier_reference');
SET @sql := IF(@col = 0,
    'ALTER TABLE `presta_products` ADD COLUMN `supplier_reference` VARCHAR(120) NULL DEFAULT NULL AFTER `reference`',
    'SELECT "presta_products.supplier_reference already exists, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
