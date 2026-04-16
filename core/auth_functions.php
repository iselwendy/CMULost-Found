<?php
// core/auth_functions.php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['path' => '/', 'samesite' => 'Lax']);
    session_start();
}

require_once __DIR__ . '/db_config.php';

function handleLogin($pdo) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'login') {
        return null;
    }

    $email    = trim($_POST['cmu_email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        return ['type' => 'error', 'text' => 'Please enter your email and password.'];
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE cmu_email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            return ['type' => 'error', 'text' => 'Invalid email or password.'];
        }

        // Normalize role — handles both numeric (1) and string ('admin') values
        $raw_role   = $user['role'] ?? 'student';
        $normalized = is_numeric($raw_role)
            ? (intval($raw_role) === 1 ? 'admin' : 'student')
            : strtolower(trim($raw_role));

        session_regenerate_id(true);

        // Store BOTH keys so all pages work regardless of which key they check
        $_SESSION['user_id']       = $user['user_id'];
        $_SESSION['full_name']     = $user['full_name'];
        $_SESSION['user_name']     = $user['full_name'];   // used in admin sidebar
        $_SESSION['department']    = $user['department'];
        $_SESSION['school_number'] = $user['school_number'];
        $_SESSION['course_and_year'] = $user['course_and_year'];
        $_SESSION['cmu_email']     = $user['cmu_email'];
        $_SESSION['recovery_email']     = $user['recovery_email'];
        $_SESSION['created_at']    = date('M Y', strtotime($user['created_at']));
        $_SESSION['role']          = $normalized;  // 'admin' or 'student'
        $_SESSION['user_role']     = $normalized;  // alias — admin pages check this

        $location = ($normalized === 'admin')
            ? "../admin/dashboard.php"
            : "../public/index.php";

        header("Location: $location");
        exit();

    } catch (PDOException $e) {
        error_log("Login error: " . $e->getMessage());
        return ['type' => 'error', 'text' => 'A system error occurred. Please try again.'];
    }
}