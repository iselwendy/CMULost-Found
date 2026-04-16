<?php
/**
 * CMU Lost & Found — Get Custody Items
 * admin/get_custody_items.php
 *
 * Returns all found_reports in OSA custody (status = 'surrendered')
 * along with their shelf/bin assignments from the inventory table.
 * Used by the Shelf Labels modal in dashboard.php.
 *
 * GET /admin/get_custody_items.php
 * Response: { success: bool, items: [...] }
 */

session_start();
header('Content-Type: application/json');

// ── Auth guard ────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

require_once '../core/db_config.php';

try {
    $stmt = $pdo->query("
        SELECT
            f.found_id,
            CONCAT('FND-', LPAD(f.found_id, 5, '0')) AS tracking_id,
            f.title,
            f.status,
            f.date_found,
            COALESCE(c.name,          'Other')   AS category,
            COALESCE(loc.location_name,'Unknown') AS found_location,
            u.full_name                           AS finder_name,
            u.department                          AS finder_dept,
            inv.shelf,
            inv.row_bin,
            img.image_path
        FROM found_reports f
        LEFT JOIN users      u   ON f.reported_by  = u.user_id
        LEFT JOIN categories c   ON f.category_id  = c.category_id
        LEFT JOIN locations  loc ON f.location_id  = loc.location_id
        LEFT JOIN inventory  inv ON inv.found_id   = f.found_id
        LEFT JOIN (
            SELECT report_id, image_path
            FROM   item_images
            WHERE  report_type = 'found'
            GROUP  BY report_id
        ) img ON img.report_id = f.found_id
        WHERE f.status IN ('surrendered', 'in custody')
        ORDER BY inv.shelf ASC, f.date_found DESC
    ");

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Sanitize image paths for the web
    foreach ($items as &$item) {
        $item['image_path'] = !empty($item['image_path'])
            ? '../' . $item['image_path']
            : null;
    }
    unset($item);

    echo json_encode(['success' => true, 'items' => $items]);

} catch (PDOException $e) {
    error_log('[get_custody_items] DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.', 'items' => []]);
}