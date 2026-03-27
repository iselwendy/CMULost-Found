<?php
/**
 * My Reports - CMU Lost & Found
 * Fetches and displays user-specific lost and found reports.
 */
require_once '../core/db_config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../core/auth.php");
    exit();
}

$user_id = $_SESSION['user_id'];

try {
    // 1. Fetch Summary Stats
    $stmt_stats = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM lost_reports WHERE user_id = ? AND status != 'closed') +
            (SELECT COUNT(*) FROM found_reports WHERE reported_by = ? AND status != 'claimed') as active_count,
            (SELECT COUNT(*) FROM matches m 
             JOIN lost_reports lr ON m.lost_id = lr.lost_id 
             WHERE lr.user_id = ? AND m.status = 'pending') as match_count
    ");
    $stmt_stats->execute([$user_id, $user_id, $user_id]);
    $stats = $stmt_stats->fetch();

    // 2. Fetch "My Reports" (Combined Lost and Found)
    $stmt_reports = $pdo->prepare("
        (SELECT 
            lr.lost_id as id, 'lost' as type, lr.title, lr.private_description, 
            l.location_name, lr.status, lr.date_lost as date, lr.created_at,
            c.name as category_name,
            img.image_path
        FROM lost_reports lr
        JOIN locations l ON lr.location_id = l.location_id
        LEFT JOIN categories c ON lr.category_id = c.category_id
        LEFT JOIN (
            SELECT report_id, image_path, report_type 
            FROM item_images 
            WHERE (report_id, image_id) IN (
                SELECT report_id, MIN(image_id) 
                FROM item_images WHERE report_type = 'lost' GROUP BY report_id
            )
        ) img ON lr.lost_id = img.report_id
        WHERE lr.user_id = ?)

        UNION ALL

        (SELECT 
            fr.found_id as id, 'found' as type, fr.title, fr.private_description,
            l.location_name, fr.status, fr.date_found as date, fr.created_at,
            c.name as category_name,
            img.image_path
        FROM found_reports fr
        JOIN locations l ON fr.location_id = l.location_id
        LEFT JOIN categories c ON fr.category_id = c.category_id
        LEFT JOIN (
            SELECT report_id, image_path, report_type 
            FROM item_images 
            WHERE (report_id, image_id) IN (
                SELECT report_id, MIN(image_id) 
                FROM item_images WHERE report_type = 'found' GROUP BY report_id
            )
        ) img ON fr.found_id = img.report_id
        WHERE fr.reported_by = ?)
        
        ORDER BY created_at DESC
    ");
    $stmt_reports->execute([$user_id, $user_id]);
    $my_reports = $stmt_reports->fetchAll();

    // 3. Fetch Potential Matches
    $stmt_matches = $pdo->prepare("
        SELECT 
            m.match_id, fr.title as found_item, fr.date_found, loc.location_name, 
            lr.title as my_item, m.status as match_status,
            img.image_path
        FROM matches m
        JOIN lost_reports lr ON m.lost_id = lr.lost_id
        JOIN found_reports fr ON m.found_id = fr.found_id
        JOIN locations loc ON fr.location_id = loc.location_id
        LEFT JOIN (
            SELECT report_id, image_path 
            FROM item_images 
            WHERE report_type = 'found' 
            AND image_id IN (SELECT MIN(image_id) FROM item_images WHERE report_type = 'found' GROUP BY report_id)
        ) img ON fr.found_id = img.report_id
        WHERE lr.user_id = ? AND m.status != 'rejected'
    ");
    $stmt_matches->execute([$user_id]);
    $potential_matches = $stmt_matches->fetchAll();

} catch (PDOException $e) {
    $my_reports = [];
    $potential_matches = [];
    $stats = ['active_count' => 0, 'match_count' => 0];
    $db_error = $e->getMessage();
}

/**
 * Helper to get progress percentage and step label
 */
function getProgress($status, $type) {
    if ($type === 'found') {
        switch ($status) {
            case 'in custody':   return ['pct' => '33%', 'step' => 'Step 1 of 3', 'label' => 'In Finder\'s Possession', 'color' => 'bg-amber-400'];
            case 'surrendered':  return ['pct' => '66%', 'step' => 'Step 2 of 3', 'label' => 'Surrendered to SAO',     'color' => 'bg-blue-500'];
            case 'matched':      return ['pct' => '100%','step' => 'Step 3 of 3', 'label' => 'Match Identified',       'color' => 'bg-indigo-500'];
            case 'claimed':      return ['pct' => '100%','step' => 'Completed',   'label' => 'Claimed by Owner',       'color' => 'bg-green-500'];
            case 'disposed':     return ['pct' => '100%','step' => 'Archived',    'label' => 'Item Disposed',          'color' => 'bg-slate-400'];
            default:             return ['pct' => '10%', 'step' => 'Processing',  'label' => ucfirst($status),         'color' => 'bg-slate-300'];
        }
    } else {
        switch ($status) {
            case 'open':         return ['pct' => '33%', 'step' => 'Step 1 of 3', 'label' => 'Active Search',         'color' => 'bg-blue-500'];
            case 'matched':      return ['pct' => '66%', 'step' => 'Step 2 of 3', 'label' => 'Potential Match Found', 'color' => 'bg-indigo-500'];
            case 'resolved':     return ['pct' => '100%','step' => 'Step 3 of 3', 'label' => 'Item Recovered',        'color' => 'bg-green-500'];
            case 'closed':       return ['pct' => '100%','step' => 'Completed',   'label' => 'Case Closed',           'color' => 'bg-slate-400'];
            default:             return ['pct' => '10%', 'step' => 'Reported',    'label' => ucfirst($status),         'color' => 'bg-slate-300'];
        }
    }
}

/**
 * Helper to get human-readable status badge class
 */
function getStatusBadgeClass($status) {
    $map = [
        'in custody'   => 'bg-amber-100 text-amber-700 border-amber-200',
        'surrendered'  => 'bg-blue-100 text-blue-700 border-blue-200',
        'matched'      => 'bg-indigo-100 text-indigo-700 border-indigo-200',
        'claimed'      => 'bg-green-100 text-green-700 border-green-200',
        'disposed'     => 'bg-slate-100 text-slate-500 border-slate-200',
        'open'         => 'bg-blue-100 text-blue-700 border-blue-200',
        'resolved'     => 'bg-green-100 text-green-700 border-green-200',
        'closed'       => 'bg-slate-100 text-slate-500 border-slate-200',
    ];
    return $map[$status] ?? 'bg-slate-100 text-slate-500 border-slate-200';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reports | CMU Lost & Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/styles/header.css">
    <link rel="stylesheet" href="../assets/styles/my_reports.css">
    <link rel="stylesheet" href="../assets/styles/root.css">
</head>
<body class="bg-slate-50 min-h-screen text-slate-900">

    <?php require_once '../includes/header.php'; ?>

    <main class="max-w-6xl mx-auto px-4 py-8">

        <!-- DB Error Banner -->
        <?php if (isset($db_error)): ?>
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl text-red-700 text-sm flex gap-3">
            <i class="fas fa-exclamation-triangle mt-0.5"></i>
            <span>Database error: <?php echo htmlspecialchars($db_error); ?></span>
        </div>
        <?php endif; ?>

        <!-- Dashboard Header -->
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-8">
            <div>
                <h1 class="text-3xl font-extrabold text-slate-800 tracking-tight">Personal Dashboard</h1>
                <p class="text-slate-500">Track your reports, view matches, and manage turnovers.</p>
            </div>
            <div class="flex gap-2">
                <a href="../public/report_lost.php" class="px-4 py-2 bg-white border border-slate-200 rounded-lg text-sm font-bold text-slate-700 hover:bg-slate-50 transition shadow-sm">
                    <i class="fas fa-plus mr-2 text-indigo-500"></i>New Lost Report
                </a>
                <a href="../public/report_found.php" class="px-4 py-2 bg-cmu-blue text-white rounded-lg text-sm font-bold hover:bg-slate-800 transition shadow-md">
                    <i class="fas fa-hand-holding-heart mr-2 text-cmu-gold"></i>I Found Something
                </a>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-10">
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 flex items-center gap-5">
                <div class="w-14 h-14 bg-blue-50 text-cmu-blue rounded-2xl flex items-center justify-center text-2xl">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div>
                    <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">Active Reports</p>
                    <h3 class="text-2xl font-black text-slate-800"><?php echo sprintf("%02d", $stats['active_count'] ?? 0); ?></h3>
                </div>
            </div>

            <div class="bg-indigo-600 p-6 rounded-2xl shadow-lg shadow-indigo-100 flex items-center gap-5 text-white relative overflow-hidden">
                <div class="w-14 h-14 bg-white/20 text-white rounded-2xl flex items-center justify-center text-2xl">
                    <i class="fas fa-bolt-lightning"></i>
                </div>
                <div class="z-10">
                    <p class="text-xs font-bold text-indigo-200 uppercase tracking-widest">Potential Matches</p>
                    <h3 class="text-2xl font-black"><?php echo sprintf("%02d", $stats['match_count'] ?? 0); ?> Found</h3>
                </div>
                <i class="fas fa-magnifying-glass absolute -right-4 -bottom-4 text-white/10 text-8xl"></i>
            </div>

            <?php
                $pending_found_items = [];
                foreach ($my_reports as $r) {
                    if ($r['type'] === 'found' && in_array($r['status'], ['in custody', 'surrendered'])) {
                        $pending_found_items[] = [
                            'title'       => $r['title'],
                            'tracking_id' => 'FND-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT),
                        ];
                    }
                }
                $pending_count = count($pending_found_items);
                ?>

            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 relative">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-5">
                        <div class="w-14 h-14 bg-amber-50 text-amber-600 rounded-2xl flex items-center justify-center text-2xl flex-shrink-0">
                            <i class="fas fa-qrcode"></i>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">Turnover QR</p>
                            <h3 class="text-sm font-bold text-slate-800">
                                <?php if ($pending_count === 0): ?>
                                    No Pending Items
                                <?php elseif ($pending_count === 1): ?>
                                    1 Pending Surrender
                                <?php else: ?>
                                    <?php echo $pending_count; ?> Pending Surrenders
                                <?php endif; ?>
                            </h3>
                        </div>
                    </div>

                    <?php if ($pending_count === 1): ?>
                        <button onclick="openQRModal('<?php echo htmlspecialchars($pending_found_items[0]['tracking_id']); ?>')"
                                class="text-cmu-blue hover:text-blue-800 font-bold text-sm flex-shrink-0">
                            View Code
                        </button>

                    <?php elseif ($pending_count > 1): ?>
                        <button onclick="toggleQRDropdown(event)"
                                class="text-cmu-blue hover:text-blue-800 font-bold text-sm flex-shrink-0 flex items-center gap-1">
                            View Codes <i id="qr-dropdown-chevron" class="fas fa-chevron-down text-xs transition-transform duration-200"></i>
                        </button>

                    <?php else: ?>
                        <span class="text-xs text-slate-300 italic flex-shrink-0">—</span>
                    <?php endif; ?>
                </div>

                <?php if ($pending_count > 1): ?>
                    <!-- Floating dropdown — absolutely positioned so it doesn't push sibling cards -->
                    <div id="qr-dropdown"
                        class="hidden absolute right-0 top-full mt-2 w-full z-50
                                bg-white border border-slate-200 rounded-2xl shadow-xl
                                overflow-hidden">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-4 pt-4 pb-2">
                            Select an item to get its QR code
                        </p>
                        <div class="divide-y divide-slate-50 max-h-64 overflow-y-auto">
                            <?php foreach ($pending_found_items as $item): ?>
                                <div class="flex items-center justify-between px-4 py-3 hover:bg-amber-50 transition">
                                    <div class="min-w-0 mr-3">
                                        <p class="text-sm font-semibold text-slate-700 truncate"><?php echo htmlspecialchars($item['title']); ?></p>
                                        <p class="text-[10px] font-mono text-slate-400 mt-0.5"><?php echo htmlspecialchars($item['tracking_id']); ?></p>
                                    </div>
                                    <button onclick="openQRModal('<?php echo htmlspecialchars($item['tracking_id']); ?>'); closeQRDropdown();"
                                            class="flex-shrink-0 flex items-center gap-1.5 text-xs font-bold text-cmu-blue bg-white border border-blue-100 px-3 py-1.5 rounded-lg hover:bg-cmu-blue hover:text-white transition shadow-sm">
                                        <i class="fas fa-qrcode"></i> QR
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Main Content Tabs -->
        <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="flex border-b border-slate-100 bg-slate-50/50">
                <button onclick="switchTab('my-reports')" id="tab-my-reports" class="tab-btn active px-8 py-5 text-sm font-bold transition-all border-b-3">
                    My Reports (<?php echo count($my_reports); ?>)
                </button>
                <button onclick="switchTab('potential-matches')" id="tab-potential-matches" class="tab-btn px-8 py-5 text-sm font-bold text-slate-400 hover:text-slate-600 transition-all border-b-3 border-transparent">
                    Potential Matches
                    <?php if (!empty($potential_matches)): ?>
                        <span class="ml-2 bg-red-500 text-white text-[10px] px-2 py-0.5 rounded-full"><?php echo count($potential_matches); ?></span>
                    <?php endif; ?>
                </button>
            </div>

            <div class="p-6">
                <!-- My Reports List -->
                <div id="content-my-reports" class="space-y-4">
                    <?php if (empty($my_reports)): ?>
                        <div class="py-20 text-center">
                            <div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-folder-open text-slate-300 text-3xl"></i>
                            </div>
                            <h3 class="font-bold text-slate-800">No reports found</h3>
                            <p class="text-slate-500 text-sm mt-1">You haven't reported any lost or found items yet.</p>
                            <div class="flex gap-3 justify-center mt-6">
                                <a href="../public/report_lost.php" class="px-5 py-2.5 bg-slate-100 text-slate-700 rounded-xl text-sm font-bold hover:bg-slate-200 transition">Report Lost Item</a>
                                <a href="../public/report_found.php" class="px-5 py-2.5 bg-cmu-blue text-white rounded-xl text-sm font-bold hover:bg-slate-800 transition">Report Found Item</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($my_reports as $report):
                            $prog = getProgress($report['status'], $report['type']);
                            $tracking_id = ($report['type'] === 'found')
                                ? 'FND-' . str_pad($report['id'], 5, '0', STR_PAD_LEFT)
                                : 'LST-' . str_pad($report['id'], 5, '0', STR_PAD_LEFT);

                            $image_path = !empty($report['image_path'])
                                ? '../' . htmlspecialchars($report['image_path'])
                                : null;

                            $badge_cls = getStatusBadgeClass($report['status']);

                            // Build JSON for the detail modal
                            $modal_data = json_encode([
                                'id'           => $report['id'],
                                'type'         => $report['type'],
                                'tracking_id'  => $tracking_id,
                                'title'        => $report['title'],
                                'description'  => $report['private_description'],
                                'location'     => $report['location_name'],
                                'category'     => $report['category_name'] ?? '—',
                                'status'       => $report['status'],
                                'label'        => $prog['label'],
                                'step'         => $prog['step'],
                                'pct'          => $prog['pct'],
                                'color'        => $prog['color'],
                                'date'         => date('F d, Y', strtotime($report['date'])),
                                'created_at'   => date('F d, Y', strtotime($report['created_at'])),
                                'image'        => $image_path,
                                'date_label'   => $report['type'] === 'found' ? 'Date Found' : 'Date Lost',
                                'badge_cls'    => $badge_cls,
                            ], ENT_QUOTES);
                        ?>
                        <div class="group flex flex-col md:flex-row items-center gap-6 p-4 rounded-2xl border border-slate-100 hover:border-cmu-blue/30 hover:bg-slate-50/80 transition-all">
                            <!-- Thumbnail -->
                            <div class="w-full md:w-32 h-32 rounded-xl bg-slate-100 overflow-hidden flex-shrink-0 relative">
                                <?php if ($image_path): ?>
                                    <img src="<?php echo $image_path; ?>" alt="Item photo" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <div class="w-full h-full flex items-center justify-center text-slate-300">
                                        <i class="fas fa-image text-3xl"></i>
                                    </div>
                                <?php endif; ?>
                                <span class="absolute top-2 left-2 px-2 py-0.5 rounded text-[10px] font-bold uppercase <?php echo $report['type'] === 'found' ? 'bg-green-500 text-white' : 'bg-red-500 text-white'; ?>">
                                    <?php echo $report['type']; ?>
                                </span>
                            </div>

                            <!-- Info -->
                            <div class="flex-grow space-y-1 min-w-0">
                                <div class="flex flex-wrap justify-between items-start gap-2">
                                    <h4 class="text-lg font-bold text-slate-800 truncate"><?php echo htmlspecialchars($report['title']); ?></h4>
                                    <span class="px-3 py-1 rounded-full text-[10px] font-bold border flex-shrink-0 <?php echo $badge_cls; ?>">
                                        <?php echo strtoupper($prog['label']); ?>
                                    </span>
                                </div>
                                <p class="text-xs text-slate-500 flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span><i class="fas fa-calendar mr-1"></i><?php echo date('M d, Y', strtotime($report['date'])); ?></span>
                                    <span class="text-slate-300">•</span>
                                    <span><i class="fas fa-map-marker-alt mr-1"></i><?php echo htmlspecialchars($report['location_name']); ?></span>
                                    <span class="text-slate-300">•</span>
                                    <span class="font-mono text-[10px] text-slate-400"><?php echo $tracking_id; ?></span>
                                </p>
                                <?php if (!empty($report['private_description'])): ?>
                                    <p class="text-sm text-slate-500 line-clamp-1 italic">"<?php echo htmlspecialchars($report['private_description']); ?>"</p>
                                <?php endif; ?>

                                <!-- Progress Bar -->
                                <div class="mt-3 flex items-center gap-2">
                                    <div class="flex-grow h-1.5 bg-slate-200 rounded-full overflow-hidden">
                                        <div class="h-full rounded-full <?php echo $prog['color']; ?> transition-all duration-700" style="width: <?php echo $prog['pct']; ?>"></div>
                                    </div>
                                    <span class="text-[10px] font-bold text-slate-400 uppercase whitespace-nowrap"><?php echo $prog['step']; ?></span>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex md:flex-col gap-2 w-full md:w-auto flex-shrink-0">
                                <?php if ($report['type'] === 'found' && in_array($report['status'], ['in custody', 'surrendered'])): ?>
                                    <button onclick="openQRModal('<?php echo $tracking_id; ?>')"
                                            class="flex-1 px-4 py-2 bg-cmu-blue text-white rounded-lg text-xs font-bold hover:bg-slate-800 transition">
                                        <i class="fas fa-qrcode mr-1"></i>Get QR Code
                                    </button>
                                <?php elseif ($report['type'] === 'lost' && $report['status'] === 'matched'): ?>
                                    <button onclick="switchTab('potential-matches')"
                                            class="flex-1 px-4 py-2 bg-indigo-600 text-white rounded-lg text-xs font-bold hover:bg-indigo-700 transition">
                                        <i class="fas fa-bolt mr-1"></i>View Matches
                                    </button>
                                <?php endif; ?>
                                
                                <button onclick="openDetailModal(<?php echo htmlspecialchars($modal_data, ENT_QUOTES); ?>)"
                                        class="flex-1 px-8 py-2 border border-slate-200 text-slate-600 rounded-lg text-xs font-bold hover:bg-white hover:border-cmu-blue hover:text-cmu-blue transition">
                                    <i class="fas fa-eye mr-1"></i>Details
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Potential Matches Tab -->
                <div id="content-potential-matches" class="hidden space-y-6">
                    <div class="bg-amber-50 border border-amber-100 p-4 rounded-xl flex gap-3 items-start">
                        <i class="fas fa-info-circle text-amber-500 mt-0.5"></i>
                        <p class="text-xs text-amber-800 italic">Matching is based on category, location, and keywords. Visit SAO with a valid ID for final verification and item release.</p>
                    </div>

                    <?php if (empty($potential_matches)): ?>
                        <div class="py-14 text-center">
                            <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                <i class="fas fa-magnifying-glass text-slate-300 text-2xl"></i>
                            </div>
                            <h3 class="font-bold text-slate-700">No matches yet</h3>
                            <p class="text-slate-400 text-sm mt-1">We'll notify you via SMS when a similar item is reported.</p>
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php foreach ($potential_matches as $match):
                                $match_img = !empty($match['image_path'])
                                    ? '../' . htmlspecialchars($match['image_path'])
                                    : null;
                            ?>
                            <div class="bg-white border-2 border-indigo-100 rounded-2xl p-5 flex flex-col gap-4 relative hover:shadow-md transition">
                                <span class="absolute -top-3 left-4 bg-indigo-600 text-white text-[10px] px-3 py-1 rounded-full font-bold">
                                    MATCH FOR: <?php echo htmlspecialchars($match['my_item']); ?>
                                </span>
                                <div class="flex gap-4 pt-1">
                                    <div class="w-20 h-20 rounded-xl bg-slate-100 overflow-hidden flex-shrink-0">
                                        <?php if ($match_img): ?>
                                            <img src="<?php echo $match_img; ?>" alt="Match" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <div class="w-full h-full flex items-center justify-center text-slate-300">
                                                <i class="fas fa-image text-2xl"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <h5 class="font-bold text-slate-800"><?php echo htmlspecialchars($match['found_item']); ?></h5>
                                        <p class="text-xs text-slate-500 mb-3">
                                            <i class="fas fa-calendar mr-1"></i><?php echo date('M d, Y', strtotime($match['date_found'])); ?>
                                            &nbsp;•&nbsp;
                                            <i class="fas fa-map-marker-alt mr-1"></i><?php echo htmlspecialchars($match['location_name']); ?>
                                        </p>
                                        <a href="../public/index.php" class="inline-flex items-center gap-1.5 text-indigo-600 font-bold text-xs hover:underline">
                                            Verify at SAO <i class="fas fa-arrow-right"></i>
                                        </a>
                                    </div>
                                </div>
                                <div class="pt-3 border-t border-slate-50 flex items-center justify-between">
                                    <span class="text-[10px] text-slate-400 uppercase font-bold tracking-wide">Status: <?php echo htmlspecialchars($match['match_status']); ?></span>
                                    <span class="text-[10px] bg-indigo-50 text-indigo-600 px-2 py-1 rounded font-bold">Pending Verification</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <?php require_once '../includes/my_reports_modals.html'; ?>

    <?php require_once '../includes/footer.php'; ?>
    <script src="../assets/scripts/profile-dropdown.js"></script>
    <script src="../assets/scripts/qr_generator.js"></script>
    <script src="../assets/scripts/my_reports.js"></script>
</body>
</html>