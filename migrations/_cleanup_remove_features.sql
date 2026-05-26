-- ============================================================================
-- Cleanup — Suppression des features Égéries / Kit média / Marketing / Cocon
-- À exécuter UNE FOIS via phpMyAdmin sur la DB locale `pim_musculation`.
-- Idempotent : toutes les opérations utilisent IF EXISTS.
--
-- Ce fichier commence par "_" pour ne PAS être listé/exécuté par MigrationRunner
-- (qui glob '*.sql' et trie alphabétiquement — le `_` reste mais c'est un cleanup
-- one-shot manuel, pas une migration versionnée).
-- ============================================================================

-- 1) Tables liées aux features supprimées
DROP TABLE IF EXISTS `client_personas`;
DROP TABLE IF EXISTS `generated_banners`;
DROP TABLE IF EXISTS `client_banner_formats`;

-- 2) Colonnes promo_* sur presta_products (migration 013)
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'presta_products'
                      AND COLUMN_NAME = 'promo_reduction_type');
SET @sql := IF(@col_exists > 0,
    'ALTER TABLE `presta_products`
        DROP INDEX `idx_presta_products_promo`,
        DROP COLUMN `promo_reduction_type`,
        DROP COLUMN `promo_reduction`,
        DROP COLUMN `promo_from`,
        DROP COLUMN `promo_to`',
    'SELECT "Colonnes promo_* deja absentes, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3) Nettoyage de schema_migrations (retire les entrées des fichiers supprimés)
DELETE FROM `schema_migrations` WHERE `name` IN (
    '004_add_banner_formats.sql',
    '005_add_generated_banners.sql',
    '006_add_input_urls_to_banners.sql',
    '007_add_client_personas.sql',
    '013_add_promo_columns_to_products.sql'
);

-- 4) Nettoyage du champ JSON modules sur clients (la clé 'cocon' n'existe plus)
UPDATE `clients`
   SET `enabled_modules` = JSON_REMOVE(`enabled_modules`, '$.cocon')
 WHERE JSON_CONTAINS_PATH(`enabled_modules`, 'one', '$.cocon') = 1;
