<?php
/**
 * CMU Lost & Found — Export Action Log
 * admin/export_log.php
 *
 * Streams a CSV of the admin_action_log table.
 * Protected: admin only.
 */

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../core/auth.php");
    exit();
}

require_once '../core/db_config.php';

// ── Query ─────────────────────────────────────────────────────────────────────
try {
    $stmt = $pdo->query("
        SELECT
            l.log_id,
            l.created_at,
            u.full_name    AS admin_name,
            u.cmu_email    AS admin_email,
            l.action_type,
            l.target_type,
            l.target_id,
            l.description
        FROM admin_action_log l
        LEFT JOIN users u ON u.user_id = l.admin_id
        ORDER BY l.created_at DESC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

// ── Stream CSV ────────────────────────────────────────────────────────────────
$filename = 'admin_action_log_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// UTF-8 BOM so Excel opens it correctly
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Header row
fputcsv($out, [
    'Log ID',
    'Timestamp',
    'Admin Name',
    'Admin Email',
    'Action Type',
    'Target Type',
    'Target ID',
    'Description',
]);

// Data rows
foreach ($rows as $row) {
    fputcsv($out, [
        $row['log_id'],
        $row['created_at'],
        $row['admin_name']  ?? 'System',
        $row['admin_email'] ?? '',
        str_replace('_', ' ', $row['action_type'] ?? ''),
        $row['target_type'] ?? '',
        $row['target_id']   ?? '',
        $row['description'] ?? '',
    ]);
}

fclose($out);
exit();