-- ============================================================================
-- 020 — Table des images generees par IA (Kie.AI) attachees a un produit
--
-- Une ligne par generation. Statut pending->success/error, image_url valable
-- ~24h cote Kie.AI puis expire (rehoster via PrestaShop si besoin pérenne).
-- parent_generation_id : chaine les raffinements (modification d'image).
-- Idempotente.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `generated_images` (
    `id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `product_id` CHAR(36) NOT NULL,
    `presta_product_id` INT UNSIGNED NULL DEFAULT NULL,
    `prompt` TEXT NOT NULL,
    `input_urls` JSON NULL DEFAULT NULL,
    `model` VARCHAR(64) NOT NULL DEFAULT '',
    `task_id` VARCHAR(128) NULL DEFAULT NULL,
    `status` ENUM('pending', 'success', 'error') NOT NULL DEFAULT 'pending',
    `image_url` VARCHAR(500) NULL DEFAULT NULL,
    `error_message` TEXT NULL DEFAULT NULL,
    `parent_generation_id` CHAR(36) NULL DEFAULT NULL,
    `pushed_image_id` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_gen_images_product` (`client_id`, `product_id`, `created_at` DESC),
    KEY `idx_gen_images_task` (`task_id`),
    KEY `idx_gen_images_parent` (`parent_generation_id`),
    CONSTRAINT `fk_gen_images_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_gen_images_product` FOREIGN KEY (`product_id`) REFERENCES `presta_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
