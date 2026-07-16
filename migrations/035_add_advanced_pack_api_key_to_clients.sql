-- ============================================================================
-- 035 — Ajout de `advanced_pack_api_key_encrypted` a `clients`.
-- Cle API du fichier api_advancedpack.php (fourni par le PIM, upload racine PS)
-- qui expose la liste des id_product marques comme pack Advanced Pack.
-- Stockee chiffree. NULL = non configuree (packs Advanced Pack non detectes,
-- seuls les packs natifs PS le seront).
-- Idempotente : skip si colonne deja presente.
-- ============================================================================

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'clients'
               AND COLUMN_NAME = 'advanced_pack_api_key_encrypted');
SET @sql := IF(@col = 0,
    'ALTER TABLE `clients` ADD COLUMN `advanced_pack_api_key_encrypted` VARCHAR(500) NULL DEFAULT NULL AFTER `aw_cpf_api_key_encrypted`',
    'SELECT "clients.advanced_pack_api_key_encrypted deja presente, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
