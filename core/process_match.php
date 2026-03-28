<?php
/**
 * CMU Lost & Found — Match Action Handler
 *
 * Accepts JSON POST from the Matching Portal JS (confirm / reject / override_reject).
 * Updates the matches table and, on confirm, triggers an SMS via sms_gateway.php.
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

// ── Parse JSON body ────────────────────────────────────────────────────────
$body     = json_decode(file_get_contents('php://input'), true);
$match_id = isset($body['match_id']) ? (int)$body['match_id'] : 0;
$action   = trim($body['action'] ?? '');

if (!$match_id || !in_array($action, ['confirm', 'reject', 'override_reject'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit();
}

// ── Load the match row ─────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT m.*,
           f.title      AS found_title,
           l.title      AS lost_title,
           u.full_name  AS owner_name,
           u.phone_number AS phone
    FROM   matches      m
    JOIN   found_reports f ON m.found_id = f.found_id
    JOIN   lost_reports  l ON m.lost_id  = l.lost_id
    JOIN   users         u ON l.user_id  = u.user_id
    WHERE  m.match_id = ?
    LIMIT  1
");
$stmt->execute([$match_id]);
$match = $stmt->fetch();

if (!$match) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Match not found.']);
    exit();
}

// ── Handle each action ─────────────────────────────────────────────────────
try {
    switch ($action) {

        // Admin manually confirms a <90% match and sends SMS
        case 'confirm':
            $pdo->prepare("
                UPDATE matches
                SET    status     = 'confirmed',
                       matched_by = ?,
                       matched_at = NOW()
                WHERE  match_id   = ?
            ")->execute([$_SESSION['user_id'], $match_id]);

            // Mark the lost report as 'matched' so it leaves the open queue
            $pdo->prepare("
                UPDATE lost_reports SET status = 'matched' WHERE lost_id = ?
            ")->execute([$match['lost_id']]);

            // Send SMS notification to item owner
            sendMatchNotification($match);

            echo json_encode(['success' => true, 'message' => 'Match confirmed and SMS sent.']);
            break;

        // Admin rejects a pending (<90%) match
        case 'reject':
            $pdo->prepare("
                UPDATE matches
                SET    status     = 'rejected',
                       matched_by = ?,
                       matched_at = NOW()
                WHERE  match_id   = ?
            ")->execute([$_SESSION['user_id'], $match_id]);

            echo json_encode(['success' => true, 'message' => 'Match rejected.']);
            break;

        // Admin overrides an already auto-confirmed (≥90%) match
        case 'override_reject':
            $pdo->prepare("
                UPDATE matches
                SET    status     = 'rejected',
                       matched_by = ?,
                       notes      = CONCAT(COALESCE(notes,''), ' [Admin override]'),
                       matched_at = NOW()
                WHERE  match_id   = ?
            ")->execute([$_SESSION['user_id'], $match_id]);

            // Revert the lost report back to 'open' so it can be re-matched
            $pdo->prepare("
                UPDATE lost_reports SET status = 'open' WHERE lost_id = ?
            ")->execute([$match['lost_id']]);

            echo json_encode(['success' => true, 'message' => 'Auto-match overridden and rejected.']);
            break;
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

// ── SMS helper ─────────────────────────────────────────────────────────────
function sendMatchNotification(array $match): void
{
    $phone     = $match['phone']      ?? null;
    $ownerName = $match['owner_name'] ?? 'Student';
    $itemTitle = $match['lost_title'] ?? 'your item';

    if (!$phone) return;

    $message = "Hi {$ownerName}, a potential match for your lost \"{$itemTitle}\" "
             . "has been found at the Office of Student Affairs. "
             . "Please visit OSA with a valid ID to verify and claim your item. "
             . "- CMU Lost & Found";

    // Delegate to sms_gateway.php
    if (function_exists('sendSms')) {
        sendSms($phone, $message);
    }
}