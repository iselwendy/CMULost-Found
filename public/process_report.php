<?php
/**
 * CMU Lost & Found - Unified Report Processor
 * Handles submission for both Lost and Found Item Reports.
 *
 * Found items:
 *   - Inserts into found_reports
 *   - Uploads photo → item_images
 *   - Calls runMatchingEngine() → populates matches table
 *   - Sends confirmation email to the finder
 *
 * Lost items:
 *   - Inserts into lost_reports
 *   - Uploads photo → item_images (optional)
 *   - Sends confirmation email to the reporter
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────

$paths = [
    dirname(__FILE__) . '/../core/db_config.php',
    dirname(__FILE__) . '/db_config.php',
    $_SERVER['DOCUMENT_ROOT'] . '/CMULandF/core/db_config.php'
];

$loaded = false;
foreach ($paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    die("Fatal Error: Could not find core/db_config.php.");
}

session_start();

if (!isset($pdo)) {
    die("Fatal Error: Database connection variable (\$pdo) is not defined.");
}

// ── Gate: POST only ───────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request method.");
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../core/auth.php");
    exit();
}

// ── Helpers ───────────────────────────────────────────────────────────────

function respondJson(bool $success, string $message, array $data = []): void
{
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit();
}

// ── 1. Extract & Sanitize Input ───────────────────────────────────────────

$user_id     = (int) $_SESSION['user_id'];
$report_type = $_POST['report_type'] ?? 'lost'; // 'lost' | 'found'
$title       = trim($_POST['title'] ?? 'Untitled Item');
$category_n  = trim($_POST['category'] ?? 'Other');
$description = trim($_POST['hidden_marks'] ?? ''); // compiled private marks from JS

$raw_date   = $_POST['date_lost'] ?? $_POST['date_found'] ?? date('Y-m-d');
$date_event = date('Y-m-d', strtotime($raw_date));

// ── 2. Category & Location Mapping ───────────────────────────────────────

$category_map = [
    'Electronics' => 1,
    'Valuables'   => 2,
    'Documents'   => 3,
    'Books'       => 4,
    'Clothing'    => 5,
    'Personal'    => 6,
    'Other'       => 7,
];
$category_id = $category_map[$category_n] ?? 7;

$location_id = (int)($_POST['location_id'] ?? 0);
// Validate it exists in the DB
if ($location_id > 0) {
    $loc_check = $pdo->prepare("SELECT 1 FROM locations WHERE location_id = ? LIMIT 1");
    $loc_check->execute([$location_id]);
    if (!$loc_check->fetchColumn()) {
        $location_id = 0; // fallback if invalid
    }
}

// ── 3. Photo Upload Helper ────────────────────────────────────────────────

function handlePhotoUpload(array $file, string $report_type, int $report_id): ?string
{
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Photo upload failed (error code ' . $file['error'] . ').');
    }

    $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $finfo         = new finfo(FILEINFO_MIME_TYPE);
    $mime          = $finfo->file($file['tmp_name']);

    if (!in_array($mime, $allowed_types, true)) {
        throw new RuntimeException('Invalid file type. Only JPEG, PNG, WEBP, and GIF are allowed.');
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Photo must be under 5 MB.');
    }

    $upload_dir = dirname(__FILE__) . '/../uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $ext          = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $new_filename = $report_type . '_' . $report_id . '_' . time() . '.' . $ext;
    $dest         = $upload_dir . $new_filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Failed to save uploaded photo.');
    }

    return 'uploads/' . $new_filename;
}

// ── 4. Database Insertion ─────────────────────────────────────────────────

$report_id = null;

try {
    $pdo->beginTransaction();

    if ($report_type === 'found') {
        $stmt = $pdo->prepare("
            INSERT INTO found_reports
                   (reported_by, category_id, location_id,
                    title, private_description,
                    date_found, status, created_at)
            VALUES (?, ?, ?,
                    ?, ?,
                    ?, 'in custody', NOW())
        ");
        $stmt->execute([
            $user_id,
            $category_id,
            $location_id,
            $title,
            $description,
            $date_event,
        ]);

    } else {
        $stmt = $pdo->prepare("
            INSERT INTO lost_reports
                   (user_id, category_id, location_id,
                    title, private_description,
                    date_lost, status)
            VALUES (?, ?, ?,
                    ?, ?,
                    ?, 'open')
        ");
        $stmt->execute([
            $user_id,
            $category_id,
            $location_id,
            $title,
            $description,
            $date_event,
        ]);
    }

    $report_id = (int) $pdo->lastInsertId();

    // ── 5. Photo Upload ───────────────────────────────────────────────────

    if (!empty($_FILES['photo']['name'])) {
        $image_path = handlePhotoUpload($_FILES['photo'], $report_type, $report_id);

        if ($image_path !== null) {
            $pdo->prepare("
                INSERT INTO item_images (report_type, report_id, image_path)
                VALUES (?, ?, ?)
            ")->execute([$report_type, $report_id, $image_path]);
        }
    }

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[process_report] DB Error: ' . $e->getMessage());

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        respondJson(false, 'An error occurred while saving your report. Please try again.');
    }

    $_SESSION['error'] = 'Failed to save report. Please try again.';
    header("Location: report_{$report_type}.php?status=error");
    exit();
}

// ── 6. Post-Insert: Run Matching Engine (Found Items Only) ────────────────

$match_count = 0;

if ($report_type === 'found') {
    $engine_path = dirname(__FILE__) . '/../core/matching_engine.php';

    if (file_exists($engine_path)) {
        require_once $engine_path;
        try {
            $matches     = runMatchingEngine($report_id, 0); // 0 = system-triggered
            $match_count = count($matches);
        } catch (Throwable $e) {
            error_log('[MatchingEngine] Error for found_id=' . $report_id . ': ' . $e->getMessage());
        }
    } else {
        error_log('[process_report] matching_engine.php not found at: ' . $engine_path);
    }
}

// ── 7. Send Confirmation Email ────────────────────────────────────────────

try {
    $mailer_path = dirname(__FILE__) . '/../core/mailer.php';

    if (file_exists($mailer_path)) {
        require_once $mailer_path;

        // Fetch user's email
        $uStmt = $pdo->prepare("SELECT full_name, cmu_email, recovery_email FROM users WHERE user_id = ? LIMIT 1");
        $uStmt->execute([$user_id]);
        $reporter = $uStmt->fetch();

        if ($reporter && !empty($reporter['recovery_email'])) {
            $prefix      = $report_type === 'found' ? 'FND' : 'LST';
            $tracking_id = $prefix . '-' . str_pad((string)$report_id, 5, '0', STR_PAD_LEFT);

            sendReportConfirmationEmail(
                $reporter['recovery_email'],
                $reporter['full_name'],
                $report_type,
                $title,
                $tracking_id
            );
        }
    }
} catch (Throwable $e) {
    // Non-fatal — don't block the user's success flow
    error_log('[process_report] Confirmation email failed: ' . $e->getMessage());
}

// ── 8. Respond ────────────────────────────────────────────────────────────

$success_message = ucfirst($report_type) . ' report submitted successfully!';
$_SESSION['msg'] = $success_message;

// AJAX response
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    respondJson(true, $success_message, [
        'report_id'   => $report_id,
        'match_count' => $match_count,
    ]);
}

// Standard POST redirect
if ($report_type === 'found') {
    $_SESSION['report_success'] = [
        'found_id'    => $report_id,
        'match_count' => $match_count,
    ];
    header("Location: ../dashboard/my_reports.php?new_report=1");
} else {
    header("Location: ../public/index.php?status=success&type=lost");
}

exit();