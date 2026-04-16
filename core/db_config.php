<?php
/**
 * CMU Lost & Found - Database Configuration
 * This file handles the PDO connection to the MySQL database.
 */

// Database Credentials
$host     = 'localhost';
$dbname   = 'cmu_lost_found';
$username = 'mariel'; // Default XAMPP username
$password = '00112233';     // Default XAMPP password
$charset  = 'utf8mb4';

// Data Source Name
$dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";

// PDO Options
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on error
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Fetch as associative array
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Use actual prepared statements
];

try {
    // Create the connection
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (\PDOException $e) {
    // If connection fails, stop the script and show error
    // In production, you should log this to a file instead of echoing
    die("Database Connection Failed: " . $e->getMessage());
}

/**
 * Quick Helper to get the PDO instance globally
 */
function getDB() {
    global $pdo;
    return $pdo;
}
?>