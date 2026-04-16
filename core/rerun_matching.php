<?php
/**
 * CMU Lost & Found — Re-Run Matching Engine (AJAX endpoint)
 *
 * POST /core/rerun_matching.php
 * Runs runMatchingEngine() against every active found_report,
 * upserting new matches and updating stale ones.
 * Returns JSON { success, found_scanned, total_matches, new_matches, confirmed }.
 */

session_start();
header('Content-Type: application/json');

// ── Auth guard ─────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/matching_engine.php';

// ── Fetch all active found reports ─────────────────────────────────────────
$stmt = $pdo->query("
    SELECT found_id, title
    FROM   found_reports
    WHERE  status NOT IN ('claimed', 'disposed', 'returned')
    ORDER  BY created_at DESC
");
$found_reports = $stmt->fetchAll();

$found_scanned  = count($found_reports);
$total_matches  = 0;
$new_matches    = 0;
$confirmed      = 0;
$errors         = [];

foreach ($found_reports as $row) {
    $found_id = (int) $row['found_id'];

    try {
        // Check how many matches existed before
        $before = (int) $pdo->prepare("SELECT COUNT(*) FROM matches WHERE found_id = ?")
                              ->execute([$found_id]) ? $pdo->query("SELECT COUNT(*) FROM matches WHERE found_id = $found_id")->fetchColumn() : 0;

        $matches = runMatchingEngine($found_id, 0); // 0 = system-triggered

        $total_matches += count($matches);
        $new_matches   += max(0, count($matches) - $before);
        $confirmed     += count(array_filter($matches, fn($m) => $m['status'] === 'confirmed'));

    } catch (Throwable $e) {
        $errors[] = "found_id=$found_id: " . $e->getMessage();
        error_log('[rerun_matching] ' . end($errors));
    }
}

echo json_encode([
    'success'       => true,
    'found_scanned' => $found_scanned,
    'total_matches' => $total_matches,
    'new_matches'   => $new_matches,
    'confirmed'     => $confirmed,
    'errors'        => $errors,
]);