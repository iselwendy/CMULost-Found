<?php
/**
 * CMU Lost & Found — Record Merger
 * admin/record_merger.php
 *
 * Resolves duplicate found/lost reports by:
 *   1. Detecting likely duplicates (same category + keyword similarity)
 *   2. Showing a side-by-side comparison of any two reports
 *   3. Merging: all images, matches, and inventory rows are re-linked to
 *      the primary record; the duplicate is soft-deleted (status = 'disposed'
 *      for found, 'closed' for lost) and flagged in a merge_log table.
 *
 * Usage:
 *   GET  record_merger.php              → duplicate detection dashboard
 *   GET  record_merger.php?a=FND-x&b=FND-y  → compare two specific reports
 *   POST record_merger.php              → execute merge
 */

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../core/auth.php");
    exit();
}

require_once '../core/db_config.php';

// ── Bootstrap merge_log table (idempotent) ────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS merge_log (
            merge_id        INT           PRIMARY KEY AUTO_INCREMENT,
            report_type     ENUM('found','lost') NOT NULL,
            primary_id      INT           NOT NULL,
            duplicate_id    INT           NOT NULL,
            merged_by       INT           NOT NULL,
            merge_reason    TEXT          DEFAULT NULL,
            images_moved    SMALLINT      DEFAULT 0,
            matches_moved   SMALLINT      DEFAULT 0,
            merged_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_primary   (primary_id),
            INDEX idx_duplicate (duplicate_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException) { /* already exists */ }

$admin_id   = (int) $_SESSION['user_id'];
$admin_name = htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['user_name'] ?? 'Admin');

// ── Helpers ───────────────────────────────────────────────────────────────

/** Parse "FND-00042" or "LST-00003" → ['type'=>'found','id'=>42] */
function parseRef(string $ref): ?array
{
    if (preg_match('/^(FND|LST)-0*(\d+)$/i', strtoupper(trim($ref)), $m)) {
        return [
            'type' => strtoupper($m[1]) === 'FND' ? 'found' : 'lost',
            'id'   => (int) $m[2],
        ];
    }
    return null;
}

/** Fetch a full report row (found or lost) with category/location/images. */
function fetchReport(PDO $pdo, string $type, int $id): ?array
{
    if ($type === 'found') {
        $stmt = $pdo->prepare("
            SELECT
                f.found_id AS report_id,
                'found'    AS report_type,
                CONCAT('FND-', LPAD(f.found_id, 5, '0')) AS tracking_id,
                f.title,
                f.private_description AS description,
                f.status,
                f.date_found  AS date_event,
                f.created_at,
                COALESCE(c.name,          'Uncategorized') AS category,
                COALESCE(loc.location_name,'Unknown')      AS location,
                u.full_name  AS reporter_name,
                u.department AS reporter_dept,
                f.category_id,
                f.location_id
            FROM found_reports f
            LEFT JOIN categories c   ON f.category_id = c.category_id
            LEFT JOIN locations  loc ON f.location_id  = loc.location_id
            LEFT JOIN users      u   ON f.reported_by  = u.user_id
            WHERE f.found_id = ?
            LIMIT 1
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT
                l.lost_id  AS report_id,
                'lost'     AS report_type,
                CONCAT('LST-', LPAD(l.lost_id, 5, '0')) AS tracking_id,
                l.title,
                l.private_description AS description,
                l.status,
                l.date_lost AS date_event,
                l.created_at,
                COALESCE(c.name,          'Uncategorized') AS category,
                COALESCE(loc.location_name,'Unknown')      AS location,
                u.full_name  AS reporter_name,
                u.department AS reporter_dept,
                l.category_id,
                l.location_id
            FROM lost_reports l
            LEFT JOIN categories c   ON l.category_id = c.category_id
            LEFT JOIN locations  loc ON l.location_id  = loc.location_id
            LEFT JOIN users      u   ON l.user_id      = u.user_id
            WHERE l.lost_id = ?
            LIMIT 1
        ");
    }
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return null;

    // Attach images
    $imgs = $pdo->prepare("
        SELECT image_id, image_path FROM item_images
        WHERE report_type = ? AND report_id = ?
        ORDER BY uploaded_at ASC
    ");
    $imgs->execute([$type, $id]);
    $row['images'] = $imgs->fetchAll();

    // Attach match count
    if ($type === 'found') {
        $mc = $pdo->prepare("SELECT COUNT(*) FROM matches WHERE found_id = ?");
    } else {
        $mc = $pdo->prepare("SELECT COUNT(*) FROM matches WHERE lost_id = ?");
    }
    $mc->execute([$id]);
    $row['match_count'] = (int) $mc->fetchColumn();

    return $row;
}

/** Simple tokeniser — mirrors the one in matching_engine.php */
function tokenize(string $text): array
{
    static $stopWords = ['the','and','for','with','that','this','was','are','have','not',
        'but','from','they','been','their','what','when','which','your','found','lost',
        'item','report','left','think','near','inside','some','very','just','there',
        'can','will','would','it','my','a','an','is','in','on','at','to','of','or',
        'its','i','ang','mga','ng','sa','na','ay','ko','mo','ito','ako',
        'colors','color','traits','trait','keywords','keyword','exact','spot'];
    $clean  = preg_replace('/[|:,\/\\\\]+/', ' ', mb_strtolower($text));
    $clean  = preg_replace('/[^a-z0-9\s\-]/', ' ', $clean);
    $words  = preg_split('/\s+/', trim($clean), -1, PREG_SPLIT_NO_EMPTY);
    $tokens = array_filter($words, fn($w) => strlen($w) >= 3 && !in_array($w, $stopWords, true));
    return array_values(array_unique(array_values($tokens)));
}

/** Jaccard-style overlap score 0–100 between two text blobs. */
function textSimilarity(string $a, string $b): int
{
    $ta = tokenize($a);
    $tb = tokenize($b);
    if (empty($ta) || empty($tb)) return 0;
    $inter = count(array_intersect($ta, $tb));
    $union = count(array_unique(array_merge($ta, $tb)));
    return $union > 0 ? (int) round(($inter / $union) * 100) : 0;
}

// ── POST: execute merge ───────────────────────────────────────────────────
$merge_success = null;
$merge_error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['primary_ref'], $_POST['duplicate_ref'])) {

    $pRef  = parseRef($_POST['primary_ref']   ?? '');
    $dRef  = parseRef($_POST['duplicate_ref'] ?? '');
    $reason = trim($_POST['merge_reason'] ?? '');

    if (!$pRef || !$dRef) {
        $merge_error = 'Invalid tracking IDs supplied.';
    } elseif ($pRef['type'] !== $dRef['type']) {
        $merge_error = 'Both reports must be the same type (both Found or both Lost).';
    } elseif ($pRef['id'] === $dRef['id']) {
        $merge_error = 'Primary and duplicate cannot be the same record.';
    } else {
        $type = $pRef['type'];
        $pid  = $pRef['id'];
        $did  = $dRef['id'];

        // Verify both records exist
        $pRow = fetchReport($pdo, $type, $pid);
        $dRow = fetchReport($pdo, $type, $did);

        if (!$pRow || !$dRow) {
            $merge_error = 'One or both records could not be found.';
        } else {
            try {
                $pdo->beginTransaction();

                $imgs_moved    = 0;
                $matches_moved = 0;

                if ($type === 'found') {
                    // 1. Re-link images
                    $imgs_moved = (int) $pdo->prepare("
                        UPDATE item_images SET report_id = ?
                        WHERE report_type = 'found' AND report_id = ?
                    ")->execute([$pid, $did]) ? $pdo->rowCount() : 0;

                    // Re-execute to get actual row count (PDO::rowCount() after execute is fine)
                    $s = $pdo->prepare("UPDATE item_images SET report_id = ? WHERE report_type = 'found' AND report_id = ?");
                    // Already done above, count from a fresh query instead
                    $imgs_moved_stmt = $pdo->prepare("SELECT COUNT(*) FROM item_images WHERE report_type = 'found' AND report_id = ?");

                    // 2. Re-link matches (avoid duplicating existing found_id+lost_id pairs)
                    $dup_matches = $pdo->prepare("SELECT match_id, lost_id FROM matches WHERE found_id = ?")->execute([$did])
                        ? (function() use ($pdo, $did) {
                            $s = $pdo->prepare("SELECT match_id, lost_id FROM matches WHERE found_id = ?");
                            $s->execute([$did]);
                            return $s->fetchAll();
                          })()
                        : [];
                    $dup_matches_stmt = $pdo->prepare("SELECT match_id, lost_id FROM matches WHERE found_id = ?");
                    $dup_matches_stmt->execute([$did]);
                    $dup_matches = $dup_matches_stmt->fetchAll();

                    foreach ($dup_matches as $m) {
                        $exists = $pdo->prepare("SELECT 1 FROM matches WHERE found_id = ? AND lost_id = ? LIMIT 1");
                        $exists->execute([$pid, $m['lost_id']]);
                        if ($exists->fetchColumn()) {
                            // Delete duplicate match row
                            $pdo->prepare("DELETE FROM matches WHERE match_id = ?")->execute([$m['match_id']]);
                        } else {
                            $pdo->prepare("UPDATE matches SET found_id = ? WHERE match_id = ?")->execute([$pid, $m['match_id']]);
                            $matches_moved++;
                        }
                    }

                    // 3. Re-link inventory row if exists
                    $pdo->prepare("UPDATE inventory SET found_id = ? WHERE found_id = ?")->execute([$pid, $did]);

                    // 4. Soft-delete the duplicate
                    $pdo->prepare("UPDATE found_reports SET status = 'disposed' WHERE found_id = ?")->execute([$did]);

                    // Count images actually moved
                    $ic = $pdo->prepare("SELECT COUNT(*) FROM item_images WHERE report_type = 'found' AND report_id = ?");
                    $ic->execute([$pid]);

                } else { // lost
                    // 1. Re-link images
                    $pdo->prepare("UPDATE item_images SET report_id = ? WHERE report_type = 'lost' AND report_id = ?")->execute([$pid, $did]);

                    // 2. Re-link matches
                    $dup_matches_stmt = $pdo->prepare("SELECT match_id, found_id FROM matches WHERE lost_id = ?");
                    $dup_matches_stmt->execute([$did]);
                    $dup_matches = $dup_matches_stmt->fetchAll();

                    foreach ($dup_matches as $m) {
                        $exists = $pdo->prepare("SELECT 1 FROM matches WHERE lost_id = ? AND found_id = ? LIMIT 1");
                        $exists->execute([$pid, $m['found_id']]);
                        if ($exists->fetchColumn()) {
                            $pdo->prepare("DELETE FROM matches WHERE match_id = ?")->execute([$m['match_id']]);
                        } else {
                            $pdo->prepare("UPDATE matches SET lost_id = ? WHERE match_id = ?")->execute([$pid, $m['match_id']]);
                            $matches_moved++;
                        }
                    }

                    // 3. Soft-delete the duplicate
                    $pdo->prepare("UPDATE lost_reports SET status = 'closed' WHERE lost_id = ?")->execute([$did]);
                }

                // 5. Write merge log
                $pdo->prepare("
                    INSERT INTO merge_log
                        (report_type, primary_id, duplicate_id, merged_by, merge_reason, images_moved, matches_moved)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([$type, $pid, $did, $admin_id, $reason ?: null, $imgs_moved, $matches_moved]);

                $pdo->commit();

                $merge_success = [
                    'primary'   => strtoupper($type === 'found' ? 'FND' : 'LST') . '-' . str_pad($pid, 5, '0', STR_PAD_LEFT),
                    'duplicate' => strtoupper($type === 'found' ? 'FND' : 'LST') . '-' . str_pad($did, 5, '0', STR_PAD_LEFT),
                    'imgs'      => $imgs_moved,
                    'matches'   => $matches_moved,
                ];

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $merge_error = 'Database error: ' . $e->getMessage();
                error_log('[record_merger] ' . $e->getMessage());
            }
        }
    }
}

// ── GET: compare two specific reports ────────────────────────────────────
$report_a = null;
$report_b = null;
$compare_error = null;
$similarity = null;

$param_a = $_GET['a'] ?? '';
$param_b = $_GET['b'] ?? '';

if ($param_a && $param_b) {
    $refA = parseRef($param_a);
    $refB = parseRef($param_b);

    if (!$refA) { $compare_error = "Invalid reference: $param_a"; }
    elseif (!$refB) { $compare_error = "Invalid reference: $param_b"; }
    elseif ($refA['type'] !== $refB['type']) { $compare_error = 'Reports must be the same type.'; }
    else {
        $report_a = fetchReport($pdo, $refA['type'], $refA['id']);
        $report_b = fetchReport($pdo, $refB['type'], $refB['id']);
        if (!$report_a) $compare_error = "Record not found: $param_a";
        elseif (!$report_b) $compare_error = "Record not found: $param_b";
        else {
            $similarity = textSimilarity(
                $report_a['title'] . ' ' . $report_a['description'],
                $report_b['title'] . ' ' . $report_b['description']
            );
        }
    }
}

// ── Auto-detect duplicate candidates ─────────────────────────────────────
$candidates_found = [];
$candidates_lost  = [];

try {
    // Found duplicates: same category, title tokens overlap, both active
    $found_active = $pdo->query("
        SELECT f.found_id, f.title, f.private_description, f.category_id, f.status, f.created_at
        FROM found_reports f
        WHERE f.status NOT IN ('claimed','disposed','returned')
        ORDER BY f.created_at DESC
        LIMIT 100
    ")->fetchAll();

    for ($i = 0; $i < count($found_active); $i++) {
        for ($j = $i + 1; $j < count($found_active); $j++) {
            $a = $found_active[$i];
            $b = $found_active[$j];
            if ($a['category_id'] !== $b['category_id']) continue;
            $sim = textSimilarity($a['title'] . ' ' . $a['private_description'], $b['title'] . ' ' . $b['private_description']);
            if ($sim >= 40) {
                $candidates_found[] = [
                    'a_id'  => $a['found_id'],
                    'a_ref' => 'FND-' . str_pad($a['found_id'], 5, '0', STR_PAD_LEFT),
                    'a_ttl' => $a['title'],
                    'b_id'  => $b['found_id'],
                    'b_ref' => 'FND-' . str_pad($b['found_id'], 5, '0', STR_PAD_LEFT),
                    'b_ttl' => $b['title'],
                    'sim'   => $sim,
                ];
            }
        }
    }
    usort($candidates_found, fn($x, $y) => $y['sim'] <=> $x['sim']);
    $candidates_found = array_slice($candidates_found, 0, 15);

    // Lost duplicates: same logic
    $lost_active = $pdo->query("
        SELECT l.lost_id, l.title, l.private_description, l.category_id, l.status, l.created_at
        FROM lost_reports l
        WHERE l.status IN ('open','matched')
        ORDER BY l.created_at DESC
        LIMIT 100
    ")->fetchAll();

    for ($i = 0; $i < count($lost_active); $i++) {
        for ($j = $i + 1; $j < count($lost_active); $j++) {
            $a = $lost_active[$i];
            $b = $lost_active[$j];
            if ($a['category_id'] !== $b['category_id']) continue;
            $sim = textSimilarity($a['title'] . ' ' . $a['private_description'], $b['title'] . ' ' . $b['private_description']);
            if ($sim >= 40) {
                $candidates_lost[] = [
                    'a_id'  => $a['lost_id'],
                    'a_ref' => 'LST-' . str_pad($a['lost_id'], 5, '0', STR_PAD_LEFT),
                    'a_ttl' => $a['title'],
                    'b_id'  => $b['lost_id'],
                    'b_ref' => 'LST-' . str_pad($b['lost_id'], 5, '0', STR_PAD_LEFT),
                    'b_ttl' => $b['title'],
                    'sim'   => $sim,
                ];
            }
        }
    }
    usort($candidates_lost, fn($x, $y) => $y['sim'] <=> $x['sim']);
    $candidates_lost = array_slice($candidates_lost, 0, 15);

} catch (PDOException $e) {
    error_log('[record_merger] candidate scan: ' . $e->getMessage());
}

// ── Merge history (last 20) ───────────────────────────────────────────────
$merge_history = [];
try {
    $merge_history = $pdo->query("
        SELECT ml.*, u.full_name AS officer
        FROM merge_log ml
        LEFT JOIN users u ON u.user_id = ml.merged_by
        ORDER BY ml.merged_at DESC
        LIMIT 20
    ")->fetchAll();
} catch (PDOException) {}

// ── Similarity badge helper ───────────────────────────────────────────────
function simBadge(int $sim): string {
    if ($sim >= 70) return 'bg-red-100 text-red-700 border-red-200';
    if ($sim >= 50) return 'bg-amber-100 text-amber-700 border-amber-200';
    return 'bg-blue-100 text-blue-700 border-blue-200';
}
function simLabel(int $sim): string {
    if ($sim >= 70) return 'High';
    if ($sim >= 50) return 'Medium';
    return 'Low';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Merger | CMU Lost & Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/styles/root.css">
    <link rel="stylesheet" href="../assets/styles/admin_dashboard.css">
    <style>
        .diff-match   { background: #dcfce7; color: #166534; border-radius: 3px; padding: 0 3px; }
        .diff-missing { background: #fee2e2; color: #991b1b; border-radius: 3px; padding: 0 3px; text-decoration: line-through; }
        .card-primary   { border: 2px solid #003366; background: #EFF6FF; }
        .card-duplicate { border: 2px dashed #f97316; background: #fff7ed; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex">

<!-- ── Sidebar ───────────────────────────────────────────────────────────── -->
<aside class="w-64 bg-cmu-blue text-white flex-shrink-0 hidden lg:flex flex-col shadow-xl sticky top-0 h-screen">
    <div class="p-6 flex items-center gap-3 border-b border-white/10">
        <img src="../assets/images/system-icon.png" alt="Logo" class="w-10 h-10 bg-white rounded-lg p-1"
             onerror="this.src='https://ui-avatars.com/api/?name=OSA&background=fff&color=003366';">
        <div>
            <h1 class="font-bold text-sm leading-tight">OSA Admin</h1>
            <p class="text-[10px] text-blue-200 uppercase tracking-widest">Management Portal</p>
        </div>
    </div>
    <nav class="flex-grow p-4 space-y-2">
        <a href="dashboard.php"       class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition"><i class="fas fa-th-large w-5"></i><span class="text-sm font-medium">Dashboard Overview</span></a>
        <a href="inventory.php"       class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition"><i class="fas fa-boxes w-5"></i><span class="text-sm font-medium">Physical Inventory</span></a>
        <a href="qr_scanner.php"      class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition"><i class="fas fa-qrcode w-5"></i><span class="text-sm font-medium">QR Intake Scanner</span></a>
        <a href="matching_portal.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition"><i class="fas fa-sync w-5"></i><span class="text-sm font-medium">Matching Portal</span></a>
        <a href="claim_verify.php"    class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition"><i class="fas fa-user-check w-5"></i><span class="text-sm font-medium">Claim Verification</span></a>
        <div class="pt-4 mt-4 border-t border-white/10">
            <a href="archive.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition"><i class="fas fa-archive w-5 text-blue-300"></i><span class="text-sm font-medium text-blue-100">Records Archive</span></a>
            <a href="record_merger.php" class="sidebar-link active flex items-center gap-3 p-3 rounded-xl transition mt-1"><i class="fas fa-code-merge w-5 text-blue-300"></i><span class="text-sm font-medium text-blue-100">Record Merger</span></a>
        </div>
    </nav>
    <div class="p-4 border-t border-white/10">
        <div class="bg-white/5 rounded-2xl p-4">
            <p class="text-[10px] text-blue-300 uppercase font-bold mb-2">Logged in as</p>
            <p class="text-sm font-bold truncate"><?php echo $admin_name; ?></p>
            <a href="../core/logout.php" class="text-xs text-cmu-blue font-bold mt-2 py-2 px-4 inline-block rounded-md bg-cmu-gold hover:rounded-full hover:text-cmu-gold hover:bg-white">Logout Session</a>
        </div>
    </div>
</aside>

<!-- ── Main ──────────────────────────────────────────────────────────────── -->
<main class="flex-grow flex flex-col min-w-0 h-screen overflow-y-auto">

    <!-- Header -->
    <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between sticky top-0 z-10">
        <div>
            <h2 class="text-xl font-black text-slate-800 tracking-tight uppercase">Record Merger</h2>
            <p class="text-[10px] text-slate-500 font-bold uppercase tracking-widest">
                Detect and resolve duplicate found / lost reports
            </p>
        </div>
        <div class="flex items-center gap-4">
            <div class="hidden md:flex flex-col text-right">
                <span class="text-xs font-bold text-slate-400"><?php echo date('l, F j, Y'); ?></span>
                <span class="text-[10px] text-green-500 font-black uppercase"><i class="fas fa-circle text-[6px] mr-1"></i> System Online</span>
            </div>
            <div class="h-10 w-10 bg-slate-100 rounded-full flex items-center justify-center border border-slate-200">
                <i class="fas fa-user-shield text-cmu-blue"></i>
            </div>
        </div>
    </header>

    <div class="p-8 space-y-8">

        <!-- ── Success / Error banners ────────────────────────────────── -->
        <?php if ($merge_success): ?>
        <div class="bg-green-50 border border-green-200 rounded-2xl p-5 flex items-start gap-4">
            <div class="w-10 h-10 bg-green-500 text-white rounded-full flex items-center justify-center flex-shrink-0">
                <i class="fas fa-check"></i>
            </div>
            <div>
                <p class="font-black text-green-800 text-sm">Merge complete!</p>
                <p class="text-xs text-green-700 mt-1 leading-relaxed">
                    <strong><?php echo $merge_success['duplicate']; ?></strong> was merged into
                    <strong><?php echo $merge_success['primary']; ?></strong>.
                    <?php if ($merge_success['imgs'] > 0): ?>
                        <?php echo $merge_success['imgs']; ?> image(s) transferred.
                    <?php endif; ?>
                    <?php if ($merge_success['matches'] > 0): ?>
                        <?php echo $merge_success['matches']; ?> match(es) re-linked.
                    <?php endif; ?>
                    The duplicate has been soft-deleted and is no longer visible to users.
                </p>
            </div>
        </div>
        <?php elseif ($merge_error): ?>
        <div class="bg-red-50 border border-red-200 rounded-2xl p-4 flex items-center gap-3 text-red-700 text-sm font-semibold">
            <i class="fas fa-exclamation-triangle flex-shrink-0"></i>
            <?php echo htmlspecialchars($merge_error); ?>
        </div>
        <?php elseif ($compare_error): ?>
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 flex items-center gap-3 text-amber-700 text-sm font-semibold">
            <i class="fas fa-info-circle flex-shrink-0"></i>
            <?php echo htmlspecialchars($compare_error); ?>
        </div>
        <?php endif; ?>

        <!-- ── Manual lookup ──────────────────────────────────────────── -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Manual Comparison</p>
            <form method="GET" class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[160px]">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Report A</label>
                    <input type="text" name="a" placeholder="FND-00042 or LST-00007"
                           value="<?php echo htmlspecialchars($param_a); ?>"
                           class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-mono font-bold focus:ring-2 focus:ring-cmu-blue outline-none uppercase transition">
                </div>
                <div class="flex-1 min-w-[160px]">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Report B</label>
                    <input type="text" name="b" placeholder="FND-00043 or LST-00008"
                           value="<?php echo htmlspecialchars($param_b); ?>"
                           class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-mono font-bold focus:ring-2 focus:ring-cmu-blue outline-none uppercase transition">
                </div>
                <button type="submit"
                        class="px-6 py-3 bg-cmu-blue text-white rounded-xl font-bold text-sm hover:bg-slate-800 transition shadow-sm">
                    <i class="fas fa-arrows-alt-h mr-2"></i>Compare
                </button>
                <?php if ($param_a || $param_b): ?>
                <a href="record_merger.php" class="px-5 py-3 border border-slate-200 text-slate-500 rounded-xl font-bold text-xs hover:bg-slate-50 transition">
                    <i class="fas fa-times mr-1"></i>Clear
                </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- ── Side-by-side comparison ────────────────────────────────── -->
        <?php if ($report_a && $report_b): ?>
        <div class="space-y-4">

            <!-- Similarity score banner -->
            <?php
            $simBadgeClass = $similarity >= 70 ? 'bg-red-50 border-red-200 text-red-800' :
                            ($similarity >= 50 ? 'bg-amber-50 border-amber-200 text-amber-800'
                                               : 'bg-blue-50 border-blue-200 text-blue-800');
            $simIcon = $similarity >= 70 ? 'fa-exclamation-triangle text-red-500' :
                      ($similarity >= 50 ? 'fa-circle-info text-amber-500' : 'fa-circle-info text-blue-400');
            ?>
            <div class="border rounded-2xl p-5 flex items-center justify-between gap-4 <?php echo $simBadgeClass; ?>">
                <div class="flex items-center gap-3">
                    <i class="fas <?php echo $simIcon; ?> text-lg flex-shrink-0"></i>
                    <div>
                        <p class="font-black text-sm">Text Similarity: <?php echo $similarity; ?>%</p>
                        <p class="text-xs opacity-80 mt-0.5">
                            <?php if ($similarity >= 70): ?>
                                These reports are very likely duplicates. Review carefully before merging.
                            <?php elseif ($similarity >= 50): ?>
                                Moderate similarity — could be the same item. Compare details below.
                            <?php else: ?>
                                Low similarity — verify manually that these are actually duplicates.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="text-right flex-shrink-0">
                    <div class="h-3 w-32 bg-white/60 rounded-full overflow-hidden">
                        <div class="h-full rounded-full <?php echo $similarity >= 70 ? 'bg-red-500' : ($similarity >= 50 ? 'bg-amber-500' : 'bg-blue-400'); ?>"
                             style="width:<?php echo $similarity; ?>%"></div>
                    </div>
                </div>
            </div>

            <!-- Two-column comparison -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <?php foreach ([['a', $report_a], ['b', $report_b]] as [$key, $report]):
                    $isA = $key === 'a';
                    $cardClass = $isA ? 'card-primary' : 'card-duplicate';
                    $label     = $isA ? '🔵 Primary (Keep)' : '🟠 Duplicate (Remove)';
                ?>
                <div class="rounded-3xl overflow-hidden shadow-sm <?php echo $cardClass; ?>">

                    <!-- Card header -->
                    <div class="px-6 py-4 flex items-center justify-between <?php echo $isA ? 'bg-cmu-blue/5' : 'bg-orange-500/5'; ?>">
                        <div>
                            <span class="text-[10px] font-black uppercase tracking-widest <?php echo $isA ? 'text-cmu-blue' : 'text-orange-600'; ?>">
                                <?php echo $label; ?>
                            </span>
                            <p class="font-black text-slate-800 font-mono text-sm mt-0.5"><?php echo htmlspecialchars($report['tracking_id']); ?></p>
                        </div>
                        <span class="text-[10px] font-bold px-2.5 py-1 rounded-full border
                            <?php echo in_array($report['status'], ['in custody','open']) ? 'bg-amber-100 text-amber-700 border-amber-200' : 'bg-slate-100 text-slate-500 border-slate-200'; ?>">
                            <?php echo htmlspecialchars(ucwords($report['status'])); ?>
                        </span>
                    </div>

                    <!-- Card body -->
                    <div class="p-6 space-y-4 bg-white/70">

                        <div>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Title</p>
                            <p class="text-base font-bold text-slate-800"><?php echo htmlspecialchars($report['title']); ?></p>
                        </div>

                        <div class="grid grid-cols-2 gap-3 text-xs">
                            <div class="bg-slate-50 rounded-xl p-3">
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-0.5">Category</p>
                                <p class="font-bold text-slate-700"><?php echo htmlspecialchars($report['category']); ?></p>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-3">
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-0.5">Location</p>
                                <p class="font-bold text-slate-700"><?php echo htmlspecialchars($report['location']); ?></p>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-3">
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-0.5">Date</p>
                                <p class="font-bold text-slate-700"><?php echo !empty($report['date_event']) ? date('M d, Y', strtotime($report['date_event'])) : '—'; ?></p>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-3">
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-0.5">Reporter</p>
                                <p class="font-bold text-slate-700 truncate"><?php echo htmlspecialchars($report['reporter_name'] ?? '—'); ?></p>
                            </div>
                        </div>

                        <?php if (!empty($report['description'])): ?>
                        <div>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Private Notes</p>
                            <p class="text-xs text-slate-600 bg-amber-50 border border-amber-100 rounded-xl p-3 italic leading-relaxed">
                                <?php echo htmlspecialchars($report['description']); ?>
                            </p>
                        </div>
                        <?php endif; ?>

                        <!-- Stats row -->
                        <div class="flex gap-3 text-[11px]">
                            <span class="bg-indigo-50 text-indigo-700 border border-indigo-100 px-2.5 py-1 rounded-full font-bold">
                                <i class="fas fa-images mr-1"></i><?php echo count($report['images']); ?> image(s)
                            </span>
                            <span class="bg-green-50 text-green-700 border border-green-100 px-2.5 py-1 rounded-full font-bold">
                                <i class="fas fa-sync mr-1"></i><?php echo $report['match_count']; ?> match(es)
                            </span>
                            <span class="bg-slate-100 text-slate-500 border border-slate-200 px-2.5 py-1 rounded-full font-bold">
                                <?php echo date('M d', strtotime($report['created_at'])); ?>
                            </span>
                        </div>

                        <!-- Image thumbnails -->
                        <?php if (!empty($report['images'])): ?>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach (array_slice($report['images'], 0, 4) as $img): ?>
                            <div class="w-16 h-16 rounded-lg overflow-hidden border border-slate-100 bg-slate-50">
                                <img src="../<?php echo htmlspecialchars($img['image_path']); ?>"
                                     alt="Item photo"
                                     class="w-full h-full object-cover"
                                     onerror="this.parentElement.innerHTML='<div class=\'w-full h-full flex items-center justify-center text-slate-300\'><i class=\'fas fa-image\'></i></div>'">
                            </div>
                            <?php endforeach; ?>
                            <?php if (count($report['images']) > 4): ?>
                            <div class="w-16 h-16 rounded-lg bg-slate-100 flex items-center justify-center text-xs font-bold text-slate-400">
                                +<?php echo count($report['images']) - 4; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Merge form -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-8">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Execute Merge</p>
                <p class="text-xs text-slate-500 mb-6 leading-relaxed">
                    The <strong class="text-cmu-blue">Primary</strong> record is kept and enriched.
                    The <strong class="text-orange-600">Duplicate</strong> is soft-deleted — its images and matches move to the primary.
                    This action is logged and <u>cannot be automatically undone</u>.
                </p>

                <form method="POST" onsubmit="return confirmMerge()">
                    <input type="hidden" name="primary_ref"   value="<?php echo htmlspecialchars($report_a['tracking_id']); ?>">
                    <input type="hidden" name="duplicate_ref" value="<?php echo htmlspecialchars($report_b['tracking_id']); ?>">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                        <!-- Primary selector -->
                        <div class="p-4 border-2 border-cmu-blue rounded-2xl bg-blue-50/50">
                            <p class="text-[10px] font-black text-cmu-blue uppercase tracking-widest mb-1">Keep (Primary)</p>
                            <p class="font-black text-slate-800 font-mono"><?php echo htmlspecialchars($report_a['tracking_id']); ?></p>
                            <p class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($report_a['title']); ?></p>
                        </div>
                        <!-- Duplicate selector -->
                        <div class="p-4 border-2 border-dashed border-orange-400 rounded-2xl bg-orange-50/50">
                            <p class="text-[10px] font-black text-orange-600 uppercase tracking-widest mb-1">Remove (Duplicate)</p>
                            <p class="font-black text-slate-800 font-mono"><?php echo htmlspecialchars($report_b['tracking_id']); ?></p>
                            <p class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($report_b['title']); ?></p>
                        </div>
                    </div>

                    <!-- Swap button -->
                    <div class="flex justify-center mb-6">
                        <a href="?a=<?php echo urlencode($param_b); ?>&b=<?php echo urlencode($param_a); ?>"
                           class="flex items-center gap-2 px-4 py-2 border border-slate-200 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-50 transition">
                            <i class="fas fa-arrow-right-arrow-left"></i> Swap Primary / Duplicate
                        </a>
                    </div>

                    <div class="mb-6">
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Merge Reason (optional)</label>
                        <input type="text" name="merge_reason" placeholder="e.g. Same item reported twice by different finders"
                               class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-cmu-blue transition">
                    </div>

                    <div class="bg-amber-50 border border-amber-100 rounded-2xl p-4 flex gap-3 mb-6">
                        <i class="fas fa-triangle-exclamation text-amber-500 mt-0.5 flex-shrink-0"></i>
                        <div class="text-xs text-amber-800 leading-relaxed">
                            <p><strong>What will happen:</strong></p>
                            <p class="mt-1">
                                ① All images attached to <strong><?php echo htmlspecialchars($report_b['tracking_id']); ?></strong>
                                will be moved to <strong><?php echo htmlspecialchars($report_a['tracking_id']); ?></strong>.<br>
                                ② All matches referencing the duplicate will be re-linked to the primary.<br>
                                ③ The duplicate's status will be set to
                                <strong><?php echo $report_b['report_type'] === 'found' ? '"disposed"' : '"closed"'; ?></strong>
                                and it will disappear from active views.<br>
                                ④ A merge log entry will be created for audit purposes.
                            </p>
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <a href="record_merger.php" class="px-5 py-3 border border-slate-200 text-slate-600 rounded-xl font-bold text-sm hover:bg-slate-50 transition">
                            Cancel
                        </a>
                        <button type="submit"
                                class="flex-1 py-3 bg-red-600 text-white rounded-xl font-black text-sm hover:bg-red-700 transition shadow-sm flex items-center justify-center gap-2">
                            <i class="fas fa-code-merge"></i>
                            Confirm Merge — Remove Duplicate
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Auto-detected candidates ───────────────────────────────── -->
        <?php if (!$report_a || !$report_b): ?>
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-8">

            <!-- Found duplicates -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <p class="font-black text-slate-800 text-sm uppercase tracking-tight">
                            Found Report Candidates
                            <?php if (count($candidates_found) > 0): ?>
                            <span class="ml-2 bg-red-100 text-red-600 text-[10px] px-2 py-0.5 rounded-full"><?php echo count($candidates_found); ?></span>
                            <?php endif; ?>
                        </p>
                        <p class="text-[10px] text-slate-400 mt-0.5">Same category · overlapping keywords</p>
                    </div>
                    <i class="fas fa-hand-holding-heart text-green-400"></i>
                </div>
                <div class="divide-y divide-slate-50">
                    <?php if (empty($candidates_found)): ?>
                    <div class="py-12 text-center text-slate-400">
                        <i class="fas fa-check-circle text-3xl mb-3 block text-green-300"></i>
                        <p class="text-sm font-bold">No duplicate candidates found</p>
                        <p class="text-xs mt-1">All active found reports look distinct.</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($candidates_found as $c): ?>
                    <div class="p-4 hover:bg-slate-50 transition">
                        <div class="flex items-start justify-between gap-3 mb-2">
                            <div class="min-w-0">
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold border mb-1.5 <?php echo simBadge($c['sim']); ?>">
                                    <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                                    <?php echo simLabel($c['sim']); ?> similarity — <?php echo $c['sim']; ?>%
                                </span>
                                <p class="text-xs text-slate-700 font-bold truncate">
                                    <span class="font-mono text-indigo-600"><?php echo $c['a_ref']; ?></span>
                                    <span class="text-slate-400 mx-1">↔</span>
                                    <span class="font-mono text-indigo-600"><?php echo $c['b_ref']; ?></span>
                                </p>
                                <p class="text-[11px] text-slate-500 truncate mt-0.5">
                                    "<?php echo htmlspecialchars($c['a_ttl']); ?>"
                                    vs "<?php echo htmlspecialchars($c['b_ttl']); ?>"
                                </p>
                            </div>
                            <a href="?a=<?php echo urlencode($c['a_ref']); ?>&b=<?php echo urlencode($c['b_ref']); ?>"
                               class="flex-shrink-0 px-3 py-1.5 bg-cmu-blue text-white rounded-lg text-[10px] font-black uppercase hover:bg-slate-800 transition">
                                Compare
                            </a>
                        </div>
                        <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full rounded-full <?php echo $c['sim'] >= 70 ? 'bg-red-400' : ($c['sim'] >= 50 ? 'bg-amber-400' : 'bg-blue-400'); ?>"
                                 style="width:<?php echo $c['sim']; ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Lost duplicates -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <p class="font-black text-slate-800 text-sm uppercase tracking-tight">
                            Lost Report Candidates
                            <?php if (count($candidates_lost) > 0): ?>
                            <span class="ml-2 bg-amber-100 text-amber-600 text-[10px] px-2 py-0.5 rounded-full"><?php echo count($candidates_lost); ?></span>
                            <?php endif; ?>
                        </p>
                        <p class="text-[10px] text-slate-400 mt-0.5">Same category · overlapping keywords</p>
                    </div>
                    <i class="fas fa-search text-red-400"></i>
                </div>
                <div class="divide-y divide-slate-50">
                    <?php if (empty($candidates_lost)): ?>
                    <div class="py-12 text-center text-slate-400">
                        <i class="fas fa-check-circle text-3xl mb-3 block text-green-300"></i>
                        <p class="text-sm font-bold">No duplicate candidates found</p>
                        <p class="text-xs mt-1">All open lost reports look distinct.</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($candidates_lost as $c): ?>
                    <div class="p-4 hover:bg-slate-50 transition">
                        <div class="flex items-start justify-between gap-3 mb-2">
                            <div class="min-w-0">
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold border mb-1.5 <?php echo simBadge($c['sim']); ?>">
                                    <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                                    <?php echo simLabel($c['sim']); ?> similarity — <?php echo $c['sim']; ?>%
                                </span>
                                <p class="text-xs text-slate-700 font-bold truncate">
                                    <span class="font-mono text-indigo-600"><?php echo $c['a_ref']; ?></span>
                                    <span class="text-slate-400 mx-1">↔</span>
                                    <span class="font-mono text-indigo-600"><?php echo $c['b_ref']; ?></span>
                                </p>
                                <p class="text-[11px] text-slate-500 truncate mt-0.5">
                                    "<?php echo htmlspecialchars($c['a_ttl']); ?>"
                                    vs "<?php echo htmlspecialchars($c['b_ttl']); ?>"
                                </p>
                            </div>
                            <a href="?a=<?php echo urlencode($c['a_ref']); ?>&b=<?php echo urlencode($c['b_ref']); ?>"
                               class="flex-shrink-0 px-3 py-1.5 bg-cmu-blue text-white rounded-lg text-[10px] font-black uppercase hover:bg-slate-800 transition">
                                Compare
                            </a>
                        </div>
                        <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full rounded-full <?php echo $c['sim'] >= 70 ? 'bg-red-400' : ($c['sim'] >= 50 ? 'bg-amber-400' : 'bg-blue-400'); ?>"
                                 style="width:<?php echo $c['sim']; ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        <?php endif; ?>

        <!-- ── Merge history ──────────────────────────────────────────── -->
        <?php if (!empty($merge_history)): ?>
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <p class="font-black text-slate-800 text-sm uppercase tracking-tight">
                    Recent Merges
                    <span class="ml-2 bg-slate-100 text-slate-500 text-[10px] px-2 py-0.5 rounded-full"><?php echo count($merge_history); ?></span>
                </p>
                <i class="fas fa-history text-slate-400"></i>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs min-w-[700px]">
                    <thead class="bg-slate-50 border-b border-slate-100">
                        <tr>
                            <th class="px-6 py-3 font-black text-slate-400 uppercase tracking-widest text-[10px]">Type</th>
                            <th class="px-6 py-3 font-black text-slate-400 uppercase tracking-widest text-[10px]">Primary Kept</th>
                            <th class="px-6 py-3 font-black text-slate-400 uppercase tracking-widest text-[10px]">Duplicate Removed</th>
                            <th class="px-6 py-3 font-black text-slate-400 uppercase tracking-widest text-[10px]">Moved</th>
                            <th class="px-6 py-3 font-black text-slate-400 uppercase tracking-widest text-[10px]">Officer</th>
                            <th class="px-6 py-3 font-black text-slate-400 uppercase tracking-widest text-[10px]">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                    <?php foreach ($merge_history as $h):
                        $prefix = $h['report_type'] === 'found' ? 'FND' : 'LST';
                        $pRef   = $prefix . '-' . str_pad($h['primary_id'],   5, '0', STR_PAD_LEFT);
                        $dRef   = $prefix . '-' . str_pad($h['duplicate_id'], 5, '0', STR_PAD_LEFT);
                    ?>
                    <tr class="hover:bg-slate-50 transition">
                        <td class="px-6 py-3">
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?php echo $h['report_type'] === 'found' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-600'; ?>">
                                <?php echo strtoupper($h['report_type']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-3 font-mono font-bold text-indigo-600"><?php echo $pRef; ?></td>
                        <td class="px-6 py-3 font-mono text-slate-500 line-through"><?php echo $dRef; ?></td>
                        <td class="px-6 py-3 text-slate-500">
                            <?php if ($h['images_moved'] > 0 || $h['matches_moved'] > 0): ?>
                            <?php echo $h['images_moved']; ?> img · <?php echo $h['matches_moved']; ?> match
                            <?php else: ?>
                            <span class="text-slate-300">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-3 text-slate-600 font-semibold"><?php echo htmlspecialchars($h['officer'] ?? 'System'); ?></td>
                        <td class="px-6 py-3 text-slate-400"><?php echo date('M d, Y', strtotime($h['merged_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Help banner -->
        <div class="bg-slate-800 text-white rounded-3xl p-6 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-white/10 rounded-2xl flex items-center justify-center text-xl text-blue-300 flex-shrink-0">
                    <i class="fas fa-lightbulb"></i>
                </div>
                <div>
                    <p class="text-sm font-bold">When should I merge records?</p>
                    <p class="text-xs text-slate-400 mt-0.5 leading-relaxed">
                        Merge when the same physical item has been reported twice — typically by two different finders, or
                        when a user filed the same lost report more than once. Do <u>not</u> merge reports for genuinely different items
                        even if their descriptions overlap.
                    </p>
                </div>
            </div>
        </div>

    </div><!-- /.p-8 -->
</main>

<script>
function confirmMerge() {
    return confirm(
        'Are you sure you want to merge these records?\n\n' +
        'The duplicate will be soft-deleted and all its data will be moved to the primary. ' +
        'This action is logged and cannot be automatically reversed.'
    );
}
</script>

</body>
</html>