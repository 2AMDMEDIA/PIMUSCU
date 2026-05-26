<?php

declare(strict_types=1);

/**
 * Applique tous les fichiers SQL du dossier migrations/ dans l'ordre.
 *
 * Usage : php scripts/migrate.php
 *
 * Note : pas de tracking de migrations appliquées (seulement le MVP).
 *        Ré-exécuter ce script DROP-RECREATE les tables (DROP TABLE IF EXISTS).
 */

use App\Bootstrap;
use App\Database;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Ce script doit être exécuté en CLI.\n");
    exit(1);
}

$rootPath = dirname(__DIR__);
require $rootPath . '/src/Bootstrap.php';

Bootstrap::boot($rootPath);

$migrationsDir = $rootPath . '/migrations';
$files = glob($migrationsDir . '/*.sql');
sort($files);

if (empty($files)) {
    echo "Aucune migration à appliquer.\n";
    exit(0);
}

$pdo = Database::pdo();

foreach ($files as $file) {
    echo "▶ Application de " . basename($file) . "...\n";
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "Impossible de lire {$file}\n");
        exit(1);
    }
    try {
        $pdo->exec($sql);
        echo "  ✓ OK\n";
    } catch (PDOException $e) {
        fwrite(STDERR, "  ✗ ERREUR : " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "\nMigrations appliquées avec succès.\n";
