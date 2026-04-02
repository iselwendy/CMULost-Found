<?php
/**
 * CMU Lost & Found — Archive Action Handler
 * admin/process_archive.php
 *
 * Handles:
 *   action = archive_aging   →  move an aging found_report into the archive table
 *                               and mark it as 'disposed' in found_reports
 *
 * POST params (form):
 *   found_id  INT
 *   outcome   ENUM('Expired/Donated','Disposed')
 *   notes     TEXT (optional)
 *   action    string
 */

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../core/auth.php");
    exit();
}

require_once '../core/db_config.php';

$admin_id = (int) $_SESSION['user_id'];
$action   = trim($_POST['action'] ?? '');

if ($action === 'archive_aging') {
    $found_id = (int) ($_POST['found_id'] ?? 0);
    $outcome  = in_array($_POST['outcome'] ?? '', ['Expired/Donated', 'Disposed'])
                ? $_POST['outcome']
                : 'Disposed';
    $notes    = trim($_POST['notes'] ?? '');

    if ($found_id <= 0) {
        $_SESSION['archive_error'] = 'Invalid item ID.';
        header("Location: archive.php?tab=aging");
        exit();
    }

    try {
        // Pull snapshot data
        $stmt = $pdo->prepare("
            SELECT
                f.found_id, f.title,
                COALESCE(c.name, 'Other')              AS category,
                COALESCE(loc.location_name, 'Unknown') AS found_location,
                f.date_found,
                img.image_path,
                CONCAT('FND-', LPAD(f.found_id, 5, '0')) AS tracking_id
            FROM found_reports f
            LEFT JOIN categories c   ON f.category_id = c.category_id
            LEFT JOIN locations  loc ON f.location_id  = loc.location_id
            LEFT JOIN (
                SELECT report_id, image_path FROM item_images
                WHERE report_type = 'found' GROUP BY report_id
            ) img ON img.report_id = f.found_id
            WHERE f.found_id = ?
            LIMIT 1
        ");
        $stmt->execute([$found_id]);
        $report = $stmt->fetch();

        if (!$report) {
            $_SESSION['archive_error'] = 'Found report not found.';
            header("Location: archive.php?tab=aging");
            exit();
        }

        $pdo->beginTransaction();

        // Insert into archive
        $pdo->prepare("
            INSERT INTO archive
                (found_id, tracking_id, item_title, category, found_location,
                 date_event, outcome, claimant_name, resolved_by, resolved_date,
                 image_path, notes)
            VALUES
                (?, ?, ?, ?, ?,
                 ?, ?, 'N/A', ?, CURDATE(),
                 ?, ?)
        ")->execute([
            $report['found_id'],
            $report['tracking_id'],
            $report['title'],
            $report['category'],
            $report['found_location'],
            $report['date_found'],
            $outcome,
            $admin_id,
            $report['image_path'],
            $notes ?: null,
        ]);

        // Update found_reports status
        $new_status = ($outcome === 'Disposed') ? 'disposed' : 'disposed';
        $pdo->prepare("UPDATE found_reports SET status = 'disposed' WHERE found_id = ?")
            ->execute([$found_id]);

        $pdo->commit();

        $_SESSION['archive_success'] = "Item \"{$report['title']}\" has been archived as {$outcome}.";
        header("Location: archive.php?tab=records");
        exit();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[process_archive] DB Error: ' . $e->getMessage());
        $_SESSION['archive_error'] = 'Database error. Please try again.';
        header("Location: archive.php?tab=aging");
        exit();
    }
}

// Fallback
header("Location: archive.php");
exit(); 