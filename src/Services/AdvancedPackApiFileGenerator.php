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

// Auto-detection de la table Advanced Pack. On teste plusieurs candidats
// courants (varie selon la version du module).
$candidates = [
    // [table_suffix, column_id_pack] — le pack est le PRODUIT qui EST un pack
    ['ap_pack', 'id_product_pack'],
    ['advancedpack', 'id_product_pack'],
    ['advancedpack', 'id_product'],
    ['ap_pack', 'id_product'],
    ['advanced_pack', 'id_product_pack'],
    ['advanced_pack', 'id_product'],
];

$foundTable = null;
$foundColumn = null;
$detectionLog = [];
foreach ($candidates as [$suffix, $col]) {
    $tableName = $prefix . $suffix;
    // Table existe ?
    $exists = $db->getValue("SHOW TABLES LIKE '" . pSQL($tableName) . "'");
    if (!$exists) {
        $detectionLog[] = 'Table ' . $tableName . ' : absente';
        continue;
    }
    // Colonne existe ?
    $colExists = $db->getValue("SHOW COLUMNS FROM `" . bqSQL($tableName) . "` LIKE '" . pSQL($col) . "'");
    if (!$colExists) {
        $detectionLog[] = 'Table ' . $tableName . ' : colonne ' . $col . ' absente';
        continue;
    }
    $foundTable = $tableName;
    $foundColumn = $col;
    $detectionLog[] = 'Table ' . $tableName . ' : colonne ' . $col . ' TROUVEE';
    break;
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
            'message' => 'Aucune table Advanced Pack detectee.',
            'db_prefix' => $prefix,
            'candidates_tested' => $detectionLog,
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
]);
ADVPACK_TEMPLATE_END;
    }
}
