<?php
// core/auth_functions.php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['path' => '/', 'samesite' => 'Lax']);
    session_start();
}

require_once __DIR__ . '/db_config.php';

function handleLogin($pdo) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
        $email = trim($_POST['cmu_email']);
        $password = $_POST['password'] ?? '';

        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE cmu_email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['department'] = $user['department'];
                $_SESSION['school_number'] = $user['school_number'];
                $_SESSION['course_and_year'] = $user['course_and_year'];
                $_SESSION['role'] = $user['role']; 
                $_SESSION['cmu_email'] = $user['cmu_email'];
                $_SESSION['created_at'] = date('M Y', strtotime($user['created_at']));

                $location = ($user['role'] === 'admin') ? "../admin/dashboard.php" : "../public/index.php";
                header("Location: $location");
                exit();
            }
            return ['type' => 'error', 'text' => 'Invalid email or password.'];
        } catch (PDOException $e) {
            return ['type' => 'error', 'text' => 'System error.'];
        }
    }
    return null;
}