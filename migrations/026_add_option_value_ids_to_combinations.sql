-- ============================================================================
-- 026 — Ajout de `option_value_ids` a `presta_product_combinations`
-- Stocke les id_product_option_value (= id_attribute) qui composent la
-- declinaison, en CSV (ex: "7,4"). Renseigne au sync /produits.
-- Sert au Controle (onglet 'Declinaisons a 2+ attributs') pour afficher les
-- id des valeurs d'attributs.
-- Idempotente : skip si colonne deja presente.
-- ============================================================================

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'presta_product_combinations'
               AND COLUMN_NAME = 'option_value_ids');
SET @sql := IF(@col = 0,
    'ALTER TABLE `presta_product_combinations` ADD COLUMN `option_value_ids` VARCHAR(255) NULL DEFAULT NULL AFTER `attributes_label`',
    'SELECT "presta_product_combinations.option_value_ids deja presente, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
