-- ============================================================================
-- 019 — Table des combinaisons (declinaisons) PrestaShop par produit
--
-- Une ligne par id_product_attribute (taille/saveur/couleur d'un produit).
-- Synchronisee depuis /api/combinations ; labels d'attributs resolus via
-- /api/product_option_values + /api/product_options.
-- Idempotente.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `presta_product_combinations` (
    `id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `presta_product_id` INT UNSIGNED NOT NULL,
    `presta_combination_id` INT UNSIGNED NOT NULL,
    `reference` VARCHAR(120) NULL DEFAULT NULL,
    `barcode` VARCHAR(40) NULL DEFAULT NULL,
    `supplier_reference` VARCHAR(120) NULL DEFAULT NULL,
    `attributes_label` VARCHAR(255) NULL DEFAULT NULL,
    `synced_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_combinations_client_combid` (`client_id`, `presta_combination_id`),
    KEY `idx_combinations_lookup` (`client_id`, `presta_product_id`),
    CONSTRAINT `fk_combinations_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
