-- ============================================================================
-- 021 — Liste des id_attribute_group Presta actives par client
--
-- Si NULL : tous les groupes Presta sont proposes dans le form de creation
-- de combination (comportement par defaut).
-- Si tableau JSON : seuls ces groupes apparaissent (ex: [1, 3, 5]).
-- Pilote par Settings -> Attributs.
-- Idempotente.
-- ============================================================================

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'clients'
               AND COLUMN_NAME = 'enabled_attribute_group_ids');
SET @sql := IF(@col = 0,
    'ALTER TABLE `clients` ADD COLUMN `enabled_attribute_group_ids` JSON NULL DEFAULT NULL AFTER `supplier_id`',
    'SELECT "clients.enabled_attribute_group_ids already exists, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
