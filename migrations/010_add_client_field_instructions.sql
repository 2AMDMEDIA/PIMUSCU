-- ============================================================================
-- 010 — Table des instructions IA par champ et par client.
-- Permet de configurer dans Paramètres → Champs des règles spécifiques
-- (longueur, ton, contraintes) qui seront injectées dans le prompt système
-- à chaque génération IA.
--
-- Idempotente : skip si la table existe déjà.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `client_field_instructions` (
    `id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `entity_type` VARCHAR(40) NOT NULL,
    `field_name` VARCHAR(80) NOT NULL,
    `instructions` TEXT NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_field_instr_client_entity_field` (`client_id`, `entity_type`, `field_name`),
    KEY `idx_field_instr_client_entity` (`client_id`, `entity_type`),
    CONSTRAINT `fk_field_instr_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
