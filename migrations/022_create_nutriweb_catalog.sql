-- ============================================================================
-- 022 — Table de cache du catalogue Nutriweb par client
--
-- Synchronisee manuellement via le bouton 'Synchroniser' sur /catalogue.
-- Stocke les donnees du feed + le matching live vers presta_products
-- et presta_product_combinations (via supplier_reference = sku).
-- Idempotente.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `nutriweb_catalog` (
    `id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `sku` VARCHAR(60) NOT NULL,
    `name` VARCHAR(255) NULL DEFAULT NULL,
    `brand` VARCHAR(120) NULL DEFAULT NULL,
    `barcode` VARCHAR(40) NULL DEFAULT NULL,
    `size` VARCHAR(120) NULL DEFAULT NULL,
    `size_rank` INT UNSIGNED NULL DEFAULT NULL,
    `color` VARCHAR(120) NULL DEFAULT NULL,
    `flavor` VARCHAR(120) NULL DEFAULT NULL,
    `permalink` VARCHAR(255) NULL DEFAULT NULL,
    `image_url` VARCHAR(500) NULL DEFAULT NULL,
    `price_base` DECIMAL(10, 4) NULL DEFAULT NULL,
    `price_selling` DECIMAL(10, 4) NULL DEFAULT NULL,
    `price_retail` DECIMAL(10, 4) NULL DEFAULT NULL,
    `purchase_price` DECIMAL(10, 4) NULL DEFAULT NULL,
    `presta_product_id` INT UNSIGNED NULL DEFAULT NULL,
    `presta_combination_id` INT UNSIGNED NULL DEFAULT NULL,
    `last_synced_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_nw_catalog_client_sku` (`client_id`, `sku`),
    KEY `idx_nw_catalog_product` (`client_id`, `presta_product_id`),
    KEY `idx_nw_catalog_combination` (`client_id`, `presta_combination_id`),
    KEY `idx_nw_catalog_synced` (`client_id`, `last_synced_at`),
    CONSTRAINT `fk_nw_catalog_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
