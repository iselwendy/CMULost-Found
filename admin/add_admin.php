<?php
/**
 * CMU Lost & Found — Add Admin Handler
 * admin/add_admin.php
 *
 * Accepts JSON POST to create a new admin user.
 * Returns JSON { success, message }.
 */

session_start();
header('Content-Type: application/json');

// ── Auth guard ─────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

require_once '../core/db_config.php';

// ── Parse input ────────────────────────────────────────────────────────────
$body        = json_decode(file_get_contents('php://input'), true);
$full_name   = trim($body['full_name']     ?? '');
$cmu_email   = trim($body['cmu_email']     ?? '');
$school_no   = trim($body['school_number'] ?? '');
$department  = trim($body['department']    ?? '');
$phone       = trim($body['phone_number']  ?? '');
$password    = $body['password']           ?? '';
$confirm_pw  = $body['confirm_password']   ?? '';

// ── Validate ───────────────────────────────────────────────────────────────
$errors = [];

if (empty($full_name))  $errors[] = 'Full name is required.';
if (empty($cmu_email))  $errors[] = 'University email is required.';
if (empty($school_no))  $errors[] = 'School number is required.';
if (empty($department)) $errors[] = 'Department is required.';
if (empty($phone))      $errors[] = 'Phone number is required.';
if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
if ($password !== $confirm_pw) $errors[] = 'Passwords do not match.';

// Basic email format check
if (!empty($cmu_email) && !filter_var($cmu_email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Invalid email format.';
}

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit();
}

// ── Check for duplicate email / school number ──────────────────────────────
try {
    $check = $pdo->prepare("
        SELECT user_id FROM users
        WHERE cmu_email = ? OR school_number = ?
        LIMIT 1
    ");
    $check->execute([$cmu_email, $school_no]);

    if ($check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'An account with that email or school number already exists.']);
        exit();
    }

    // ── Insert new admin ────────────────────────────────────────────────────
    $hashed = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare("
        INSERT INTO users
            (school_number, cmu_email, password, full_name,
             department, phone_number, role, created_at, updated_at)
        VALUES
            (?, ?, ?, ?,
             ?, ?, 'admin', NOW(), NOW())
    ");
    $stmt->execute([
        $school_no,
        $cmu_email,
        $hashed,
        $full_name,
        $department,
        $phone,
    ]);

    echo json_encode([
        'success' => true,
        'message' => "Admin account for {$full_name} created successfully.",
    ]);

} catch (PDOException $e) {
    error_log('[add_admin] DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
}