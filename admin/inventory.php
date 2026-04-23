<?php
/**
 * CMU Lost & Found - Physical Inventory Management
 * Allows OSA to track shelf locations and item statuses.
 */

session_start();

// Security Guard
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../core/auth.php");
    exit();
}

require_once '../core/db_config.php';

// ── Filters ───────────────────────────────────────────────────────────────────
$status_filter = $_GET['status'] ?? 'all';
$search_query  = trim($_GET['search'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 10;
$offset        = ($page - 1) * $per_page;

// ── Build WHERE clause ────────────────────────────────────────────────────────
$where_parts = [];
$params      = [];

// Status tab filter
// 'custody'  → status = 'in custody'
// 'pending'  → status = 'in custody' where no shelf assigned yet (we use
//              the inventory table if it exists, but fall back to
//              status = 'in custody' with no shelf in found_reports)
// For simplicity we map the tabs to found_reports.status values:
//   all      → no filter
//   custody  → surrendered (physically on the shelf)
//   pending  → in custody  (reported but not yet scanned / received)
if ($status_filter === 'custody') {
    $where_parts[] = "f.status = 'surrendered'";
} elseif ($status_filter === 'pending') {
    $where_parts[] = "f.status = 'in custody'";
} else {
    // 'all' — show everything that hasn't been claimed or disposed
    $where_parts[] = "f.status NOT IN ('claimed', 'disposed', 'returned')";
}

// Search
if (!empty($search_query)) {
    $where_parts[] = "(
        f.title LIKE ?
        OR CONCAT('FND-', LPAD(f.found_id, 5, '0')) LIKE ?
        OR u.full_name LIKE ?
        OR c.name LIKE ?
    )";
    $search_val = '%' . $search_query . '%';
    // Add 4 positional values (one per column)
    $search_params = [$search_val, $search_val, $search_val, $search_val];
}

$where_sql = $where_parts ? ('WHERE ' . implode(' AND ', $where_parts)) : '';

// ── Count total rows for pagination ───────────────────────────────────────────
try {
    $count_sql = "
        SELECT COUNT(*)
        FROM found_reports f
        LEFT JOIN users      u   ON f.reported_by  = u.user_id
        LEFT JOIN categories c   ON f.category_id  = c.category_id
        LEFT JOIN locations  loc ON f.location_id  = loc.location_id
        $where_sql
    ";
    $all_params = [];
    if (!empty($search_query)) {
        $all_params = array_merge($all_params, $search_params);
    }
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($all_params);
    $total_items = (int) $count_stmt->fetchColumn();
    $total_pages = max(1, (int) ceil($total_items / $per_page));
} catch (PDOException $e) {
    $total_items = 0;
    $total_pages = 1;
}

// ── Fetch inventory rows ──────────────────────────────────────────────────────
$items = [];
$db_error = null;

try {
    $params[':limit']  = $per_page;
    $params[':offset'] = $offset;

    $sql = "
        SELECT
            f.found_id,
            f.title,
            f.status,
            f.date_found,
            f.created_at,
            f.private_description,
            CONCAT('FND-', LPAD(f.found_id, 5, '0')) AS tracking_id,
            c.name          AS category,
            loc.location_name AS found_location,
            u.full_name     AS finder_name,
            u.department    AS finder_dept,
            img.image_path,
            inv.shelf,
            inv.row_bin
        FROM found_reports f
        LEFT JOIN users      u   ON f.reported_by  = u.user_id
        LEFT JOIN categories c   ON f.category_id  = c.category_id
        LEFT JOIN locations  loc ON f.location_id  = loc.location_id
        LEFT JOIN inventory  inv ON inv.found_id   = f.found_id
        LEFT JOIN (
            SELECT report_id, image_path
            FROM   item_images
            WHERE  report_type = 'found'
            GROUP  BY report_id
        ) img ON img.report_id = f.found_id
        $where_sql
        ORDER BY f.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $pdo->prepare($sql);

    // PDO requires explicit binding for LIMIT/OFFSET when using named params
    $main_params = [];
    if (!empty($search_query)) {
        $main_params = array_merge($main_params, $search_params);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($main_params, [$per_page, $offset]));
    $items = $stmt->fetchAll();

} catch (PDOException $e) {
    $db_error = $e->getMessage();
    error_log('[inventory.php] DB Error: ' . $e->getMessage());
}

// ── Summary stats for the stat pills ─────────────────────────────────────────
$stats = ['all' => 0, 'custody' => 0, 'pending' => 0];
try {
    $s = $pdo->query("
        SELECT
            SUM(status NOT IN ('claimed','disposed','returned'))            AS all_count,
            SUM(status = 'surrendered')                                     AS custody_count,
            SUM(status = 'in custody')                                      AS pending_count
        FROM found_reports
    ")->fetch();
    $stats['all']     = (int)($s['all_count']     ?? 0);
    $stats['custody'] = (int)($s['custody_count'] ?? 0);
    $stats['pending'] = (int)($s['pending_count'] ?? 0);
} catch (PDOException $e) { /* fail silently */ }

// ── Status badge helper ───────────────────────────────────────────────────────
function statusPill(string $status): string {
    return match($status) {
        'surrendered'       => 'border-green-200 bg-green-50 text-green-700',
        'in custody'        => 'border-amber-200 bg-amber-50 text-amber-700',
        'matched'           => 'border-indigo-200 bg-indigo-50 text-indigo-700',
        'claimed', 'returned' => 'border-slate-200 bg-slate-50 text-slate-500',
        default             => 'border-slate-200 bg-slate-50 text-slate-400',
    };
}

function statusLabel(string $status): string {
    return match($status) {
        'in custody'  => 'Pending Turnover',
        'surrendered' => 'In Custody',
        'matched'     => 'Matched',
        'claimed'     => 'Claimed',
        'returned'    => 'Returned',
        default       => ucfirst($status),
    };
}

// ── Category icon map ─────────────────────────────────────────────────────────
function categoryIcon(string $category): string {
    return match($category) {
        'Electronics' => 'fa-display',
        'Valuables'   => 'fa-wallet',
        'Documents'   => 'fa-id-card',
        'Books'       => 'fa-book',
        'Clothing'    => 'fa-shirt',
        'Personal'    => 'fa-bag-shopping',
        default       => 'fa-box',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Physical Inventory | CMU Lost & Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/styles/root.css">
    <link rel="stylesheet" href="../assets/styles/admin_dashboard.css">
</head>
<body class="bg-slate-50 min-h-screen flex">

    <!-- ── Sidebar ────────────────────────────────────────────────────────── -->
    <aside class="w-64 bg-cmu-blue text-white flex-shrink-0 hidden lg:flex flex-col shadow-xl sticky top-0 h-screen">
        <div class="p-6 flex items-center gap-3 border-b border-white/10">
            <img src="../assets/images/system-icon.png" alt="Logo"
                 class="w-10 h-10 bg-white rounded-lg p-1"
                 onerror="this.src='https://ui-avatars.com/api/?name=SAO&background=fff&color=003366';">
            <div>
                <h1 class="font-bold text-sm leading-tight">SAO Admin</h1>
                <p class="text-[10px] text-blue-200 uppercase tracking-widest">Management Portal</p>
            </div>
        </div>

        <nav class="flex-grow p-4 space-y-2">
            <a href="dashboard.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition">
                <i class="fas fa-th-large w-5"></i>
                <span class="text-sm font-medium">Dashboard Overview</span>
            </a>
            <a href="inventory.php" class="sidebar-link active flex items-center gap-3 p-3 rounded-xl transition">
                <i class="fas fa-boxes w-5"></i>
                <span class="text-sm font-medium">Physical Inventory</span>
            </a>
            <a href="qr_scanner.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition">
                <i class="fas fa-qrcode w-5"></i>
                <span class="text-sm font-medium">QR Intake Scanner</span>
            </a>
            <a href="matching_portal.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition">
                <i class="fas fa-sync w-5"></i>
                <span class="text-sm font-medium">Matching Portal</span>
            </a>
            <a href="claim_verify.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition">
                <i class="fas fa-user-check w-5"></i>
                <span class="text-sm font-medium">Claim Verification</span>
            </a>
            <a href="manage_reports.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition">
                <i class="fas fa-shield w-5"></i>
                <span class="text-sm font-medium">Manage Reports</span>
            </a>
            <div class="pt-4 mt-4 border-t border-white/10">
                <a href="archive.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition">
                    <i class="fas fa-archive w-5 text-blue-300"></i>
                    <span class="text-sm font-medium text-blue-100">Records Archive</span>
                </a>
                <a href="record_merger.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition mt-1">
                    <i class="fas fa-code-merge w-5 text-blue-300"></i>
                    <span class="text-sm font-medium text-blue-100">Record Merger</span>
                </a>
            </div>
        </nav>

        <div class="p-4 border-t border-white/10">
            <div class="bg-white/5 rounded-2xl p-4">
                <p class="text-[10px] text-blue-300 uppercase font-bold mb-2">Logged in as</p>
                <p class="text-sm font-bold truncate"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['user_name'] ?? 'Admin'); ?></p>
                <a href="../core/logout.php"
                   class="text-xs text-cmu-blue font-bold mt-2 py-2 px-4 inline-block rounded-md bg-cmu-gold hover:rounded-full hover:text-cmu-gold hover:bg-white">
                    Logout Session
                </a>
            </div>
        </div>
    </aside>

    <!-- ── Content ────────────────────────────────────────────────────────── -->
    <main class="flex-grow flex flex-col min-w-0 h-screen overflow-hidden">

        <!-- Navbar -->
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between flex-shrink-0">
            <div>
                <h2 class="text-xl font-black text-slate-800 tracking-tight uppercase">Physical Inventory</h2>
                <p class="text-xs text-slate-500">Track item locations and manage SAO custody records.</p>
            </div>

            <div class="flex items-center gap-4">
                <!-- Live search form -->
                <form method="GET" action="inventory.php" class="relative hidden md:block">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                    <input type="text" name="search"
                           value="<?php echo htmlspecialchars($search_query); ?>"
                           placeholder="Search by ID, title, finder..."
                           class="pl-10 pr-4 py-2 bg-slate-100 border-transparent focus:bg-white focus:border-cmu-blue border rounded-xl text-sm transition w-64 outline-none">
                </form>
                <button onclick="document.getElementById('manualEntryModal').classList.remove('hidden')"
                        class="bg-cmu-blue text-white px-4 py-2 rounded-xl text-sm font-bold shadow-md hover:bg-slate-800 transition">
                    <i class="fas fa-plus mr-2"></i>Manual Entry
                </button>
            </div>
        </header>

        <!-- DB Error Banner -->
        <?php if ($db_error): ?>
        <div class="mx-8 mt-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-xs font-semibold flex items-center gap-2">
            <i class="fas fa-exclamation-triangle"></i>
            Database error — <?php echo htmlspecialchars($db_error); ?>
        </div>
        <?php endif; ?>

        <!-- Main Workspace -->
        <div class="p-8 flex-grow flex flex-col gap-6 overflow-hidden">

            <!-- Filters & Tabs -->
            <div class="flex flex-col md:flex-row items-center justify-between gap-4 flex-shrink-0">
                <div class="flex bg-white p-1 rounded-xl border border-slate-200 shadow-sm">
                    <?php
                    $tabs = [
                        'all'     => "All Items ({$stats['all']})",
                        'custody' => "Surrendered ({$stats['custody']})",
                        'pending' => "Pending Receipt ({$stats['pending']})",
                    ];
                    foreach ($tabs as $key => $label):
                        $active = $status_filter === $key;
                        $href = '?' . http_build_query(['status' => $key, 'search' => $search_query, 'page' => 1]);
                    ?>
                    <a href="<?php echo htmlspecialchars($href); ?>"
                       class="px-4 py-2 text-xs font-bold rounded-lg transition <?php echo $active ? 'bg-cmu-blue text-white shadow-md' : 'text-slate-500 hover:bg-slate-50'; ?>">
                        <?php echo $label; ?>
                    </a>
                    <?php endforeach; ?>
                </div>

                <div class="flex items-center gap-2">
                    <span class="text-xs font-bold text-slate-400 uppercase mr-2">Display:</span>
                    <button class="w-9 h-9 flex items-center justify-center bg-white border border-slate-200 rounded-lg text-slate-600 shadow-sm"><i class="fas fa-list"></i></button>
                    <button class="w-9 h-9 flex items-center justify-center bg-slate-100 border border-slate-200 rounded-lg text-slate-400"><i class="fas fa-th-large"></i></button>
                </div>
            </div>

            <!-- Inventory Table -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm flex-grow overflow-hidden flex flex-col">
                <div class="overflow-x-auto custom-scrollbar flex-grow">
                    <table class="w-full text-left border-collapse min-w-[1000px]">
                        <thead>
                            <tr class="bg-slate-50/50 border-b border-slate-100">
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase text-center tracking-widest">Item Info</th>
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase text-center tracking-widest">Tracking ID</th>
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase text-center tracking-widest">Status</th>
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase text-center tracking-widest">Shelf</th>
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase text-center tracking-widest">Finder</th>
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase text-center tracking-widest">Date Found</th>
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase text-center tracking-widest">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">

                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-20 text-center">
                                    <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4">
                                        <i class="fas fa-box-open text-slate-200 text-3xl"></i>
                                    </div>
                                    <p class="font-bold text-slate-400">No items found</p>
                                    <p class="text-xs text-slate-300 mt-1">
                                        <?php echo !empty($search_query) ? 'Try adjusting your search or filters.' : 'No items match the selected status.'; ?>
                                    </p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $item):
                                $icon      = categoryIcon($item['category'] ?? 'Other');
                                $pill_cls  = statusPill($item['status']);
                                $pill_lbl  = statusLabel($item['status']);
                                $date_fmt  = !empty($item['date_found'])
                                    ? date('M d, Y', strtotime($item['date_found']))
                                    : '—';
                                $img_src   = !empty($item['image_path'])
                                    ? '../' . htmlspecialchars($item['image_path'])
                                    : null;
                            ?>
                            <tr class="hover:bg-slate-50/80 transition group">

                                <!-- Item Info -->
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-12 h-12 rounded-lg bg-slate-100 overflow-hidden flex-shrink-0 border border-slate-100 flex items-center justify-center text-slate-400">
                                            <?php if ($img_src): ?>
                                                <img src="<?php echo $img_src; ?>"
                                                     alt="Item"
                                                     class="w-full h-full object-cover"
                                                     onerror="this.parentElement.innerHTML='<i class=\'fas <?php echo $icon; ?> text-lg\'></i>'">
                                            <?php else: ?>
                                                <i class="fas <?php echo $icon; ?> text-lg"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <p class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($item['title']); ?></p>
                                            <p class="text-[10px] text-slate-400">
                                                <?php echo htmlspecialchars($item['category'] ?? 'Uncategorized'); ?>
                                                <?php if (!empty($item['found_location'])): ?>
                                                    &middot; <?php echo htmlspecialchars($item['found_location']); ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                </td>

                                <!-- Tracking ID -->
                                <td class="px-6 py-4">
                                    <span class="font-mono text-xs font-bold text-indigo-600 bg-indigo-50 px-2 py-1 text-center rounded">
                                        <?php echo htmlspecialchars($item['tracking_id']); ?>
                                    </span>
                                </td>

                                <!-- Status -->
                                <td class="px-6 py-4">
                                    <div class="text-[10px] font-bold px-2.5 py-1 rounded-xl border uppercase text-center <?php echo $pill_cls; ?>">
                                        <?php echo $pill_lbl; ?>
                                    </div>
                                </td>

                                <!-- Shelf Location -->
                                <td class="px-6 py-4">
                                    <?php if (!empty($item['shelf'])): ?>
                                        <span class="font-mono text-xs font-bold text-green-700 bg-green-50 border border-green-200 px-2.5 py-1 rounded-xl text-center block">
                                            <?php echo htmlspecialchars($item['shelf']); ?>
                                            <?php if (!empty($item['row_bin'])): ?>
                                                &ndash;<?php echo htmlspecialchars($item['row_bin']); ?>
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-[10px] font-bold text-amber-600 bg-amber-50 border border-amber-100 px-2.5 py-1 rounded-xl text-center block uppercase tracking-wide">
                                            Unassigned
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Finder -->
                                <td class="px-6 py-4">
                                    <p class="text-sm font-semibold text-slate-700 text-center truncate">
                                        <?php echo htmlspecialchars($item['finder_name'] ?? '—'); ?>
                                    </p>
                                    <p class="text-[10px] text-slate-400 text-center truncate">
                                        <?php echo htmlspecialchars($item['finder_dept'] ?? ''); ?>
                                    </p>
                                </td>

                                <!-- Date Found -->
                                <td class="px-6 py-4 text-xs text-slate-500 font-medium text-center">
                                    <?php echo $date_fmt; ?>
                                </td>

                                <!-- Actions -->
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-center gap-2">
                                        <?php if ($item['status'] === 'in custody'): ?>
                                            <!-- Pending: prompt to scan -->
                                            <a href="qr_scanner.php?prefill=<?php echo urlencode($item['tracking_id']); ?>"
                                               class="px-3 py-1 bg-cmu-blue text-white text-[10px] font-black rounded-lg uppercase tracking-tight shadow-sm hover:bg-slate-800 text-center transition">
                                                Scan Receipt
                                            </a>
                                        <?php else: ?>
                                            <button title="Edit / Assign Shelf"
                                                    onclick="openShelfModal('<?php echo htmlspecialchars($item['tracking_id']); ?>', <?php echo (int)$item['found_id']; ?>)"
                                                    class="p-2 text-slate-400 hover:text-cmu-blue transition">
                                                <i class="fas fa-pen-to-square"></i>
                                            </button>
                                            <a href="matching_portal.php" title="View Matches"
                                               class="p-2 text-slate-400 hover:text-indigo-600 transition">
                                                <i class="fas fa-sync"></i>
                                            </a>
                                            <button title="Archive"
                                                    onclick="archiveItem(<?php echo (int)$item['found_id']; ?>)"
                                                    class="p-2 text-slate-400 hover:text-red-600 transition">
                                                <i class="fas fa-archive"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        </tbody>
                    </table>
                </div>

                <!-- Pagination / Footer -->
                <div class="p-4 border-t border-slate-100 flex items-center justify-between flex-shrink-0 bg-slate-50/30">
                    <p class="text-[11px] text-slate-500">
                        Showing
                        <strong><?php echo min($offset + 1, $total_items); ?>–<?php echo min($offset + $per_page, $total_items); ?></strong>
                        of <strong><?php echo $total_items; ?></strong> items
                    </p>
                    <div class="flex gap-1">
                        <!-- Prev -->
                        <?php
                        $base_params = ['status' => $status_filter, 'search' => $search_query];
                        $prev_href = '?' . http_build_query(array_merge($base_params, ['page' => $page - 1]));
                        $next_href = '?' . http_build_query(array_merge($base_params, ['page' => $page + 1]));
                        ?>
                        <a href="<?php echo $page > 1 ? htmlspecialchars($prev_href) : '#'; ?>"
                           class="w-8 h-8 rounded bg-white border border-slate-200 flex items-center justify-center text-xs <?php echo $page <= 1 ? 'opacity-40 pointer-events-none' : 'hover:bg-slate-50 text-slate-600'; ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>

                        <!-- Page numbers (show up to 5) -->
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page   = min($total_pages, $start_page + 4);
                        for ($p = $start_page; $p <= $end_page; $p++):
                            $p_href = '?' . http_build_query(array_merge($base_params, ['page' => $p]));
                        ?>
                        <a href="<?php echo htmlspecialchars($p_href); ?>"
                           class="w-8 h-8 rounded flex items-center justify-center text-xs font-bold <?php echo $p === $page ? 'bg-cmu-blue text-white shadow-sm' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50'; ?>">
                            <?php echo $p; ?>
                        </a>
                        <?php endfor; ?>

                        <!-- Next -->
                        <a href="<?php echo $page < $total_pages ? htmlspecialchars($next_href) : '#'; ?>"
                           class="w-8 h-8 rounded bg-white border border-slate-200 flex items-center justify-center text-xs <?php echo $page >= $total_pages ? 'opacity-40 pointer-events-none' : 'hover:bg-slate-50 text-slate-600'; ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- ── Modal: Assign Shelf Location ──────────────────────────────────── -->
    <div id="locationModal" class="fixed inset-0 z-[60] hidden bg-slate-900/50 flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl w-full max-w-md p-8 shadow-2xl">
            <h3 class="text-xl font-black text-slate-800 mb-2 uppercase tracking-tight">Assign Shelf Location</h3>
            <p class="text-xs text-slate-500 mb-6">
                Physical storage coordinates for
                <span id="modalTrackingId" class="font-bold text-cmu-blue">—</span>
            </p>

            <form id="shelfForm" method="POST" action="update_shelf.php">
                <input type="hidden" name="found_id" id="shelfFoundId">
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Section/Shelf</label>
                            <select name="shelf" class="w-full bg-slate-100 border-none rounded-xl p-3 text-sm font-bold outline-none">
                                <option value="A">Shelf A (Electronics)</option>
                                <option value="B">Shelf B (Books/Paper)</option>
                                <option value="C">Shelf C (Accessories)</option>
                                <option value="D">Shelf D (Bags/Clothes)</option>
                                <option value="V">Vault (Valuables)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Row / Bin</label>
                            <input type="text" name="row_bin" placeholder="e.g. 101"
                                   class="w-full bg-slate-100 border-none rounded-xl p-3 text-sm font-bold outline-none">
                        </div>
                    </div>
                    <div class="p-4 bg-blue-50 rounded-2xl flex gap-3 border border-blue-100">
                        <i class="fas fa-info-circle text-cmu-blue mt-1"></i>
                        <p class="text-[11px] text-blue-700 leading-relaxed italic">
                            Assigning a location updates the record immediately, helping other SAO staff locate the item.
                        </p>
                    </div>
                </div>
                <div class="mt-8 flex gap-3">
                    <button type="button" onclick="closeShelfModal()"
                            class="flex-grow py-3 bg-slate-100 text-slate-600 rounded-xl font-bold text-sm hover:bg-slate-200 transition">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-grow py-3 bg-cmu-blue text-white rounded-xl font-bold text-sm shadow-lg shadow-blue-100 hover:bg-slate-800 transition">
                        Save Location
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Modal: Manual Entry placeholder ───────────────────────────────── -->
    <div id="manualEntryModal" class="fixed inset-0 z-[60] hidden bg-slate-900/50 flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl w-full max-w-md p-8 shadow-2xl text-center">
            <div class="w-14 h-14 bg-blue-50 text-cmu-blue rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-edit text-2xl"></i>
            </div>
            <h3 class="text-xl font-black text-slate-800 mb-2">Manual Entry</h3>
            <p class="text-sm text-slate-500 mb-6">
                To add an item manually, use the
                <a href="../public/report_found.php" class="text-cmu-blue font-bold hover:underline">Report Found Item</a>
                form. It will appear here once submitted.
            </p>
            <div class="flex gap-3">
                <button onclick="document.getElementById('manualEntryModal').classList.add('hidden')"
                        class="flex-1 py-3 bg-slate-100 text-slate-600 rounded-xl font-bold text-sm hover:bg-slate-200 transition">
                    Close
                </button>
                <a href="../public/report_found.php"
                   class="flex-1 py-3 bg-cmu-blue text-white rounded-xl font-bold text-sm hover:bg-slate-800 transition">
                    Go to Report Form
                </a>
            </div>
        </div>
    </div>

    <!-- ── Toast ──────────────────────────────────────────────────────────── -->
    <div id="toast" class="fixed bottom-8 left-1/2 -translate-x-1/2 z-[100] hidden px-6 py-3 rounded-2xl font-bold text-sm text-white shadow-xl transition-all duration-300"></div>

    <script>
        // ── Shelf modal ───────────────────────────────────────────────────
        function openShelfModal(trackingId, foundId) {
            document.getElementById('modalTrackingId').textContent = trackingId;
            document.getElementById('shelfFoundId').value = foundId;
            document.getElementById('locationModal').classList.remove('hidden');
        }
        function closeShelfModal() {
            document.getElementById('locationModal').classList.add('hidden');
        }

        // Close modals on backdrop click
        ['locationModal', 'manualEntryModal'].forEach(id => {
            document.getElementById(id).addEventListener('click', function(e) {
                if (e.target === this) this.classList.add('hidden');
            });
        });

        // ── Archive item (AJAX) ────────────────────────────────────────────
        async function archiveItem(foundId) {
            if (!confirm('Archive this item? It will be moved to the Records Archive.')) return;
            try {
                const res = await fetch('archive_item.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ found_id: foundId })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Item archived successfully.', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.message || 'Failed to archive item.', 'error');
                }
            } catch {
                showToast('Network error. Please try again.', 'error');
            }
        }

        // ── Toast helper ──────────────────────────────────────────────────
        function showToast(msg, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = msg;
            toast.className = `fixed bottom-8 left-1/2 -translate-x-1/2 z-[100] px-6 py-3 rounded-2xl font-bold text-sm text-white shadow-xl transition-all duration-300 ${type === 'success' ? 'bg-green-600' : 'bg-red-600'}`;
            toast.classList.remove('hidden');
            setTimeout(() => {
                toast.classList.add('hidden');
            }, 3000);
        }
    </script>
</body>
</html>