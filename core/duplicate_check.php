<?php
/**
 * CMU Lost & Found — Real-time Duplicate Check
 * POST /core/duplicate_check.php
 *
 * Called by item_image_upload.js as the user types a title in
 * report_lost.php or report_found.php.
 *
 * Body (JSON):
 *   { title: string, category_id: int, report_type: "lost"|"found" }
 *
 * Response:
 *   { success: bool, items: [{ title, category, created_at }] }
 */

header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'items' => []]);
    exit();
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/matching_engine.php';

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

$title       = trim($body['title']       ?? '');
$category_id = (int)($body['category_id'] ?? 0);
$report_type = in_array($body['report_type'] ?? '', ['lost', 'found'])
               ? $body['report_type']
               : 'lost';

if (strlen($title) < 3) {
    echo json_encode(['success' => true, 'items' => []]);
    exit();
}

try {
    $rows = realtimeDuplicateCheck($title, $category_id, $report_type, 4);

    $items = array_map(function (array $row) use ($pdo, $report_type): array {
        $cat = '';
        if (!empty($row['category_id'])) {
            static $catCache = [];
            if (!isset($catCache[$row['category_id']])) {
                $s = $pdo->prepare("SELECT name FROM categories WHERE category_id = ? LIMIT 1");
                $s->execute([$row['category_id']]);
                $catCache[$row['category_id']] = $s->fetchColumn() ?: '';
            }
            $cat = $catCache[$row['category_id']];
        }
        return [
            'report_id'  => $row['report_id']  ?? '',
            'type'       => $report_type,
            'title'      => $row['title']      ?? '',
            'category'   => $cat,
            'created_at' => $row['created_at'] ?? '',
        ];
    }, $rows);

    echo json_encode(['success' => true, 'items' => $items]);

} catch (Throwable $e) {
    error_log('[duplicate_check] ' . $e->getMessage());
    echo json_encode(['success' => false, 'items' => []]);
}