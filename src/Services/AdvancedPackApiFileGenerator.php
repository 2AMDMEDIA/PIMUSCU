<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Génère le contenu du fichier api_advancedpack.php à uploader sur le shop.
 *
 * Le fichier expose la liste des id_product marqués comme packs via le
 * module Advanced Pack (202 ecommerce). Auto-detection de la table pour
 * couvrir les differentes versions du module.
 */
final class AdvancedPackApiFileGenerator
{
    public static function generate(string $apiKey): string
    {
        $template = self::template();
        return str_replace('__API_KEY__', addslashes($apiKey), $template);
    }

    private static function template(): string
    {
        return <<<'ADVPACK_TEMPLATE_END'
<?php
/**
 * API Advanced Pack — expose la liste des id_product qui sont des packs
 * (module Advanced Pack de 202 ecommerce).
 *
 * Fichier généré par PIM Musculation. À placer à la RACINE de votre PrestaShop.
 * Sécurité : clé API partagée obligatoire.
 *
 * Usage :
 *   GET /api_advancedpack.php?key=<KEY>
 *   ou header : X-API-Key: <KEY>
 *
 *   ?ping=1  -> ping simple (test connexion)
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

function ap_fail($message, $extra = [], $code = 500) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(array_merge(['success' => false, 'error' => $message], $extra));
    exit;
}

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(function (\Throwable $e) {
    ap_fail('PHP Exception', [
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ]);
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        ap_fail('PHP Fatal', [
            'message' => $err['message'],
            'file' => basename($err['file']),
            'line' => $err['line'],
        ]);
    }
});

define('API_KEY', '__API_KEY__');
define('AP_VERSION', '2026-07-15');

// Ping (pas d'auth requise, pour tester la connectivite)
if (isset($_GET['ping'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['pong' => true, 'version' => AP_VERSION, 'php' => PHP_VERSION]);
    exit;
}

// Auth
$providedKey = $_GET['key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!is_string($providedKey) || !hash_equals(API_KEY, $providedKey)) {
    ap_fail('Invalid or missing API key', [], 401);
}

// Chargement PrestaShop
$configPath = __DIR__ . '/config/config.inc.php';
if (!file_exists($configPath)) {
    ap_fail('config/config.inc.php introuvable — fichier pas a la racine PS ?');
}
require_once $configPath;

if (!class_exists('Db')) {
    ap_fail('Class Db introuvable apres inclusion config PS');
}

$db = Db::getInstance();
$prefix = _DB_PREFIX_;

// Scan dynamique : on cherche TOUTES les tables PS dont le nom contient 'pack'
// ou 'advanced', puis pour chacune on liste les colonnes. Ca permet de trouver
// la vraie table sans avoir a deviner le nom.
$scannedTables = [];
$patterns = ['%pack%', '%advanced%', '%bundle%'];
foreach ($patterns as $pat) {
    $rows = $db->executeS("SHOW TABLES LIKE '" . pSQL($prefix . $pat) . "'");
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $tableName = reset($r);
            if (!in_array($tableName, $scannedTables, true)) {
                $scannedTables[] = $tableName;
            }
        }
    }
}
sort($scannedTables);

// Pour chaque table trouvee, liste les colonnes qui contiennent 'product' ou 'pack'
// (candidates a etre l'id du produit qui est un pack).
$tableDetails = [];
foreach ($scannedTables as $tableName) {
    $cols = $db->executeS('SHOW COLUMNS FROM `' . bqSQL($tableName) . '`');
    $allCols = [];
    $productCols = [];
    if (is_array($cols)) {
        foreach ($cols as $c) {
            $name = (string) ($c['Field'] ?? '');
            $allCols[] = $name;
            if (stripos($name, 'product') !== false || stripos($name, 'pack') !== false) {
                $productCols[] = $name;
            }
        }
    }
    // Estime le nombre de lignes (peut etre approximatif via COUNT rapide)
    $rowCount = null;
    try {
        $rowCount = (int) $db->getValue('SELECT COUNT(*) FROM `' . bqSQL($tableName) . '`');
    } catch (\Throwable $e) {
        $rowCount = null;
    }
    $tableDetails[] = [
        'table' => $tableName,
        'row_count' => $rowCount,
        'all_columns' => $allCols,
        'candidate_id_columns' => $productCols,
    ];
}

// Scoring intelligent : combine nom de table + colonne pour trouver le plus
// probable module 'Advanced Pack' (evite les faux positifs type gift_pack).
// Bonus nom : advancedpack/advanced_pack (+50) > advanced (+30) > bundle (+15) > pack seul (+5)
// Bonus colonne : id_product_pack (+30) > id_pack (+25) > id_product (+15) > autre (+5)
// L'override manuel via ?table=X&column=Y ecrase le scoring.
$overrideTable = isset($_GET['table']) ? (string) $_GET['table'] : '';
$overrideColumn = isset($_GET['column']) ? (string) $_GET['column'] : '';
$colPriority = ['id_product_pack' => 30, 'id_pack' => 25, 'id_product' => 15, 'pack_id' => 12, 'product_id' => 10];

$foundTable = null;
$foundColumn = null;
$scoring = [];

if ($overrideTable !== '' && $overrideColumn !== '') {
    // Verifie que la combinaison est valide
    foreach ($tableDetails as $t) {
        if ($t['table'] === $overrideTable && in_array($overrideColumn, $t['all_columns'], true)) {
            $foundTable = $overrideTable;
            $foundColumn = $overrideColumn;
            break;
        }
    }
} else {
    foreach ($tableDetails as $t) {
        $nameScore = 0;
        $n = strtolower($t['table']);
        if (strpos($n, 'advancedpack') !== false || strpos($n, 'advanced_pack') !== false) {
            $nameScore = 50;
        } elseif (strpos($n, 'advanced') !== false) {
            $nameScore = 30;
        } elseif (strpos($n, 'bundle') !== false) {
            $nameScore = 15;
        } elseif (strpos($n, 'pack') !== false) {
            $nameScore = 5;
        }
        foreach ($t['candidate_id_columns'] as $col) {
            $colScore = $colPriority[$col] ?? 3;
            $total = $nameScore + $colScore;
            $scoring[] = ['table' => $t['table'], 'column' => $col, 'score' => $total, 'name_score' => $nameScore, 'col_score' => $colScore];
        }
    }
    // Tri decroissant par score
    usort($scoring, fn($a, $b) => $b['score'] <=> $a['score']);
    if ($scoring !== []) {
        $foundTable = $scoring[0]['table'];
        $foundColumn = $scoring[0]['column'];
    }
}

if ($foundTable === null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'data' => [
            'pack_ids' => [],
            'total' => 0,
            'source_table' => null,
            'id_column' => null,
        ],
        'debug' => [
            'message' => 'Aucune table Advanced Pack detectee automatiquement.',
            'db_prefix' => $prefix,
            'tables_scanned' => $tableDetails,
            'scoring' => $scoring,
            'patterns_used' => $patterns,
            'help' => 'Si le mauvais table est choisi, force-le via ?table=ps_xxx&column=id_xxx&key=...',
        ],
    ]);
    exit;
}

// Extraction des id_product distincts
$rows = $db->executeS('SELECT DISTINCT `' . bqSQL($foundColumn) . '` AS pid FROM `' . bqSQL($foundTable) . '`');
$ids = [];
if (is_array($rows)) {
    foreach ($rows as $r) {
        $pid = (int) ($r['pid'] ?? 0);
        if ($pid > 0) $ids[] = $pid;
    }
}
$ids = array_values(array_unique($ids));
sort($ids);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'data' => [
        'pack_ids' => $ids,
        'total' => count($ids),
        'source_table' => $foundTable,
        'id_column' => $foundColumn,
    ],
    'debug' => [
        'auto_detected' => $overrideTable === '' || $overrideColumn === '',
        'top_candidates' => array_slice($scoring, 0, 5),
        'override_available' => 'Pour forcer une autre table : ?table=ps_xxx&column=id_xxx&key=...',
    ],
]);
ADVPACK_TEMPLATE_END;
    }
}
