-- ============================================================================
-- 031 — Ajout de `aw_cpf_api_key_encrypted` (VARCHAR) a `clients`.
-- Cle API du module custom Musculation.com aw_customproductfield
-- (endpoint /modules/aw_customproductfield/api.php).
-- Stockee chiffree, comme les autres cles API. NULL = non configuree.
-- Idempotente : skip si colonne deja presente.
-- ============================================================================

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'clients'
               AND COLUMN_NAME = 'aw_cpf_api_key_encrypted');
SET @sql := IF(@col = 0,
    'ALTER TABLE `clients` ADD COLUMN `aw_cpf_api_key_encrypted` VARCHAR(500) NULL DEFAULT NULL AFTER `prestashop_reviews_api_key_encrypted`',
    'SELECT "clients.aw_cpf_api_key_encrypted deja presente, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
