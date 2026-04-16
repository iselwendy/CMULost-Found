<?php
/**
 * CMU Lost & Found — Delete Report Handler
 * dashboard/delete_report.php
 *
 * Deletes a found or lost report belonging to the currently logged-in user.
 * Also removes associated image records and the physical image file.
 *
 * GET params:
 *   type  — 'found' | 'lost'
 *   id    — integer record ID
 */

require_once '../core/auth_functions.php';

// ── Guard ────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header("Location: ../core/auth.php");
    exit();
}

$user_id     = $_SESSION['user_id'];
$report_type = $_GET['type'] ?? '';
$report_id   = (int) ($_GET['id'] ?? 0);

if (!in_array($report_type, ['found', 'lost']) || $report_id <= 0) {
    header("Location: profile.php?error=invalid");
    exit();
}

try {
    $pdo->beginTransaction();

    if ($report_type === 'found') {
        // Verify ownership
        $stmt = $pdo->prepare("SELECT found_id FROM found_reports WHERE found_id = ? AND reported_by = ?");
        $stmt->execute([$report_id, $user_id]);

        if (!$stmt->fetch()) {
            $pdo->rollBack();
            header("Location: profile.php?error=notfound");
            exit();
        }

        // Get image paths for file cleanup
        $imgStmt = $pdo->prepare("SELECT image_path FROM item_images WHERE report_type = 'found' AND report_id = ?");
        $imgStmt->execute([$report_id]);
        $images = $imgStmt->fetchAll();

        // Delete image records
        $pdo->prepare("DELETE FROM item_images WHERE report_type = 'found' AND report_id = ?")->execute([$report_id]);

        $pdo->prepare("DELETE FROM matches WHERE found_id = ?")->execute([$report_id]);

        // Delete the report
        $pdo->prepare("DELETE FROM found_reports WHERE found_id = ? AND reported_by = ?")->execute([$report_id, $user_id]);

    } else {
        // Verify ownership
        $stmt = $pdo->prepare("SELECT lost_id FROM lost_reports WHERE lost_id = ? AND user_id = ?");
        $stmt->execute([$report_id, $user_id]);

        if (!$stmt->fetch()) {
            $pdo->rollBack();
            header("Location: profile.php?error=notfound");
            exit();
        }

        // Get image paths for file cleanup
        $imgStmt = $pdo->prepare("SELECT image_path FROM item_images WHERE report_type = 'lost' AND report_id = ?");
        $imgStmt->execute([$report_id]);
        $images = $imgStmt->fetchAll();

        // Delete image records
        $pdo->prepare("DELETE FROM item_images WHERE report_type = 'lost' AND report_id = ?")->execute([$report_id]);

        $pdo->prepare("DELETE FROM matches WHERE lost_id = ?")->execute([$report_id]);

        // Delete the report
        $pdo->prepare("DELETE FROM lost_reports WHERE lost_id = ? AND user_id = ?")->execute([$report_id, $user_id]);
    }

    $pdo->commit();

    // ── Clean up physical image files ─────────────────────
    foreach ($images as $img) {
        $file_path = dirname(__FILE__) . '/../' . $img['image_path'];
        if (file_exists($file_path)) {
            @unlink($file_path);
        }
    }

    header("Location: profile.php?deleted=1");
    exit();

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Delete report error: " . $e->getMessage());
    header("Location: profile.php?error=db");
    exit();
}