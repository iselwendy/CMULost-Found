<?php
/**
 * process_report.php
 * Handles the submission for both Lost and Found Item Reports.
 * Maps data to lost_reports, found_reports, and item_images tables.
 * Hierarchy: CMULandF/public/process_report.php -> CMULandF/core/db_config.php
 */

// 1. Database Connection & Session
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

// 2. Basic Security & Data Extraction
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request method.");
}

if (!isset($_POST['reporter_id']) || empty($_POST['reporter_id'])) {
    die("Unauthorized access: Reporter ID is missing or empty.");
}

$user_id     = $_POST['reporter_id'];
$report_type = $_POST['report_type'] ?? 'lost'; 
$title       = $_POST['title'] ?? 'Untitled Item';
$category_n  = $_POST['category'] ?? 'Other';
$location    = $_POST['location'] ?? 'Unknown Location';
$description = $_POST['hidden_marks'] ?? '';

/**
 * Handle Date: 
 * Updated to support DATETIME format in the database.
 * Convert datetime-local (e.g., 2023-10-25T14:30) to MySQL DATETIME (2023-10-25 14:30:00)
 */
$raw_date    = $_POST['date_lost'] ?? $_POST['date_found'] ?? date('Y-m-d H:i:s');
$date_event  = date('Y-m-d H:i:s', strtotime($raw_date));

/**
 * 3. Category Mapping
 */
$category_map = [
    'Electronics' => 1,
    'Valuables'   => 2,
    'Documents'   => 3,
    'Books'       => 4,
    'Clothing'    => 5,
    'Personal'    => 6,
    'Other'       => 7
];
$category_id = $category_map[$category_n] ?? 7;

// 4. Prepare Database Insertion
try {
    // Start Transaction
    $pdo->beginTransaction();

    if ($report_type === 'found') {
        $sql = "INSERT INTO found_reports (reported_by, category_id, title, private_description, location_found, date_found, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'in custody')";
    } else {
        $sql = "INSERT INTO lost_reports (user_id, category_id, title, private_description, location_lost, date_lost, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'open')";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $user_id, 
        $category_id, 
        $title, 
        $description, 
        $location, 
        $date_event
    ]);

    $report_id = $pdo->lastInsertId();

    // 5. Handle Image Upload & item_images Table
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = dirname(__FILE__) . '/../uploads/';
        
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $new_filename = $report_type . "_" . $report_id . "_" . time() . "." . $ext;
        $target_file = $upload_dir . $new_filename;

        if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_file)) {
            $db_image_path = 'uploads/' . $new_filename;
            
            $img_sql = "INSERT INTO item_images (report_type, report_id, image_path) VALUES (?, ?, ?)";
            $img_stmt = $pdo->prepare($img_sql);
            $img_stmt->execute([$report_type, $report_id, $db_image_path]);
        }
    }

    // Commit Transaction
    $pdo->commit();

    $_SESSION['msg'] = ucfirst($report_type) . " report submitted successfully!";
    header("Location: ../public/index.php?status=success&type=$report_type");
    exit();

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Database Error: " . $e->getMessage());
    $_SESSION['error'] = "Failed to save report: " . $e->getMessage();
    header("Location: ../public/report_" . $report_type . ".php?status=error");
    exit();
}
?>