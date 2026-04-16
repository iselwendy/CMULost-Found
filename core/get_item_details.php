<?php
/**
 * API Endpoint: Fetch found item details by Tracking ID (FND-XXXXX)
 * This endpoint is used by the Public Gallery and Admin Dashboard to 
 * retrieve detailed information about a specific found report.
 */
header('Content-Type: application/json');

require_once '../core/db_config.php';

$tracking_id = $_GET['tracking_id'] ?? '';

if (empty($tracking_id)) {
    echo json_encode(['success' => false, 'message' => 'No tracking ID provided.']);
    exit;
}

/**
 * Validate and Parse Tracking ID
 * Expected format: FND-XXXXX (e.g., FND-00001)
 */
if (preg_match('/FND-(\d{5})/', $tracking_id, $matches)) {
    $found_id = (int)$matches[1];
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid tracking ID format. Please use FND-XXXXX.'
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            f.found_id,
            f.title,
            f.private_description,
            f.category_id,
            f.status,
            f.created_at AS date_reported,
            loc.location_name AS found_location,
            c.name AS category_name,
            u.full_name AS finder_name,
            u.department AS finder_dept,
            img.image_path,
            CONCAT('FND-', LPAD(f.found_id, 5, '0')) AS tracking_id
        FROM found_reports f
        LEFT JOIN users u ON f.reported_by = u.user_id
        LEFT JOIN locations loc ON f.location_id = loc.location_id
        LEFT JOIN categories c ON f.category_id = c.category_id
        LEFT JOIN (
            SELECT report_id, image_path 
            FROM item_images 
            WHERE report_type = 'found' 
            GROUP BY report_id
        ) img ON f.found_id = img.report_id
        WHERE f.found_id = ?
    ");

    $stmt->execute([$found_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($item) {
        if (!empty($item['image_path'])) {
            $filename = basename($item['image_path']); 
            $item['image_path'] = '/CMULandF/uploads/' . $filename;
        } else {
            $item['image_path'] = null; // No image available
        }

        echo json_encode([
            'success' => true, 
            'item' => $item
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Item not found in our records.'
        ]);
    }

} catch (PDOException $e) {
    error_log("Database Error in get_item_details.php: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'A database error occurred. Please try again later.'
    ]);
}