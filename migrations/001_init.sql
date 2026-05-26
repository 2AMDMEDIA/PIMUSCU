-- ============================================================================
-- pim_musculation — Schéma MVP (001)
-- À exécuter sur une base MySQL 5.7+ ou MariaDB 10.3+ avec charset utf8mb4.
-- ============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- AUTH & USERS
-- ============================================================================

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id` CHAR(36) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NULL DEFAULT NULL,
    `full_name` VARCHAR(255) NOT NULL DEFAULT '',
    `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0,
    `needs_password_setup` TINYINT(1) NOT NULL DEFAULT 0,
    `last_login_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_users_email` (`email`),
    KEY `idx_users_super_admin` (`is_super_admin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `password_tokens`;
CREATE TABLE `password_tokens` (
    `id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `token` VARCHAR(64) NOT NULL,
    `type` ENUM('reset', 'invitation') NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_password_tokens_token` (`token`),
    KEY `idx_password_tokens_user_type` (`user_id`, `type`),
    KEY `idx_password_tokens_expires` (`expires_at`),
    CONSTRAINT `fk_password_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CLIENTS & MULTI-TENANT
-- ============================================================================

DROP TABLE IF EXISTS `clients`;
CREATE TABLE `clients` (
    `id` CHAR(36) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `prestashop_url` VARCHAR(500) NOT NULL DEFAULT '',
    `prestashop_api_key_encrypted` TEXT NULL DEFAULT NULL,
    `prestashop_blog_api_key_encrypted` TEXT NULL DEFAULT NULL,
    `prestashop_reviews_api_key_encrypted` TEXT NULL DEFAULT NULL,
    `logo_url` VARCHAR(500) NULL DEFAULT NULL,
    `footer_name` VARCHAR(255) NULL DEFAULT NULL,
    `token_monthly_limit` INT NOT NULL DEFAULT 0,
    `token_alert_threshold` TINYINT UNSIGNED NOT NULL DEFAULT 80,
    `enabled_modules` JSON NULL DEFAULT NULL,
    `custom_fields_categories` TEXT NULL DEFAULT NULL,
    `custom_fields_products` TEXT NULL DEFAULT NULL,
    `custom_fields_prompts` JSON NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_clients_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_clients`;
CREATE TABLE `user_clients` (
    `id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_clients` (`user_id`, `client_id`),
    KEY `idx_user_clients_client` (`client_id`),
    CONSTRAINT `fk_user_clients_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_user_clients_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `client_editorial`;
CREATE TABLE `client_editorial` (
    `id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `media_name` VARCHAR(255) NOT NULL DEFAULT '',
    `industry_sector` VARCHAR(255) NOT NULL DEFAULT '',
    `editorial_line` VARCHAR(500) NOT NULL DEFAULT '',
    `target_audience` TEXT NULL DEFAULT NULL,
    `editorial_forbidden` TEXT NULL DEFAULT NULL,
    `image_prompt_instructions` TEXT NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_client_editorial_client` (`client_id`),
    CONSTRAINT `fk_client_editorial_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `client_api_keys`;
CREATE TABLE `client_api_keys` (
    `id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `provider` ENUM('openrouter', 'anthropic', 'openai', 'gemini', 'mistral', 'kie') NOT NULL,
    `api_key_encrypted` TEXT NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_client_api_keys` (`client_id`, `provider`),
    CONSTRAINT `fk_client_api_keys_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `client_ai_preferences`;
CREATE TABLE `client_ai_preferences` (
    `id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `default_text_provider` VARCHAR(32) NOT NULL DEFAULT 'openrouter',
    `default_image_provider` VARCHAR(32) NOT NULL DEFAULT 'kie',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_client_ai_preferences_client` (`client_id`),
    CONSTRAINT `fk_client_ai_preferences_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- PRESTASHOP CACHE
-- ============================================================================

DROP TABLE IF EXISTS `presta_categories`;
CREATE TABLE `presta_categories` (
    `id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `presta_id` INT UNSIGNED NOT NULL,
    `parent_id` INT UNSIGNED NULL DEFAULT NULL,
    `name` VARCHAR(500) NOT NULL DEFAULT '',
    `description` LONGTEXT NULL DEFAULT NULL,
    `meta_title` VARCHAR(255) NULL DEFAULT NULL,
    `meta_description` VARCHAR(500) NULL DEFAULT NULL,
    `link_rewrite` VARCHAR(255) NULL DEFAULT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `products_count` INT NOT NULL DEFAULT 0,
    `optimized_description` LONGTEXT NULL DEFAULT NULL,
    `optimized_meta_title` VARCHAR(255) NULL DEFAULT NULL,
    `optimized_meta_description` VARCHAR(500) NULL DEFAULT NULL,
    `optimized_at` DATETIME NULL DEFAULT NULL,
    `custom_fields` JSON NULL DEFAULT NULL,
    `optimized_custom_fields` JSON NULL DEFAULT NULL,
    `synced_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_presta_categories` (`client_id`, `presta_id`),
    KEY `idx_presta_categories_parent` (`client_id`, `parent_id`),
    KEY `idx_presta_categories_active` (`client_id`, `active`),
    CONSTRAINT `fk_presta_categories_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `presta_products`;
CREATE TABLE `presta_products` (
    `id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `presta_id` INT UNSIGNED NOT NULL,
    `reference` VARCHAR(64) NULL DEFAULT NULL,
    `name` VARCHAR(500) NOT NULL DEFAULT '',
    `price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `wholesale_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `description_short` TEXT NULL DEFAULT NULL,
    `description` LONGTEXT NULL DEFAULT NULL,
    `meta_title` VARCHAR(255) NULL DEFAULT NULL,
    `meta_description` VARCHAR(500) NULL DEFAULT NULL,
    `has_cms_content` TINYINT(1) NOT NULL DEFAULT 0,
    `has_description` TINYINT(1) NOT NULL DEFAULT 0,
    `image_url` VARCHAR(500) NULL DEFAULT NULL,
    `link_rewrite` VARCHAR(255) NULL DEFAULT NULL,
    `optimized_description_short` TEXT NULL DEFAULT NULL,
    `optimized_description` LONGTEXT NULL DEFAULT NULL,
    `optimized_meta_title` VARCHAR(255) NULL DEFAULT NULL,
    `optimized_meta_description` VARCHAR(500) NULL DEFAULT NULL,
    `optimized_at` DATETIME NULL DEFAULT NULL,
    `custom_fields` JSON NULL DEFAULT NULL,
    `optimized_custom_fields` JSON NULL DEFAULT NULL,
    `synced_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_presta_products` (`client_id`, `presta_id`),
    KEY `idx_presta_products_reference` (`client_id`, `reference`),
    KEY `idx_presta_products_has_desc` (`client_id`, `has_description`),
    KEY `idx_presta_products_active` (`client_id`, `active`),
    CONSTRAINT `fk_presta_products_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `presta_field_schemas`;
CREATE TABLE `presta_field_schemas` (
    `id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `resource` ENUM('categories', 'products') NOT NULL,
    `field_name` VARCHAR(128) NOT NULL,
    `is_language` TINYINT(1) NOT NULL DEFAULT 0,
    `ai_prompt` VARCHAR(200) NULL DEFAULT NULL,
    `is_selected` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_presta_field_schemas` (`client_id`, `resource`, `field_name`),
    CONSTRAINT `fk_presta_field_schemas_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SYSTÈME (jobs, logs, alertes)
-- ============================================================================

DROP TABLE IF EXISTS `sync_jobs`;
CREATE TABLE `sync_jobs` (
    `id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `type` ENUM('sync_categories', 'sync_products', 'optimize_category', 'optimize_product', 'generate_image') NOT NULL,
    `entity_id` VARCHAR(64) NULL DEFAULT NULL,
    `status` ENUM('queued', 'running', 'completed', 'error') NOT NULL DEFAULT 'queued',
    `total` INT NOT NULL DEFAULT 0,
    `synced` INT NOT NULL DEFAULT 0,
    `percent` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `message` VARCHAR(500) NULL DEFAULT NULL,
    `error_message` TEXT NULL DEFAULT NULL,
    `result` JSON NULL DEFAULT NULL,
    `started_at` DATETIME NULL DEFAULT NULL,
    `finished_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sync_jobs_client_status` (`client_id`, `status`),
    KEY `idx_sync_jobs_status_created` (`status`, `created_at`),
    CONSTRAINT `fk_sync_jobs_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `ai_usage_logs`;
CREATE TABLE `ai_usage_logs` (
    `id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `provider` VARCHAR(32) NOT NULL,
    `model` VARCHAR(128) NULL DEFAULT NULL,
    `prompt_tokens` INT NOT NULL DEFAULT 0,
    `completion_tokens` INT NOT NULL DEFAULT 0,
    `total_tokens` INT NOT NULL DEFAULT 0,
    `cost_eur` DECIMAL(12, 6) NOT NULL DEFAULT 0,
    `entity_type` VARCHAR(64) NULL DEFAULT NULL,
    `entity_id` VARCHAR(64) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ai_usage_logs_client_created` (`client_id`, `created_at`),
    KEY `idx_ai_usage_logs_provider` (`provider`),
    CONSTRAINT `fk_ai_usage_logs_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE `activity_logs` (
    `id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NULL DEFAULT NULL,
    `user_id` CHAR(36) NULL DEFAULT NULL,
    `action` VARCHAR(64) NOT NULL,
    `entity_type` VARCHAR(64) NULL DEFAULT NULL,
    `entity_id` VARCHAR(64) NULL DEFAULT NULL,
    `details` JSON NULL DEFAULT NULL,
    `status` ENUM('success', 'error', 'warning') NOT NULL DEFAULT 'success',
    `error_message` TEXT NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_activity_logs_client_created` (`client_id`, `created_at`),
    KEY `idx_activity_logs_action` (`action`),
    CONSTRAINT `fk_activity_logs_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_activity_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `admin_alerts`;
CREATE TABLE `admin_alerts` (
    `id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `type` VARCHAR(64) NOT NULL,
    `message` TEXT NOT NULL,
    `read_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_admin_alerts_read_created` (`read_at`, `created_at`),
    CONSTRAINT `fk_admin_alerts_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
