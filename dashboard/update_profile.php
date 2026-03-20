<?php
require_once '../core/auth_functions.php'; // Ensure this contains your PDO $pdo connection

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    
    // Sanitize inputs
    $full_name = trim($_POST['full_name']);
    $recovery_email = trim($_POST['recovery_email']);
    $phone_number = trim($_POST['phone_number']);

    try {
        $sql = "UPDATE users SET 
                full_name = ?, 
                recovery_email = ?, 
                phone_number = ? 
                WHERE user_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([$full_name, $recovery_email, $phone_number, $user_id]);

        if ($result) {
            // Redirect back with a success message
            header("Location: settings.php?status=updated");
            exit();
        }
    } catch (PDOException $e) {
        // Handle errors (e.g., duplicate email)
        header("Location: settings.php?status=error");
        exit();
    }
}