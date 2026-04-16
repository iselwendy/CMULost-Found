<?php
/**
 * CMU Lost & Found — Matching Portal
 *
 * FIX (image bug): The image subquery previously used a bare LIMIT 1 with no
 * GROUP BY, which returned only ONE image row for the entire query and shared
 * it across all matches. Fixed to use GROUP BY report_id so each found report
 * gets its own correct image.
 *
 * FIX (status sync): Lost reports are now updated to 'matched' by the matching
 * engine itself (matching_engine.php) whenever confidence >= 80.
 */

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../core/auth.php");
    exit();
}

require_once '../core/db_config.php';
require_once '../core/matching_engine.php';

// ── Active tab & selected match ───────────────────────────────────────────
$active_tab  = $_GET['tab']      ?? 'review';
$selected_id = $_GET['match_id'] ?? null;

// ── Review queue: pending matches with confidence < 90 ───────────────────
// FIX: Use LEFT JOIN on categories & locations — INNER JOIN silently drops
//      rows when category_id or location_id is NULL, emptying the queue.
$stmt_review = $pdo->prepare("
    SELECT
        m.match_id,
        CONCAT('FND-', LPAD(f.found_id, 5, '0')) AS found_tracking,
        COALESCE(c.name, 'Uncategorized')  AS category,
        m.confidence_score                 AS confidence,
        f.title                            AS found_title,
        COALESCE(loc_f.location_name, 'Unknown') AS found_location,
        f.date_found,
        f.private_description              AS found_notes,
        l.title                            AS lost_title,
        COALESCE(loc_l.location_name, 'Unknown') AS lost_location,
        l.date_lost,
        l.private_description              AS lost_description,
        u.full_name                        AS reporter_name,
        u.department                       AS reporter_dept,
        u.recovery_email                   AS email,
        img_f.image_path                   AS found_image_path,
        img_l.image_path                   AS lost_image_path
    FROM      matches      m
    JOIN      found_reports f    ON m.found_id    = f.found_id
    JOIN      lost_reports  l    ON m.lost_id     = l.lost_id
    LEFT JOIN categories    c    ON f.category_id = c.category_id
    JOIN      users         u    ON l.user_id     = u.user_id
    LEFT JOIN locations     loc_f ON f.location_id = loc_f.location_id
    LEFT JOIN locations     loc_l ON l.location_id = loc_l.location_id
    LEFT JOIN (
        SELECT   report_id, image_path
        FROM     item_images
        WHERE    report_type = 'found'
        GROUP BY report_id
    ) img_f ON img_f.report_id = f.found_id
    LEFT JOIN (
        SELECT   report_id, image_path
        FROM     item_images
        WHERE    report_type = 'lost'
        GROUP BY report_id
    ) img_l ON img_l.report_id = l.lost_id
    WHERE  m.status = 'pending'
      AND  m.confidence_score > 30
      AND  m.confidence_score < 80
    ORDER  BY m.confidence_score DESC
");
$stmt_review->execute();
$review_queue_raw = $stmt_review->fetchAll();

// ── Auto-notified queue: confirmed matches ≥ 80 ──────────────────────────
$stmt_auto = $pdo->prepare("
    SELECT
        m.match_id,
        CONCAT('FND-', LPAD(f.found_id, 5, '0')) AS found_tracking,
        COALESCE(c.name, 'Uncategorized')  AS category,
        m.confidence_score                 AS confidence,
        f.title                            AS found_title,
        l.title                            AS lost_title,
        u.full_name                        AS reporter_name,
        DATE_FORMAT(m.matched_at, '%b %d · %h:%i %p') AS notified_at
    FROM  matches      m
    JOIN  found_reports f ON m.found_id    = f.found_id
    JOIN  lost_reports  l ON m.lost_id     = l.lost_id
    LEFT JOIN categories c ON f.category_id = c.category_id
    JOIN  users         u ON l.user_id     = u.user_id
    WHERE (m.confidence_score >= 80 OR m.status = 'confirmed')
        AND m.status NOT IN ('rejected', 'completed')
        AND f.status != 'surrendered'
        AND l.status != 'resolved'
    ORDER  BY m.matched_at DESC
    LIMIT  20
");
$stmt_auto->execute();
$auto_queue = $stmt_auto->fetchAll();

// ── Today's rejected / confirmed counts (for stat cards) ─────────────────
$stmt_stats = $pdo->query("
    SELECT
        SUM(status = 'rejected'  AND DATE(matched_at) = CURDATE()) AS rejected,
        SUM(status = 'confirmed' AND DATE(matched_at) = CURDATE()) AS confirmed
    FROM matches
");
$day_stats = $stmt_stats->fetch();

$stats = [
    'review'    => count($review_queue_raw),
    'auto'      => count($auto_queue),
    'rejected'  => (int)($day_stats['rejected']  ?? 0),
    'confirmed' => (int)($day_stats['confirmed'] ?? 0),
];

// ── Build signals array for each review item ──────────────────────────────
function buildSignalsFromRow(array $row): array
{
    $keywordMatch = scoreKeywords(
        ($row['found_title'] ?? '') . ' ' . ($row['found_notes'] ?? ''),
        ($row['lost_title']  ?? '') . ' ' . ($row['lost_description'] ?? '')
    ) > 0;

    return [
        'Category match' => true,
        'Location match' => ($row['found_location'] === $row['lost_location']),
        'Keyword match'  => $keywordMatch,
        'Photo provided' => !empty($row['found_image_path']) || !empty($row['lost_image_path']),
    ];
}

$review_queue = array_map(function (array $row): array {
    $row['signals'] = buildSignalsFromRow($row);
    return $row;
}, $review_queue_raw);

// ── Resolve the selected match ─────────────────────────────────────────────
$selected = null;
if ($active_tab === 'review') {
    foreach ($review_queue as $item) {
        if ((string)$item['match_id'] === (string)$selected_id) {
            $selected = $item;
            break;
        }
    }
    if (!$selected && !empty($review_queue)) {
        $selected = $review_queue[0];
    }
}

// ── Colour helpers ────────────────────────────────────────────────────────
function confidenceClass(int $score): string {
    if ($score >= 80) return 'score-high';
    if ($score >= 65) return 'score-mid';
    return 'score-low';
}
function confidenceColor(int $score): string {
    if ($score >= 80) return '#639922';
    if ($score >= 65) return '#EF9F27';
    return '#888780';
}
function confidenceBarColor(int $score): string {
    if ($score >= 80) return '#639922';
    if ($score >= 65) return '#EF9F27';
    return '#B4B2A9';
}
function confidenceTextColor(int $score): string {
    if ($score >= 80) return '#3B6D11';
    if ($score >= 65) return '#854F0B';
    return '#5F5E5A';
}

// ── Image path helper ─────────────────────────────────────────────────────
// image_path is stored as e.g. "uploads/filename.jpg" — prepend "../" for
// admin pages one level below the project root.
function resolveImagePath(?string $path): ?string {
    if (empty($path)) return null;
    // Already absolute web path
    if (str_starts_with($path, '/') || str_starts_with($path, 'http')) return $path;
    return '../' . ltrim($path, '/');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Matching Portal | CMU Lost & Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/styles/root.css">
    <link rel="stylesheet" href="../assets/styles/admin_dashboard.css">
    <link rel="stylesheet" href="../assets/styles/matching_portal.css">
</head>
<body class="bg-slate-50 flex min-h-screen font-sans text-slate-800">

    <!-- ── Sidebar ─────────────────────────────────────────────────────── -->
    <aside class="w-64 bg-cmu-blue text-white flex-shrink-0 hidden lg:flex flex-col shadow-xl sticky top-0 h-screen">
        <div class="p-6 flex items-center gap-3 border-b border-white/10">
            <img src="../assets/images/system-icon.png" alt="Logo"
                 class="w-10 h-10 bg-white rounded-lg p-1"
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
            <a href="matching_portal.php" class="sidebar-link active flex items-center gap-3 p-3 rounded-xl transition"><i class="fas fa-sync w-5"></i><span class="text-sm font-medium">Matching Portal</span></a>
            <a href="claim_verify.php"    class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition"><i class="fas fa-user-check w-5"></i><span class="text-sm font-medium">Claim Verification</span></a>
            <div class="pt-4 mt-4 border-t border-white/10">
                <a href="archive.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition"><i class="fas fa-archive w-5 text-blue-300"></i><span class="text-sm font-medium text-blue-100">Records Archive</span></a>
                <a href="record_merger.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition mt-1">
                    <i class="fas fa-code-merge w-5 text-blue-300"></i>
                    <span class="text-sm font-medium text-blue-100">Record Merger</span>
                </a>
            </div>
        </nav>

        <div class="p-4 border-t border-white/10">
            <div class="bg-white/5 rounded-2xl p-4">
                <p class="text-[10px] text-blue-300 uppercase font-bold mb-2">Logged in as</p>
                <p class="text-sm font-bold truncate"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                <a href="../core/logout.php"
                   class="text-xs text-cmu-blue font-bold mt-2 py-2 px-4 inline-block rounded-md bg-cmu-gold hover:rounded-full hover:text-cmu-gold hover:bg-white">
                    Logout Session
                </a>
            </div>
        </div>
    </aside>

    <!-- ── Main ─────────────────────────────────────────────────────────── -->
    <main class="flex-grow flex flex-col min-w-0 overflow-hidden relative" style="height:150vh;">

        <!-- Page header -->
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between flex-shrink-0">
            <div>
                <h2 class="text-xl font-black text-slate-800 tracking-tight uppercase">AI Matching Portal</h2>
                <p class="text-xs text-slate-500 mt-0.5">
                    Matches ≥80% are auto-notified via SMS &nbsp;·&nbsp; Below 80% requires your review
                </p>
            </div>
            <div class="flex items-center gap-3">
                <button id="rerunBtn"
                        onclick="rerunMatchingEngine()"
                        class="flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-xs font-bold rounded-xl hover:bg-indigo-700 transition shadow-sm">
                    <i class="fas fa-rotate" id="rerunIcon"></i>
                    Re-run Matching Engine
                </button>
                <div class="hidden md:flex flex-col text-right">
                    <span class="text-xs font-bold text-slate-400"><?php echo date('l, F j, Y'); ?></span>
                    <span class="text-[10px] text-green-500 font-black uppercase">
                        <i class="fas fa-circle text-[6px] mr-1"></i> System Online
                    </span>
                </div>
                <div class="h-10 w-10 bg-slate-100 rounded-full flex items-center justify-center border border-slate-200">
                    <i class="fas fa-user-shield text-cmu-blue"></i>
                </div>
            </div>
        </header>

        <!-- Re-run result banner -->
        <div id="rerunBanner" class="hidden px-8 py-3 text-sm font-semibold flex items-center gap-3"></div>

        <!-- Summary stat cards -->
        <div class="px-8 pt-6 pb-2 grid grid-cols-2 md:grid-cols-4 gap-4 flex-shrink-0">
            <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Needs Review</p>
                <p class="text-2xl font-black text-slate-800" id="stat-review"><?php echo $stats['review']; ?></p>
            </div>
            <div class="bg-blue-50 rounded-2xl border border-blue-100 p-4">
                <p class="text-[10px] font-black text-blue-400 uppercase tracking-widest mb-1">Auto-Notified</p>
                <p class="text-2xl font-black text-blue-600"><?php echo $stats['auto']; ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Rejected Today</p>
                <p class="text-2xl font-black text-red-500"><?php echo $stats['rejected']; ?></p>
            </div>
            <div class="bg-green-50 rounded-2xl border border-green-100 p-4">
                <p class="text-[10px] font-black text-green-600 uppercase tracking-widest mb-1">Confirmed Today</p>
                <p class="text-2xl font-black text-green-700"><?php echo $stats['confirmed']; ?></p>
            </div>
        </div>

        <!-- Portal body -->
        <div class="flex flex-grow overflow-hidden mx-8 mb-6 mt-4 rounded-2xl border border-slate-200 shadow-sm bg-white">

            <!-- ── Queue panel ───────────────────────────────────────── -->
            <div class="queue-panel">

                <div style="padding:12px 12px 0;">
                    <p style="font-size:11px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px;">
                        Match Queue
                    </p>
                    <div class="queue-tabs">
                        <a href="?tab=review" class="qtab <?php echo $active_tab === 'review' ? 'active' : ''; ?>">
                            Review (<?php echo $stats['review']; ?>)
                        </a>
                        <a href="?tab=auto"   class="qtab <?php echo $active_tab === 'auto'   ? 'active' : ''; ?>">
                            Auto-sent (<?php echo $stats['auto']; ?>)
                        </a>
                    </div>
                </div>

                <div class="queue-list">

                    <?php if ($active_tab === 'review'): ?>

                        <?php if (empty($review_queue)): ?>
                            <div style="text-align:center;padding:32px 12px;color:#94a3b8;">
                                <i class="fas fa-check-circle" style="font-size:28px;margin-bottom:8px;display:block;color:#97C459;"></i>
                                <p style="font-size:12px;font-weight:700;">All caught up!</p>
                                <p style="font-size:11px;margin-top:4px;">No matches awaiting review.</p>
                                <p style="font-size:10px;margin-top:8px;color:#cbd5e1;">Try clicking "Re-run Matching Engine" above to check for new matches.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($review_queue as $qi): ?>
                                <a href="?tab=review&match_id=<?php echo urlencode($qi['match_id']); ?>"
                                   class="qitem <?php echo ($selected && (string)$selected['match_id'] === (string)$qi['match_id']) ? 'active' : ''; ?>">
                                    <div class="qitem-title"><?php echo htmlspecialchars($qi['found_title']); ?></div>
                                    <div class="qitem-meta">
                                        <?php echo htmlspecialchars($qi['found_tracking']); ?>
                                        &nbsp;·&nbsp;
                                        <?php echo htmlspecialchars($qi['category']); ?>
                                    </div>
                                    <div class="score-pill <?php echo confidenceClass((int)$qi['confidence']); ?>">
                                        <span style="width:7px;height:7px;border-radius:50%;display:inline-block;
                                                     background:<?php echo confidenceColor((int)$qi['confidence']); ?>;"></span>
                                        <?php echo $qi['confidence']; ?>% confidence
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>

                    <?php else: ?>

                        <div class="section-divider">Email sent automatically</div>

                        <?php if (empty($auto_queue)): ?>
                            <div style="text-align:center;padding:32px 12px;color:#94a3b8;">
                                <i class="fas fa-comment-sms" style="font-size:28px;margin-bottom:8px;display:block;"></i>
                                <p style="font-size:12px;font-weight:700;">No auto-notifications yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($auto_queue as $ai): ?>
                                <div class="qitem" style="cursor:default;opacity:.85;">
                                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                                        <div class="qitem-title" style="flex:1;padding-right:6px;">
                                            <?php echo htmlspecialchars($ai['found_title']); ?>
                                        </div>
                                        <span class="auto-sms-badge">Email sent</span>
                                    </div>
                                    <div class="qitem-meta">
                                        <?php echo htmlspecialchars($ai['found_tracking']); ?>
                                        &nbsp;·&nbsp;
                                        <?php echo htmlspecialchars($ai['category']); ?>
                                    </div>
                                    <div class="score-pill score-high">
                                        <span style="width:7px;height:7px;border-radius:50%;display:inline-block;background:#639922;"></span>
                                        <?php echo $ai['confidence']; ?>% confidence
                                    </div>
                                    <div class="qitem-meta" style="margin-top:4px;">
                                        <i class="fas fa-clock" style="font-size:9px;"></i>
                                        <?php echo htmlspecialchars($ai['notified_at']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                    <?php endif; ?>

                </div>
            </div><!-- end queue-panel -->

            <!-- ── Workspace ─────────────────────────────────────────── -->
            <div class="workspace">

                <?php if ($active_tab === 'review' && $selected): ?>
                    <?php
                    // FIX: resolve image path correctly for admin pages
                    $resolved_img = resolveImagePath($selected['found_image_path'] ?? null);
                    ?>

                    <!-- Workspace header -->
                    <div class="ws-header">
                        <div>
                            <p class="text-xs font-black text-slate-400 uppercase tracking-widest">
                                AI Match Review &nbsp;—&nbsp;
                                <?php echo htmlspecialchars((string)$selected['match_id']); ?>
                            </p>
                            <p class="text-sm font-bold text-slate-700 mt-0.5">
                                <?php echo htmlspecialchars($selected['found_title']); ?>
                                &nbsp;↔&nbsp;
                                Lost report by <?php echo htmlspecialchars($selected['reporter_name']); ?>
                            </p>
                        </div>
                        <div style="display:flex;align-items:center;gap:12px;">
                            <div style="text-align:right;">
                                <p style="font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;font-weight:700;">
                                    AI Confidence
                                </p>
                                <p style="font-size:18px;font-weight:900;color:<?php echo confidenceTextColor((int)$selected['confidence']); ?>;">
                                    <?php echo $selected['confidence']; ?>%
                                </p>
                            </div>
                            <div>
                                <div class="confidence-bar-outer">
                                    <div style="width:<?php echo (int)$selected['confidence']; ?>%;height:100%;
                                                border-radius:99px;
                                                background:<?php echo confidenceBarColor((int)$selected['confidence']); ?>;
                                                transition:width .4s ease;">
                                    </div>
                                </div>
                                <p style="font-size:10px;color:#94a3b8;margin-top:4px;text-align:center;">
                                    Below 80% — review required
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Split comparison -->
                    <div class="split-view">

                        <!-- Pane A: Found item -->
                        <div class="pane">
                            <span class="pane-label label-inventory">Active Found Items</span>

                            <div>
                                <p class="pane-title"><?php echo htmlspecialchars($selected['found_title']); ?></p>
                                <p class="pane-sub">
                                    Found &nbsp;·&nbsp;
                                    <?php echo htmlspecialchars($selected['found_location']); ?>
                                    &nbsp;·&nbsp;
                                    <?php echo htmlspecialchars($selected['date_found']); ?>
                                </p>
                            </div>

                            <?php if ($resolved_img): ?>
                                <img src="<?php echo htmlspecialchars($resolved_img); ?>"
                                     alt="Item photo" class="item-photo"
                                     onerror="this.parentElement.innerHTML='<div class=\'item-placeholder\'><i class=\'fas fa-image\' style=\'font-size:22px;\'></i><span>No photo provided</span></div>'">
                            <?php else: ?>
                                <div class="item-placeholder">
                                    <i class="fas fa-image" style="font-size:22px;"></i>
                                    <span>No photo provided</span>
                                </div>
                            <?php endif; ?>

                            <div class="field-group">
                                <p class="field-label">Finder's notes (confidential)</p>
                                <p class="field-val"><?php echo htmlspecialchars($selected['found_notes'] ?: '—'); ?></p>
                            </div>

                            <div class="field-group">
                                <p class="field-label">Category</p>
                                <p class="field-val"><?php echo htmlspecialchars($selected['category']); ?></p>
                            </div>
                        </div><!-- end pane A -->

                        <!-- Pane B: Lost report -->
                        <div class="pane pane-b">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                                <span class="pane-label label-lost">Lost Report</span>
                                <span class="score-pill <?php echo confidenceClass((int)$selected['confidence']); ?>"
                                      style="margin-top:0;flex-shrink:0;">
                                    <span style="width:7px;height:7px;border-radius:50%;display:inline-block;
                                                 background:<?php echo confidenceColor((int)$selected['confidence']); ?>;"></span>
                                    <?php echo $selected['confidence']; ?>% match
                                </span>
                            </div>

                            <div>
                                <p class="pane-title"><?php echo htmlspecialchars($selected['lost_title']); ?></p>
                                <p class="pane-sub">
                                    Lost &nbsp;·&nbsp;
                                    <?php echo htmlspecialchars($selected['lost_location']); ?>
                                    &nbsp;·&nbsp;
                                    <?php echo htmlspecialchars($selected['date_lost']); ?>
                                </p>
                            </div>

                            <?php 
                                // Example for the LOST item side
                                $lost_img = !empty($selected['lost_image_path']) ? '../' . $selected['lost_image_path'] : null;
                            ?>

                            <?php if ($lost_img): ?>
                                <img src="<?php echo htmlspecialchars($lost_img); ?>" 
                                    alt="Lost Item photo" class="item-photo">
                            <?php else: ?>
                                <div class="item-placeholder">
                                    <i class="fas fa-image" style="font-size:22px;"></i>
                                    <span>No photo provided</span>
                                </div>
                            <?php endif; ?>

                            <div class="field-group">
                                <p class="field-label">Claimant's description</p>
                                <p class="field-val"><?php echo htmlspecialchars($selected['lost_description'] ?: '—'); ?></p>
                            </div>

                            <div class="field-group">
                                <p class="field-label">AI signal breakdown</p>
                                <div class="signal-row">
                                    <?php foreach ($selected['signals'] as $label => $hit): ?>
                                        <span class="signal-chip <?php echo $hit ? 'chip-yes' : 'chip-no'; ?>">
                                            <?php if ($hit): ?>
                                                <i class="fas fa-check" style="font-size:9px;margin-right:3px;"></i>
                                            <?php else: ?>
                                                <i class="fas fa-times" style="font-size:9px;margin-right:3px;"></i>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($label); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="field-group">
                                <p class="field-label">Claimant</p>
                                <div class="field-val" style="display:flex;align-items:center;gap:10px;">
                                    <div style="width:34px;height:34px;border-radius:50%;background:#EFF6FF;
                                                display:flex;align-items:center;justify-content:center;
                                                color:#1D4ED8;font-weight:900;font-size:13px;flex-shrink:0;">
                                        <?php echo strtoupper(mb_substr($selected['reporter_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <p style="font-size:13px;font-weight:700;color:#0f172a;margin:0;">
                                            <?php echo htmlspecialchars($selected['reporter_name']); ?>
                                        </p>
                                        <p style="font-size:11px;color:#64748b;margin:0;">
                                            <?php echo htmlspecialchars($selected['reporter_dept'] ?? '—'); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div><!-- end pane B -->

                    </div><!-- end split-view -->

                    <!-- Action bar -->
                    <?php
                    $modal_payload = json_encode([
                        'name'    => $selected['reporter_name'] ?? '',
                        'email'   => $selected['email'] ?? 'N/A',
                        'item'    => $selected['found_title'] ?? '',
                        'matchId' => (string)$selected['match_id'],
                    ], JSON_HEX_QUOT | JSON_HEX_APOS | JSON_HEX_TAG);
                    ?>
                    <div class="action-bar">
                        <button class="btn-reject"
                                id="rejectBtn"
                                data-match-id="<?php echo htmlspecialchars((string)$selected['match_id']); ?>">
                            <i class="fas fa-times" style="margin-right:6px;"></i>Reject Match
                        </button>
                        <button class="btn-skip" onclick="skipMatch()">Skip for Now</button>
                        <button class="btn-confirm"
                                id="confirmBtn"
                                data-modal='<?php echo htmlspecialchars($modal_payload, ENT_QUOTES, 'UTF-8'); ?>'>
                            <i class="fas fa-comment-sms"></i>
                            Confirm &amp; Send Email to Owner
                        </button>
                        <div class="auto-note">
                            <span style="width:8px;height:8px;border-radius:50%;background:#639922;display:inline-block;"></span>
                            Matches ≥80% skip this step and notify automatically
                        </div>
                    </div>

                <?php elseif ($active_tab === 'auto'): ?>

                    <div style="padding:32px;flex:1;overflow-y:auto;">
                        <div style="max-width:620px;">
                            <p class="text-xs font-black text-slate-400 uppercase tracking-widest mb-1">
                                Auto-Notified Matches
                            </p>
                            <p class="text-sm text-slate-500 mb-6">
                                These matches scored ≥80% confidence. Email notifications were sent automatically.
                                No action is needed unless a match must be manually overridden.
                            </p>

                            <?php foreach ($auto_queue as $ai): ?>
                                <div class="auto-card" style="margin-bottom:12px;">
                                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                                        <div style="flex:1;">
                                            <p style="font-size:14px;font-weight:900;color:#0f172a;margin:0;">
                                                <?php echo htmlspecialchars($ai['found_title']); ?>
                                                <span style="color:#94a3b8;font-weight:400;font-size:12px;">
                                                    ↔ <?php echo htmlspecialchars($ai['lost_title']); ?>
                                                </span>
                                            </p>
                                            <p style="font-size:11px;color:#94a3b8;margin:4px 0 0;">
                                                <?php echo htmlspecialchars($ai['found_tracking']); ?>
                                                &nbsp;·&nbsp; Notified: <?php echo htmlspecialchars($ai['reporter_name']); ?>
                                                &nbsp;·&nbsp; <?php echo htmlspecialchars($ai['notified_at']); ?>
                                            </p>
                                        </div>
                                        <div class="score-pill score-high" style="margin-top:0;flex-shrink:0;">
                                            <span style="width:7px;height:7px;border-radius:50%;display:inline-block;background:#639922;"></span>
                                            <?php echo $ai['confidence']; ?>%
                                        </div>
                                    </div>
                                    <div style="display:flex;gap:8px;margin-top:10px;">
                                        <span style="font-size:11px;font-weight:700;background:#E6F1FB;color:#185FA5;padding:2px 10px;border-radius:99px;">
                                            <i class="fas fa-check" style="font-size:9px;margin-right:4px;"></i>Email sent
                                        </span>
                                        <button onclick="overrideMatch(<?php echo json_encode((string)$ai['match_id']); ?>)"
                                                style="font-size:11px;font-weight:700;background:none;
                                                       border:1px solid #FCA5A5;color:#B91C1C;
                                                       padding:2px 10px;border-radius:99px;cursor:pointer;">
                                            Override &amp; Reject
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-mouse-pointer" style="font-size:36px;color:#cbd5e1;"></i>
                        <p style="font-size:14px;font-weight:700;color:#334155;">Select a match to begin</p>
                        <p style="font-size:12px;">Choose an item from the queue on the left.</p>
                    </div>
                <?php endif; ?>

            </div><!-- end workspace -->
        </div><!-- end portal body -->

        <!-- ── Confirm Modal ────────────────────────────────────────────── -->
        <div id="confirmModal"
             style="display:none;position:absolute;inset:0;
                    background:rgba(15,23,42,.55);z-index:60;
                    align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:24px;max-width:420px;width:100%;
                        padding:32px;margin:16px;box-shadow:0 20px 40px rgba(0,0,0,.15);">
                <div style="width:56px;height:56px;background:#EAF3DE;border-radius:50%;
                             display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <i class="fas fa-comment-sms" style="font-size:22px;color:#3B6D11;"></i>
                </div>
                <h3 style="font-size:18px;font-weight:900;color:#0f172a;text-align:center;margin-bottom:6px;">
                    Confirm Match &amp; Notify Owner
                </h3>
                <p style="font-size:13px;color:#64748b;text-align:center;margin-bottom:20px;">
                    An email will be sent to <strong id="modal-name">—</strong>
                    at <strong id="modal-email">—</strong>.
                </p>
                <div style="background:#f8fafc;border-radius:12px;padding:14px 16px;
                             border:1px solid #e2e8f0;margin-bottom:20px;font-size:12px;
                             color:#475569;line-height:1.6;">
                    <strong style="display:block;font-size:10px;text-transform:uppercase;
                                   letter-spacing:.07em;color:#94a3b8;margin-bottom:6px;">
                        Email Preview
                    </strong>
                    "Hi <span id="modal-name-sms">—</span>, a potential match for your lost
                    <strong id="modal-item">—</strong> may be at the OSA.
                    Please visit the Office of Student Affairs with a valid ID to verify and claim your item."
                </div>
                <div style="display:flex;gap:10px;">
                    <button onclick="closeConfirmModal()"
                            style="flex:1;padding:12px;border-radius:12px;border:1px solid #e2e8f0;
                                   background:none;font-size:13px;font-weight:700;cursor:pointer;color:#64748b;">
                        Cancel
                    </button>
                    <button onclick="submitConfirm()"
                            style="flex:1;padding:12px;border-radius:12px;border:none;
                                   background:#003366;color:#fff;font-size:13px;font-weight:700;cursor:pointer;">
                        Send Email &amp; Confirm
                    </button>
                </div>
            </div>
        </div>

    </main>

    <script>
        let pendingMatchId = null;

        document.addEventListener('DOMContentLoaded', function () {
            const rejectBtn = document.getElementById('rejectBtn');
            if (rejectBtn) {
                rejectBtn.addEventListener('click', function () {
                    rejectMatch(this.dataset.matchId);
                });
            }

            const confirmBtn = document.getElementById('confirmBtn');
            if (confirmBtn) {
                confirmBtn.addEventListener('click', function () {
                    const payload = JSON.parse(this.dataset.modal);
                    openConfirmModal(payload.name, payload.email, payload.item, payload.matchId);
                });
            }
        });

        function openConfirmModal(name, email, item, matchId) {
            pendingMatchId = matchId;
            document.getElementById('modal-name').textContent     = name;
            document.getElementById('modal-name-sms').textContent = name;
            document.getElementById('modal-email').textContent    = email || 'N/A';
            document.getElementById('modal-item').textContent     = item;
            document.getElementById('confirmModal').style.display = 'flex';
        }

        function closeConfirmModal() {
            document.getElementById('confirmModal').style.display = 'none';
            pendingMatchId = null;
        }

        function submitConfirm() {
            if (!pendingMatchId) return;
            fetch('../core/process_match.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ match_id: pendingMatchId, action: 'confirm' })
            })
            .then(r => r.text().then(text => {
                try { return JSON.parse(text); } catch (e) { throw new Error('Non-JSON: ' + text); }
            }))
            .then(data => {
                if (data.success) {
                    window.location.href = 'matching_portal.php?tab=review';
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(err => alert('Error: ' + err.message));
        }

        function rejectMatch(matchId) {
            if (!confirm('Reject this match? The items will remain available for future matching.')) return;
            fetch('../core/process_match.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ match_id: matchId, action: 'reject' })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) window.location.href = 'matching_portal.php?tab=review';
                else alert('Error: ' + data.message);
            })
            .catch(() => alert('Network error. Please try again.'));
        }

        function overrideMatch(matchId) {
            if (!confirm('Override and reject this auto-notified match? This cannot be undone.')) return;
            fetch('../core/process_match.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ match_id: matchId, action: 'override_reject' })
            })
            .then(r => r.json())
            .then(() => window.location.reload())
            .catch(() => alert('Network error. Please try again.'));
        }

        function skipMatch() {
            const items = document.querySelectorAll('.qitem.active');
            if (items.length) {
                const next = items[0].closest('a')?.nextElementSibling;
                if (next && next.href) { window.location.href = next.href; return; }
            }
            window.location.href = 'matching_portal.php?tab=review';
        }

        document.getElementById('confirmModal').addEventListener('click', function (e) {
            if (e.target === this) closeConfirmModal();
        });

        async function rerunMatchingEngine() {
            const btn    = document.getElementById('rerunBtn');
            const banner = document.getElementById('rerunBanner');
            const originalHTML = btn.innerHTML;

            btn.disabled = true;
            btn.classList.add('opacity-70', 'cursor-not-allowed');
            btn.innerHTML = '<i class="fas fa-rotate fa-spin mr-2"></i> Running engine…';

            try {
                const res  = await fetch('../core/rerun_matching.php', { method: 'POST' });
                const data = await res.json();

                if (data.success) {
                    banner.className = 'px-8 py-3 text-sm font-semibold flex items-center gap-3 bg-green-50 border-b border-green-100 text-green-800';
                    banner.innerHTML = `
                        <i class="fas fa-check-circle text-green-500"></i>
                        Matching engine completed —
                        <strong>${data.found_scanned}</strong> reports scanned,
                        <strong>${data.total_matches}</strong> matches in DB,
                        <strong>${data.confirmed}</strong> auto-confirmed.
                        ${data.new_matches > 0
                            ? '<a href="?tab=review" class="ml-2 underline font-bold text-green-700">View new matches →</a>'
                            : ''}
                    `;
                    banner.classList.remove('hidden');
                    setTimeout(() => window.location.href = 'matching_portal.php?tab=review', 2200);
                } else {
                    banner.className = 'px-8 py-3 text-sm font-semibold flex items-center gap-3 bg-red-50 border-b border-red-100 text-red-700';
                    banner.innerHTML = `<i class="fas fa-exclamation-circle text-red-500"></i> ${data.message || 'An error occurred.'}`;
                    banner.classList.remove('hidden');
                    btn.disabled = false;
                    btn.innerHTML = originalHTML;
                    btn.classList.remove('opacity-70', 'cursor-not-allowed');
                }
            } catch (err) {
                banner.className = 'px-8 py-3 text-sm font-semibold flex items-center gap-3 bg-red-50 border-b border-red-100 text-red-700';
                banner.innerHTML = '<i class="fas fa-wifi text-red-400"></i> Network error — could not reach the server.';
                banner.classList.remove('hidden');
                btn.disabled = false;
                btn.innerHTML = originalHTML;
                btn.classList.remove('opacity-70', 'cursor-not-allowed');
            }
        }
    </script>


</body>
</html>