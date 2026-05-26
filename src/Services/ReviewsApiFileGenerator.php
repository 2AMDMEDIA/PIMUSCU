<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Génère le contenu du fichier api_reviews.php à uploader sur le shop client.
 *
 * Le template est embarqué en nowdoc pour éviter d'avoir un fichier .tpl
 * (certains FTP comme Amen rejettent les fichiers contenant du code PHP
 * mais ayant une extension non-.php).
 */
final class ReviewsApiFileGenerator
{
    public static function generate(string $apiKey): string
    {
        $template = self::template();
        return str_replace('__API_KEY__', addslashes($apiKey), $template);
    }

    private static function template(): string
    {
        return <<<'API_REVIEWS_TEMPLATE_END'
<?php
/**
 * API Avis Produit — endpoint pour le module ws_productreviews
 *
 * Fichier généré par PIM Musculation. À placer à la racine de votre PrestaShop.
 */

// Capture les erreurs PHP et les renvoie en JSON.
ini_set('display_errors', '0');
error_reporting(E_ALL);

function api_reviews_fail($message, $extra = []) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(array_merge(['error' => $message], $extra));
    exit;
}

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (\Throwable $e) {
    api_reviews_fail('PHP Exception', [
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
        'class' => get_class($e),
    ]);
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        api_reviews_fail('PHP Fatal', [
            'message' => $err['message'],
            'file' => basename($err['file']),
            'line' => $err['line'],
        ]);
    }
});

define('API_KEY', '__API_KEY__');
define('API_REVIEWS_VERSION', '2026-05-12-pdo-prepared-crud');
define('PS_ROOT', __DIR__);

if (isset($_GET['ping'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'pong' => true,
        'version' => API_REVIEWS_VERSION,
        'php' => PHP_VERSION,
    ]);
    exit;
}

if (empty($_GET['api_key']) || $_GET['api_key'] !== API_KEY) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

require_once PS_ROOT . '/config/config.inc.php';
require_once PS_ROOT . '/init.php';

while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');

$db = Db::getInstance();
$table = _DB_PREFIX_ . 'ws_product_comment';
$method = $_SERVER['REQUEST_METHOD'];

// GET — stats globales OU avis d'un produit
if ($method === 'GET') {
    $productId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;

    if ($productId > 0) {
        $rows = $db->executeS(
            "SELECT id_product_comment, id_product, id_customer, id_guest,
                    customer_name, title, content, grade, validate, deleted, date_add
               FROM `$table`
              WHERE id_product = $productId AND deleted = 0
              ORDER BY date_add DESC
              LIMIT 1000"
        );
        echo json_encode(['reviews' => $rows ?: []]);
        exit;
    }

    $stats = $db->executeS(
        "SELECT id_product,
                COUNT(*) AS count,
                ROUND(AVG(grade), 2) AS avg_grade
           FROM `$table`
          WHERE deleted = 0 AND validate = 1
          GROUP BY id_product"
    );
    echo json_encode(['stats' => $stats ?: []]);
    exit;
}

// POST — import / upsert (prepared statements PDO)
if ($method === 'POST') {
    $input = file_get_contents('php://input');
    $reviews = json_decode($input, true);

    if (!is_array($reviews)) {
        http_response_code(400);
        exit(json_encode(['error' => 'Invalid JSON or not an array']));
    }

    $pdo = $db->getLink();

    $stmtSelect = $pdo->prepare(
        "SELECT id_product_comment FROM `$table`
          WHERE id_product = :id_product
            AND customer_name = :customer_name
            AND LEFT(content, 100) = :content_check
          LIMIT 1"
    );
    $stmtUpdate = $pdo->prepare(
        "UPDATE `$table` SET
            id_customer = :id_customer,
            id_guest    = :id_guest,
            title       = :title,
            content     = :content,
            grade       = :grade,
            validate    = :validate,
            date_add    = :date_add
          WHERE id_product_comment = :id"
    );
    $stmtInsert = $pdo->prepare(
        "INSERT INTO `$table`
            (id_product, id_customer, id_guest, customer_name, title, content,
             grade, validate, deleted, date_add)
         VALUES
            (:id_product, :id_customer, :id_guest, :customer_name, :title, :content,
             :grade, :validate, :deleted, :date_add)"
    );

    $inserted = 0;
    $updated  = 0;
    $errors   = [];

    foreach ($reviews as $i => $r) {
        if (empty($r['id_product']) || empty($r['content'])) {
            $errors[] = "Index $i : id_product et content sont obligatoires";
            continue;
        }

        $id_product    = (int) $r['id_product'];
        $id_customer   = isset($r['id_customer']) ? (int) $r['id_customer'] : 0;
        $id_guest      = isset($r['id_guest'])    ? (int) $r['id_guest']   : 0;
        $customer_name = isset($r['customer_name']) ? mb_substr((string) $r['customer_name'], 0, 64) : '';
        $title         = isset($r['title'])         ? mb_substr((string) $r['title'], 0, 64)         : '';
        $content       = (string) $r['content'];
        $grade         = isset($r['grade'])         ? min(5, max(0, (int) $r['grade'])) : 5;
        $validate      = isset($r['validate'])      ? (int) (bool) $r['validate']       : 1;
        $deleted       = 0;
        $date_add      = isset($r['date_add']) ? (string) $r['date_add'] : date('Y-m-d H:i:s');

        $content_check = mb_substr($content, 0, 100);

        try {
            $stmtSelect->execute([
                ':id_product'    => $id_product,
                ':customer_name' => $customer_name,
                ':content_check' => $content_check,
            ]);
            $existing_id = (int) ($stmtSelect->fetchColumn() ?: 0);

            if ($existing_id) {
                $stmtUpdate->execute([
                    ':id_customer' => $id_customer,
                    ':id_guest'    => $id_guest,
                    ':title'       => $title,
                    ':content'     => $content,
                    ':grade'       => $grade,
                    ':validate'    => $validate,
                    ':date_add'    => $date_add,
                    ':id'          => $existing_id,
                ]);
                $updated++;
            } else {
                $stmtInsert->execute([
                    ':id_product'    => $id_product,
                    ':id_customer'   => $id_customer,
                    ':id_guest'      => $id_guest,
                    ':customer_name' => $customer_name,
                    ':title'         => $title,
                    ':content'       => $content,
                    ':grade'         => $grade,
                    ':validate'      => $validate,
                    ':deleted'       => $deleted,
                    ':date_add'      => $date_add,
                ]);
                $inserted++;
            }
        } catch (\Throwable $e) {
            $errors[] = "Index $i : " . $e->getMessage();
        }
    }

    echo json_encode([
        'success'  => true,
        'inserted' => $inserted,
        'updated'  => $updated,
        'errors'   => $errors,
    ]);
    exit;
}

// PUT — update d'un avis par id_product_comment
if ($method === 'PUT' || $method === 'PATCH') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        exit(json_encode(['error' => 'id manquant ou invalide']));
    }

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if (!is_array($data)) {
        http_response_code(400);
        exit(json_encode(['error' => 'Invalid JSON']));
    }

    $pdo = $db->getLink();
    $allowed = ['customer_name', 'title', 'content', 'grade', 'validate'];
    $sets = [];
    $params = [':id' => $id];
    foreach ($allowed as $field) {
        if (!array_key_exists($field, $data)) continue;
        $value = $data[$field];
        if ($field === 'grade')    $value = min(5, max(0, (int) $value));
        if ($field === 'validate') $value = (int) (bool) $value;
        if ($field === 'customer_name') $value = mb_substr((string) $value, 0, 64);
        if ($field === 'title')         $value = mb_substr((string) $value, 0, 64);
        if ($field === 'content')       $value = (string) $value;
        $sets[] = "$field = :$field";
        $params[":$field"] = $value;
    }
    if ($sets === []) {
        http_response_code(400);
        exit(json_encode(['error' => 'Aucun champ a mettre a jour']));
    }

    try {
        $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE id_product_comment = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $affected = $stmt->rowCount();
        echo json_encode(['success' => true, 'affected' => $affected]);
    } catch (\Throwable $e) {
        api_reviews_fail('UPDATE failed', ['message' => $e->getMessage()]);
    }
    exit;
}

// DELETE — soft delete par defaut (deleted=1), hard delete via ?hard=1
if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        exit(json_encode(['error' => 'id manquant ou invalide']));
    }
    $hard = !empty($_GET['hard']);
    $pdo = $db->getLink();

    try {
        if ($hard) {
            $stmt = $pdo->prepare("DELETE FROM `$table` WHERE id_product_comment = :id");
        } else {
            $stmt = $pdo->prepare("UPDATE `$table` SET deleted = 1 WHERE id_product_comment = :id");
        }
        $stmt->execute([':id' => $id]);
        echo json_encode(['success' => true, 'affected' => $stmt->rowCount(), 'hard' => $hard]);
    } catch (\Throwable $e) {
        api_reviews_fail('DELETE failed', ['message' => $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method Not Allowed']);
API_REVIEWS_TEMPLATE_END;
    }
}
