-- ============================================================================
-- 017 — Table des reglages Nutriweb par client (cle privee + 2 URLs API)
-- Idempotente : skip si la table existe deja.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `client_nutriweb_settings` (
    `id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `private_key_encrypted` TEXT NULL DEFAULT NULL,
    `catalogue_url` VARCHAR(500) NULL DEFAULT NULL,
    `product_info_url` VARCHAR(500) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_nutriweb_settings_client` (`client_id`),
    CONSTRAINT `fk_nutriweb_settings_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
