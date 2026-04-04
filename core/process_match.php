<?php
ob_start(); // Buffer everything so stray errors don't break JSON

session_start();
header('Content-Type: application/json');

// ── Auth guard ──────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

require_once __DIR__ . '/db_config.php';

// ── Parse JSON body ─────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!$raw || !$body) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Empty or invalid request body.']);
    exit();
}

$match_id = isset($body['match_id']) ? (int)$body['match_id'] : 0;
$action   = trim($body['action'] ?? '');

if (!$match_id || !in_array($action, ['confirm', 'reject', 'override_reject'], true)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request. match_id=' . $match_id . ' action=' . $action]);
    exit();
}

// ── Load the match row ──────────────────────────────────────────────────
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
    ob_end_clean();
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Match not found for match_id=' . $match_id]);
    exit();
}

// ── Handle each action ──────────────────────────────────────────────────
try {
    switch ($action) {

        case 'confirm':
            $pdo->prepare("
                UPDATE matches
                SET    status     = 'confirmed',
                       matched_by = ?,
                       matched_at = NOW()
                WHERE  match_id   = ?
            ")->execute([$_SESSION['user_id'], $match_id]);

            $pdo->prepare("
                UPDATE lost_reports SET status = 'matched' WHERE lost_id = ?
            ")->execute([$match['lost_id']]);

            sendMatchNotification($match);

            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'Match confirmed and SMS sent.']);
            break;

        case 'reject':
            $pdo->prepare("
                UPDATE matches
                SET    status     = 'rejected',
                       matched_by = ?,
                       matched_at = NOW()
                WHERE  match_id   = ?
            ")->execute([$_SESSION['user_id'], $match_id]);

            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'Match rejected.']);
            break;

        case 'override_reject':
            $pdo->prepare("
                UPDATE matches
                SET    status     = 'rejected',
                       matched_by = ?,
                       notes      = CONCAT(COALESCE(notes,''), ' [Admin override]'),
                       matched_at = NOW()
                WHERE  match_id   = ?
            ")->execute([$_SESSION['user_id'], $match_id]);

            $pdo->prepare("
                UPDATE lost_reports SET status = 'open' WHERE lost_id = ?
            ")->execute([$match['lost_id']]);

            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'Auto-match overridden and rejected.']);
            break;
    }

} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

// ── SMS helper ──────────────────────────────────────────────────────────
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

    if (function_exists('sendSms')) {
        sendSms($phone, $message);
    }
}