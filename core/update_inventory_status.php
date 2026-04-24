<?php
/**
 * CMU Lost & Found — Update Inventory Status
 * POST /core/update_inventory_status.php
 *
 * Called by the QR Intake Scanner after an admin confirms physical receipt
 * of a found item. Does two things:
 *   1. Updates found_reports.status  → 'surrendered'
 *   2. Upserts a row in the inventory table with the assigned shelf location
 *
 * Expected JSON body:
 *   {
 *     "tracking_id":    "FND-00042",   // format FND-XXXXX
 *     "shelf_location": "B",           // single letter shelf code
 *     "bin_number":     "101",         // bin / row string
 *     "status":         "surrendered"  // always 'surrendered' from the scanner
 *   }
 *
 * Response JSON:
 *   { "success": true,  "message": "...", "found_id": 42 }
 *   { "success": false, "message": "..." }
 */

header('Content-Type: application/json');
session_start();

// ── Auth guard ────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

// ── Bootstrap DB ──────────────────────────────────────────────────────────────
$db_path = __DIR__ . '/db_config.php';
if (!file_exists($db_path)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server configuration error.']);
    exit();
}
require_once $db_path;

// ── Parse JSON body ───────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body.']);
    exit();
}

$tracking_id    = trim($body['tracking_id']    ?? '');
$shelf_location = trim($body['shelf_location'] ?? '');
$bin_number     = trim($body['bin_number']     ?? '');
$admin_id       = (int) $_SESSION['user_id'];

// ── Validate inputs ───────────────────────────────────────────────────────────
if (empty($tracking_id)) {
    echo json_encode(['success' => false, 'message' => 'Tracking ID is required.']);
    exit();
}

if (empty($shelf_location)) {
    echo json_encode(['success' => false, 'message' => 'Please select a shelf before confirming.']);
    exit();
}

if (empty($bin_number)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a bin / row number before confirming.']);
    exit();
}

// ── Parse tracking ID → found_id ──────────────────────────────────────────────
// Accepts both "FND-00042" and plain "42"
if (preg_match('/FND-0*(\d+)/i', $tracking_id, $m)) {
    $found_id = (int) $m[1];
} elseif (ctype_digit($tracking_id)) {
    $found_id = (int) $tracking_id;
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid tracking ID format. Expected FND-XXXXX.']);
    exit();
}

if ($found_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Could not resolve a valid item ID from the tracking code.']);
    exit();
}

// ── Ensure inventory table exists ─────────────────────────────────────────────
// Creates the table on first use so no separate migration script is needed.
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventory (
            inventory_id   INT          PRIMARY KEY AUTO_INCREMENT,
            found_id       INT          NOT NULL UNIQUE,
            shelf          VARCHAR(10)  NOT NULL,
            row_bin        VARCHAR(20)  NOT NULL,
            received_by    INT          DEFAULT NULL,
            received_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            updated_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (found_id)    REFERENCES found_reports(found_id) ON DELETE CASCADE,
            FOREIGN KEY (received_by) REFERENCES users(user_id) ON DELETE SET NULL
        )
    ");
} catch (PDOException $e) {
    // Table may already exist with a slightly different definition — that's fine.
    error_log('[update_inventory_status] CREATE TABLE warning: ' . $e->getMessage());
}

// ── Load the found report (verify it exists & is in the right state) ──────────
try {
    $check = $pdo->prepare("
        SELECT found_id, status, title
        FROM   found_reports
        WHERE  found_id = ?
        LIMIT  1
    ");
    $check->execute([$found_id]);
    $report = $check->fetch();
} catch (PDOException $e) {
    error_log('[update_inventory_status] SELECT error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error while looking up the item.']);
    exit();
}

if (!$report) {
    echo json_encode([
        'success' => false,
        'message' => "No found report matches tracking ID \"{$tracking_id}\". Make sure the item has been registered in the system first."
    ]);
    exit();
}

// Block double-processing unless it's already surrendered (idempotent re-scan)
if (in_array($report['status'], ['claimed', 'disposed', 'returned'], true)) {
    echo json_encode([
        'success' => false,
        'message' => "This item is already marked as \"{$report['status']}\" and cannot be re-processed."
    ]);
    exit();
}

// ── Run the update inside a transaction ──────────────────────────────────────
try {
    $pdo->beginTransaction();

    $pdo->prepare("
        UPDATE found_reports
        SET    status = 'surrendered'
        WHERE  found_id = ?
    ")->execute([$found_id]);

    // 2. Upsert inventory row (shelf + bin)
    //    ON DUPLICATE KEY UPDATE handles re-scanning the same item (e.g. moved to a different shelf)
    $pdo->prepare("
        INSERT INTO inventory (found_id, shelf, row_bin, received_by, received_at)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            shelf       = VALUES(shelf),
            row_bin     = VALUES(row_bin),
            received_by = VALUES(received_by)
    ")->execute([$found_id, $shelf_location, $bin_number, $admin_id]);

    // 3. Trigger matching engine now that item is physically confirmed
    //    Non-fatal: matching failure must never block the success response.
    $engine = __DIR__ . '/matching_engine.php';
    if (file_exists($engine)) {
        require_once $engine;
        try {
            runMatchingEngine($found_id, $admin_id);
        } catch (Throwable $me) {
            error_log('[update_inventory_status] MatchingEngine error for found_id=' . $found_id . ': ' . $me->getMessage());
        }
    }

    $pdo->commit();

    // ── Send turnover confirmation email to finder ──────────────────────
    try {
        $finder_stmt = $pdo->prepare("
            SELECT u.full_name, u.recovery_email, u.cmu_email
            FROM found_reports fr
            JOIN users u ON fr.reported_by = u.user_id
            WHERE fr.found_id = ?
            LIMIT 1
        ");
        $finder_stmt->execute([$found_id]);
        $finder = $finder_stmt->fetch();

        if ($finder) {
            $email = !empty($finder['recovery_email'])
                ? $finder['recovery_email']
                : ($finder['cmu_email'] ?? null);

            if ($email) {
                require_once __DIR__ . '/mailer.php';
                sendTurnoverConfirmationEmail(
                    $email,
                    $finder['full_name'],
                    $report['title'],
                    "FND-" . str_pad($found_id, 5, '0', STR_PAD_LEFT),
                    "{$shelf_location}-{$bin_number}"
                );
            }
        }
    } catch (Throwable $mail_err) {
        // Non-fatal — don't block the success response
        error_log('[update_inventory_status] Email error: ' . $mail_err->getMessage());
    }

    echo json_encode([
        'success'  => true,
        'message'  => "Item \"{$report['title']}\" has been confirmed and shelved at {$shelf_location}-{$bin_number}.",
        'found_id' => $found_id,
        'shelf'    => "{$shelf_location}-{$bin_number}",
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[update_inventory_status] Transaction error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'A database error occurred while updating the inventory. Please try again.'
    ]);
}