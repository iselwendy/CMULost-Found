<?php
/**
 * CMU Lost & Found - Logout Handler
 * Clears session data and redirects the user to the login page.
 */

// Initialize the session
session_start();

// Unset all session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

unset($_SESSION['ai_suggestion_calls']);

// Finally, destroy the session.
session_destroy();

// Redirect to login page
header("location: ../core/auth.php");
exit;
?>