-- ============================================================================
-- 033 — Ajout de `image_ids` (CSV) + `active_promos_json` (TEXT) a `presta_products`.
-- Evite les appels PrestaShop en direct a chaque ouverture de fiche produit :
--  - image_ids : liste des id_image de la galerie, en CSV (ex: "12,45,78")
--    Rempli lazily a la 1ere ouverture de la fiche puis conserve.
--    Invalide (NULL) quand une image est ajoutee (addImageToGallery).
--  - active_promos_json : liste des specific_prices actives du produit, JSON.
--    Peuple au sync produits (Produits -> Synchroniser).
-- Idempotente : skip si colonnes deja presentes.
-- ============================================================================

SET @c1 := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'presta_products'
              AND COLUMN_NAME = 'image_ids');
SET @sql := IF(@c1 = 0,
    'ALTER TABLE `presta_products` ADD COLUMN `image_ids` TEXT NULL DEFAULT NULL AFTER `image_url`',
    'SELECT "presta_products.image_ids deja presente, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c2 := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'presta_products'
              AND COLUMN_NAME = 'active_promos_json');
SET @sql := IF(@c2 = 0,
    'ALTER TABLE `presta_products` ADD COLUMN `active_promos_json` TEXT NULL DEFAULT NULL AFTER `image_ids`',
    'SELECT "presta_products.active_promos_json deja presente, skip." AS msg'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
