<?php
/**
 * CMU Lost & Found — Records Archive
 * Displays resolved items, expired/donated inventory, and an aging report.
 */

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../core/auth.php");
    exit();
}

require_once '../core/db_config.php';

// ── Ensure archive table exists (idempotent) ──────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS archive (
            archive_id     INT           PRIMARY KEY AUTO_INCREMENT,
            found_id       INT           DEFAULT NULL,
            lost_id        INT           DEFAULT NULL,
            tracking_id    VARCHAR(20)   NOT NULL,
            item_title     VARCHAR(255)  NOT NULL,
            category       VARCHAR(100)  DEFAULT 'Other',
            found_location VARCHAR(150)  DEFAULT NULL,
            date_event     DATE          DEFAULT NULL,
            outcome        ENUM('Returned','Expired/Donated','Disposed','Closed') NOT NULL DEFAULT 'Returned',
            claimant_name  VARCHAR(150)  DEFAULT 'N/A',
            claimant_id_no VARCHAR(50)   DEFAULT NULL,
            claimant_dept  VARCHAR(100)  DEFAULT NULL,
            resolved_by    INT           DEFAULT NULL,
            resolved_date  DATE          NOT NULL DEFAULT (CURDATE()),
            image_path     VARCHAR(500)  DEFAULT NULL,
            notes          TEXT          DEFAULT NULL,
            created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
            updated_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_tracking (tracking_id),
            INDEX idx_outcome  (outcome),
            INDEX idx_resolved (resolved_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $e) {
    // Table may already exist with a slightly different definition — fine
    error_log('[archive.php] CREATE TABLE note: ' . $e->getMessage());
}

// ── Filters & pagination ──────────────────────────────────────────────────
$active_tab    = $_GET['tab']      ?? 'records';   // 'records' | 'aging'
$search_query  = trim($_GET['search']   ?? '');
$outcome_filter= $_GET['outcome']  ?? 'all';
$date_filter   = $_GET['date']     ?? 'all';
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 15;
$offset        = ($page - 1) * $per_page;

// ── Build WHERE for archive table ─────────────────────────────────────────
$where_parts = [];
$params      = [];

if (!empty($search_query)) {
    $where_parts[] = "(a.tracking_id LIKE :search OR a.item_title LIKE :search OR a.claimant_name LIKE :search)";
    $params[':search'] = '%' . $search_query . '%';
}

if ($outcome_filter !== 'all') {
    $where_parts[] = "a.outcome = :outcome";
    $params[':outcome'] = $outcome_filter;
}

if ($date_filter === '30') {
    $where_parts[] = "a.resolved_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
} elseif ($date_filter === '180') {
    $where_parts[] = "a.resolved_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY)";
} elseif ($date_filter === 'year') {
    $where_parts[] = "YEAR(a.resolved_date) = YEAR(CURDATE())";
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// ── Count + fetch archive rows ────────────────────────────────────────────
$archived_items = [];
$total_items    = 0;
$total_pages    = 1;
$db_error       = null;

try {
    // Count
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM archive a $where_sql");
    $count_stmt->execute($params);
    $total_items = (int) $count_stmt->fetchColumn();
    $total_pages = max(1, (int) ceil($total_items / $per_page));

    // Rows
    $params[':limit']  = $per_page;
    $params[':offset'] = $offset;

    $stmt = $pdo->prepare("
        SELECT
            a.*,
            u.full_name AS officer_name
        FROM archive a
        LEFT JOIN users u ON u.user_id = a.resolved_by
        $where_sql
        ORDER BY a.resolved_date DESC, a.archive_id DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $key => $val) {
        $type = ($key === ':limit' || $key === ':offset') ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($key, $val, $type);
    }
    $stmt->execute();
    $archived_items = $stmt->fetchAll();

} catch (PDOException $e) {
    $db_error = $e->getMessage();
    error_log('[archive.php] DB Error: ' . $e->getMessage());
}

// ── Summary counts ────────────────────────────────────────────────────────
$counts = ['total' => 0, 'Returned' => 0, 'Expired/Donated' => 0, 'Disposed' => 0, 'Closed' => 0];
try {
    $s = $pdo->query("
        SELECT outcome, COUNT(*) AS cnt
        FROM archive
        GROUP BY outcome
        WITH ROLLUP
    ")->fetchAll();
    foreach ($s as $row) {
        if ($row['outcome'] === null) {
            $counts['total'] = (int)$row['cnt'];
        } else {
            $counts[$row['outcome']] = (int)$row['cnt'];
        }
    }
} catch (PDOException $e) { /* silent */ }

// ── Aging items (approaching or past 60-day threshold) ────────────────────
$aging_items = [];
try {
    $aging_items = $pdo->query("
        SELECT
            f.found_id,
            CONCAT('FND-', LPAD(f.found_id, 5, '0')) AS tracking_id,
            f.title,
            COALESCE(c.name, 'Other')              AS category,
            COALESCE(loc.location_name, 'Unknown') AS found_location,
            f.date_found,
            DATEDIFF(CURDATE(), f.date_found)      AS days_held,
            CASE
                WHEN DATEDIFF(CURDATE(), f.date_found) >= 60 THEN 'Expired'
                WHEN DATEDIFF(CURDATE(), f.date_found) >= 45 THEN 'Critical'
                ELSE 'Warning'
            END AS age_status
        FROM found_reports f
        LEFT JOIN categories c   ON f.category_id = c.category_id
        LEFT JOIN locations  loc ON f.location_id  = loc.location_id
        WHERE f.status NOT IN ('claimed','disposed','returned')
          AND DATEDIFF(CURDATE(), f.date_found) >= 30
        ORDER BY days_held DESC
    ")->fetchAll();
} catch (PDOException $e) {
    error_log('[archive.php] aging query: ' . $e->getMessage());
}

// ── Helpers ───────────────────────────────────────────────────────────────
function outcomeBadge(string $outcome): string {
    return match($outcome) {
        'Returned'        => 'bg-green-100 text-green-700 border-green-200',
        'Expired/Donated' => 'bg-amber-100 text-amber-700 border-amber-200',
        'Disposed'        => 'bg-red-100   text-red-600   border-red-200',
        'Closed'          => 'bg-slate-100 text-slate-500 border-slate-200',
        default           => 'bg-slate-100 text-slate-500 border-slate-200',
    };
}
function outcomeIcon(string $outcome): string {
    return match($outcome) {
        'Returned'        => 'fa-check-circle',
        'Expired/Donated' => 'fa-hand-holding-heart',
        'Disposed'        => 'fa-trash-alt',
        'Closed'          => 'fa-folder',
        default           => 'fa-circle',
    };
}
function ageBadge(string $status): string {
    return match($status) {
        'Expired'  => 'bg-red-100 text-red-700 border-red-200',
        'Critical' => 'bg-orange-100 text-orange-700 border-orange-200',
        default    => 'bg-amber-100 text-amber-600 border-amber-200',
    };
}

// Build base query string for pagination links
function buildPageHref(int $page): string {
    $q = $_GET;
    $q['page'] = $page;
    return '?' . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Records Archive | OSA Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/styles/root.css">
    <link rel="stylesheet" href="../assets/styles/admin_dashboard.css">
</head>
<body class="bg-slate-50 min-h-screen flex">

<!-- ── Sidebar ─────────────────────────────────────────────────────────── -->
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
        <a href="matching_portal.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition"><i class="fas fa-sync w-5"></i><span class="text-sm font-medium">Matching Portal</span></a>
        <a href="claim_verify.php"    class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition"><i class="fas fa-user-check w-5"></i><span class="text-sm font-medium">Claim Verification</span></a>
        <div class="pt-4 mt-4 border-t border-white/10">
            <a href="archive.php" class="sidebar-link active flex items-center gap-3 p-3 rounded-xl transition">
                <i class="fas fa-archive w-5"></i>
                <span class="text-sm font-medium">Records Archive</span>
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

<!-- ── Main ─────────────────────────────────────────────────────────────── -->
<main class="flex-grow flex flex-col min-w-0 h-screen overflow-hidden">

    <!-- Header -->
    <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between sticky top-0 z-50 flex-shrink-0">
        <div>
            <h2 class="text-xl font-black text-slate-800 tracking-tight uppercase">Records Archive</h2>
            <p class="text-xs font-bold text-slate-400 uppercase mt-0.5">Audit log of resolved and expired items</p>
        </div>

        <div class="flex gap-3">
            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>"
               class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-50 transition">
                <i class="fas fa-file-csv text-green-600"></i> CSV Export
            </a>
            <button onclick="window.print()"
                    class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-50 transition">
                <i class="fas fa-print text-slate-500"></i> Print
            </button>
        </div>
    </header>

    <div class="p-8 overflow-y-auto flex-grow space-y-6">

        <?php if ($db_error): ?>
        <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl text-xs font-semibold flex items-center gap-2">
            <i class="fas fa-exclamation-triangle"></i>
            Database error — <?php echo htmlspecialchars($db_error); ?>
        </div>
        <?php endif; ?>

        <!-- ── Summary stat cards ────────────────────────────────────────── -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
                <div class="w-10 h-10 bg-slate-100 text-slate-500 rounded-xl flex items-center justify-center mb-3">
                    <i class="fas fa-archive"></i>
                </div>
                <p class="text-2xl font-black text-slate-800"><?php echo number_format($counts['total']); ?></p>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">Total Archived</p>
            </div>
            <div class="bg-green-50 rounded-2xl border border-green-100 p-5">
                <div class="w-10 h-10 bg-green-100 text-green-600 rounded-xl flex items-center justify-center mb-3">
                    <i class="fas fa-check-circle"></i>
                </div>
                <p class="text-2xl font-black text-green-700"><?php echo number_format($counts['Returned']); ?></p>
                <p class="text-[10px] font-bold text-green-500 uppercase tracking-widest mt-0.5">Returned</p>
            </div>
            <div class="bg-amber-50 rounded-2xl border border-amber-100 p-5">
                <div class="w-10 h-10 bg-amber-100 text-amber-600 rounded-xl flex items-center justify-center mb-3">
                    <i class="fas fa-hand-holding-heart"></i>
                </div>
                <p class="text-2xl font-black text-amber-700"><?php echo number_format($counts['Expired/Donated']); ?></p>
                <p class="text-[10px] font-bold text-amber-500 uppercase tracking-widest mt-0.5">Donated</p>
            </div>
            <div class="bg-red-50 rounded-2xl border border-red-100 p-5">
                <div class="w-10 h-10 bg-red-100 text-red-500 rounded-xl flex items-center justify-center mb-3">
                    <i class="fas fa-trash-alt"></i>
                </div>
                <p class="text-2xl font-black text-red-600"><?php echo number_format($counts['Disposed']); ?></p>
                <p class="text-[10px] font-bold text-red-400 uppercase tracking-widest mt-0.5">Disposed</p>
            </div>
        </div>

        <!-- ── Tab switcher ─────────────────────────────────────────────── -->
        <div class="flex bg-white p-1 rounded-xl border border-slate-200 shadow-sm w-fit gap-1">
            <?php
            $tabs = [
                'records' => ['label' => 'Resolved Records', 'icon' => 'fa-list'],
                'aging'   => ['label' => 'Aging Items (' . count($aging_items) . ')', 'icon' => 'fa-clock'],
            ];
            foreach ($tabs as $key => $tab):
                $active = $active_tab === $key;
                $href   = '?' . http_build_query(array_merge($_GET, ['tab' => $key, 'page' => 1]));
            ?>
            <a href="<?php echo htmlspecialchars($href); ?>"
               class="flex items-center gap-2 px-5 py-2.5 text-xs font-bold rounded-lg transition
                      <?php echo $active ? 'bg-cmu-blue text-white shadow-md' : 'text-slate-500 hover:bg-slate-50'; ?>">
                <i class="fas <?php echo $tab['icon']; ?>"></i>
                <?php echo $tab['label']; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if ($active_tab === 'records'): ?>

        <!-- ── Search & filters ─────────────────────────────────────────── -->
        <form method="GET" action="archive.php" class="bg-white p-4 rounded-3xl border border-slate-200 shadow-sm flex flex-wrap gap-3 items-center">
            <input type="hidden" name="tab" value="records">

            <div class="flex-grow relative min-w-[200px]">
                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-300 text-sm"></i>
                <input type="text" name="search"
                       value="<?php echo htmlspecialchars($search_query); ?>"
                       placeholder="Search by Tracking ID, item, or claimant..."
                       class="w-full pl-11 pr-4 py-3 bg-slate-50 border-none rounded-2xl text-sm focus:ring-2 focus:ring-cmu-blue outline-none transition">
            </div>

            <select name="outcome"
                    class="bg-slate-50 border-none rounded-2xl px-4 py-3 text-sm font-bold text-slate-600 outline-none appearance-none cursor-pointer">
                <option value="all"      <?php echo $outcome_filter === 'all'              ? 'selected' : ''; ?>>All Outcomes</option>
                <option value="Returned" <?php echo $outcome_filter === 'Returned'         ? 'selected' : ''; ?>>Returned</option>
                <option value="Expired/Donated" <?php echo $outcome_filter === 'Expired/Donated' ? 'selected' : ''; ?>>Expired / Donated</option>
                <option value="Disposed" <?php echo $outcome_filter === 'Disposed'         ? 'selected' : ''; ?>>Disposed</option>
                <option value="Closed"   <?php echo $outcome_filter === 'Closed'           ? 'selected' : ''; ?>>Closed</option>
            </select>

            <select name="date"
                    class="bg-slate-50 border-none rounded-2xl px-4 py-3 text-sm font-bold text-slate-600 outline-none appearance-none cursor-pointer">
                <option value="all"  <?php echo $date_filter === 'all'  ? 'selected' : ''; ?>>All Dates</option>
                <option value="30"   <?php echo $date_filter === '30'   ? 'selected' : ''; ?>>Last 30 Days</option>
                <option value="180"  <?php echo $date_filter === '180'  ? 'selected' : ''; ?>>Last 6 Months</option>
                <option value="year" <?php echo $date_filter === 'year' ? 'selected' : ''; ?>>This Academic Year</option>
            </select>

            <button type="submit"
                    class="bg-cmu-blue text-white px-6 py-3 rounded-2xl text-sm font-bold hover:bg-slate-800 transition">
                Filter
            </button>

            <?php if ($search_query || $outcome_filter !== 'all' || $date_filter !== 'all'): ?>
            <a href="?tab=records"
               class="text-xs text-slate-400 hover:text-red-500 font-bold transition flex items-center gap-1">
                <i class="fas fa-times"></i> Clear
            </a>
            <?php endif; ?>
        </form>

        <!-- ── Records table ────────────────────────────────────────────── -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse min-w-[900px]">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200">
                            <th class="archive-table-header px-6 py-4">Tracking ID</th>
                            <th class="archive-table-header px-6 py-4">Item</th>
                            <th class="archive-table-header px-6 py-4">Category</th>
                            <th class="archive-table-header px-6 py-4">Claimant / Outcome</th>
                            <th class="archive-table-header px-6 py-4">Resolution Date</th>
                            <th class="archive-table-header px-6 py-4">Officer</th>
                            <th class="archive-table-header px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">

                    <?php if (empty($archived_items)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-20 text-center">
                                <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-folder-open text-slate-200 text-3xl"></i>
                                </div>
                                <h3 class="font-bold text-slate-800">No records found</h3>
                                <p class="text-sm text-slate-400 mt-1">
                                    <?php echo !empty($search_query) || $outcome_filter !== 'all'
                                        ? 'Try adjusting your filters or search terms.'
                                        : 'Resolved items will appear here after handover is completed.'; ?>
                                </p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($archived_items as $item):
                            $badge_cls  = outcomeBadge($item['outcome']);
                            $icon       = outcomeIcon($item['outcome']);
                            $res_date   = !empty($item['resolved_date'])
                                ? date('M d, Y', strtotime($item['resolved_date']))
                                : '—';
                            $img_src    = !empty($item['image_path'])
                                ? '../' . htmlspecialchars($item['image_path'])
                                : null;
                        ?>
                        <tr class="hover:bg-slate-50/60 transition group">

                            <!-- Tracking ID -->
                            <td class="px-6 py-5">
                                <span class="font-mono text-xs font-black text-indigo-600 bg-indigo-50 px-2 py-1 rounded">
                                    <?php echo htmlspecialchars($item['tracking_id']); ?>
                                </span>
                            </td>

                            <!-- Item -->
                            <td class="px-6 py-5">
                                <div class="flex items-center gap-3">
                                    <?php if ($img_src): ?>
                                    <div class="w-10 h-10 rounded-lg overflow-hidden flex-shrink-0 border border-slate-100 bg-slate-50">
                                        <img src="<?php echo $img_src; ?>" alt="Item"
                                             class="w-full h-full object-cover"
                                             onerror="this.style.display='none'">
                                    </div>
                                    <?php endif; ?>
                                    <p class="text-sm font-bold text-slate-800">
                                        <?php echo htmlspecialchars($item['item_title']); ?>
                                    </p>
                                </div>
                            </td>

                            <!-- Category -->
                            <td class="px-6 py-5">
                                <span class="text-[10px] font-black uppercase bg-slate-100 text-slate-500 px-2.5 py-1 rounded-md">
                                    <?php echo htmlspecialchars($item['category']); ?>
                                </span>
                            </td>

                            <!-- Claimant / Outcome -->
                            <td class="px-6 py-5">
                                <p class="text-sm font-bold text-slate-700">
                                    <?php echo htmlspecialchars($item['claimant_name']); ?>
                                </p>
                                <?php if (!empty($item['claimant_id_no'])): ?>
                                <p class="text-[10px] text-slate-400 font-mono">
                                    <?php echo htmlspecialchars($item['claimant_id_no']); ?>
                                </p>
                                <?php endif; ?>
                                <span class="inline-flex items-center gap-1 mt-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold border <?php echo $badge_cls; ?>">
                                    <i class="fas <?php echo $icon; ?> text-[9px]"></i>
                                    <?php echo htmlspecialchars($item['outcome']); ?>
                                </span>
                            </td>

                            <!-- Resolution Date -->
                            <td class="px-6 py-5 text-xs font-bold text-slate-500">
                                <?php echo $res_date; ?>
                            </td>

                            <!-- Officer -->
                            <td class="px-6 py-5 text-xs text-slate-500 font-semibold">
                                <?php echo htmlspecialchars($item['officer_name'] ?? 'System'); ?>
                            </td>

                            <!-- Actions -->
                            <td class="px-6 py-5 text-right">
                                <button onclick="openDetailModal(<?php echo htmlspecialchars(json_encode([
                                    'tracking_id'   => $item['tracking_id'],
                                    'item_title'    => $item['item_title'],
                                    'category'      => $item['category'],
                                    'found_location'=> $item['found_location'],
                                    'claimant_name' => $item['claimant_name'],
                                    'claimant_dept' => $item['claimant_dept'],
                                    'outcome'       => $item['outcome'],
                                    'resolved_date' => $res_date,
                                    'officer'       => $item['officer_name'] ?? 'System',
                                    'notes'         => $item['notes'] ?? '',
                                    'image'         => $img_src,
                                    'claimant_id_no' => $item['claimant_id_no'] ?? '',
                                    'claim_serial'    => $item['claim_serial'] ?? ''
                                ]), ENT_QUOTES); ?>)"
                                        class="text-slate-400 hover:text-cmu-blue transition p-1.5 rounded-lg hover:bg-blue-50"
                                        title="View details">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button onclick="window.print()"
                                        class="ml-1 text-slate-400 hover:text-slate-600 transition p-1.5 rounded-lg hover:bg-slate-100"
                                        title="Print record">
                                    <i class="fas fa-print"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-100 flex items-center justify-between">
                <p class="text-xs text-slate-500">
                    Showing <strong><?php echo min($offset + 1, $total_items); ?>–<?php echo min($offset + $per_page, $total_items); ?></strong>
                    of <strong><?php echo number_format($total_items); ?></strong> records
                </p>
                <div class="flex gap-1">
                    <a href="<?php echo htmlspecialchars(buildPageHref($page - 1)); ?>"
                       class="w-8 h-8 flex items-center justify-center rounded-lg text-xs border border-slate-200 bg-white
                              <?php echo $page <= 1 ? 'opacity-40 pointer-events-none' : 'hover:bg-slate-50 text-slate-600'; ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                    <a href="<?php echo htmlspecialchars(buildPageHref($p)); ?>"
                       class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold
                              <?php echo $p === $page ? 'bg-cmu-blue text-white shadow-sm' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50'; ?>">
                        <?php echo $p; ?>
                    </a>
                    <?php endfor; ?>
                    <a href="<?php echo htmlspecialchars(buildPageHref($page + 1)); ?>"
                       class="w-8 h-8 flex items-center justify-center rounded-lg text-xs border border-slate-200 bg-white
                              <?php echo $page >= $total_pages ? 'opacity-40 pointer-events-none' : 'hover:bg-slate-50 text-slate-600'; ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php else: /* ── Aging Items tab ───────────────────────────────── */ ?>

        <div class="bg-amber-50 border border-amber-100 p-5 rounded-2xl flex items-start gap-3">
            <i class="fas fa-info-circle text-amber-500 mt-0.5 flex-shrink-0"></i>
            <div>
                <p class="text-sm font-bold text-amber-800">University Retention Policy</p>
                <p class="text-xs text-amber-700 mt-1 leading-relaxed">
                    Items held for <strong>30+ days</strong> are flagged as Warning.
                    Items at <strong>45+ days</strong> are Critical and must be reviewed.
                    Items past <strong>60 days</strong> must be donated or disposed of immediately
                    in accordance with SAO regulations.
                </p>
            </div>
        </div>

        <?php if (empty($aging_items)): ?>
        <div class="bg-white rounded-3xl border border-slate-200 p-16 text-center shadow-sm">
            <div class="w-20 h-20 bg-green-50 text-green-300 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3 class="font-bold text-slate-700">No aging items right now</h3>
            <p class="text-sm text-slate-400 mt-1">All held items are within the 30-day threshold.</p>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse min-w-[700px]">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200">
                            <th class="archive-table-header px-6 py-4">Item</th>
                            <th class="archive-table-header px-6 py-4">Tracking ID</th>
                            <th class="archive-table-header px-6 py-4">Location</th>
                            <th class="archive-table-header px-6 py-4">Date Found</th>
                            <th class="archive-table-header px-6 py-4">Days Held</th>
                            <th class="archive-table-header px-6 py-4">Status</th>
                            <th class="archive-table-header px-6 py-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($aging_items as $ai):
                            $badge = ageBadge($ai['age_status']);
                        ?>
                        <tr class="hover:bg-slate-50/60 transition">
                            <td class="px-6 py-4">
                                <p class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($ai['title']); ?></p>
                                <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($ai['category']); ?></p>
                            </td>
                            <td class="px-6 py-4">
                                <span class="font-mono text-xs font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded">
                                    <?php echo htmlspecialchars($ai['tracking_id']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-xs text-slate-500">
                                <?php echo htmlspecialchars($ai['found_location']); ?>
                            </td>
                            <td class="px-6 py-4 text-xs text-slate-500 font-bold">
                                <?php echo date('M d, Y', strtotime($ai['date_found'])); ?>
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-sm font-black <?php echo (int)$ai['days_held'] >= 60 ? 'text-red-600' : ((int)$ai['days_held'] >= 45 ? 'text-orange-600' : 'text-amber-600'); ?>">
                                    <?php echo $ai['days_held']; ?> days
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold border <?php echo $badge; ?>">
                                    <i class="fas fa-clock text-[9px]"></i>
                                    <?php echo htmlspecialchars($ai['age_status']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <button onclick="openDisposeModal('<?php echo htmlspecialchars($ai['tracking_id']); ?>', <?php echo (int)$ai['found_id']; ?>, '<?php echo addslashes($ai['title']); ?>')"
                                        class="px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-white text-[10px] font-black rounded-lg uppercase tracking-tight transition shadow-sm">
                                    Process
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>

        <!-- Retention Policy footer banner -->
        <div class="p-6 bg-slate-800 rounded-3xl text-white flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-white/10 rounded-2xl flex items-center justify-center text-xl text-blue-300 flex-shrink-0">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div>
                    <p class="text-sm font-bold">University Retention Policy</p>
                    <p class="text-xs text-slate-400">Items are held for a maximum of 60 calendar days. Unclaimed items must be processed for donation or disposal per SAO regulations.</p>
                </div>
            </div>
            <a href="?tab=aging"
               class="flex-shrink-0 px-6 py-3 bg-blue-600 hover:bg-blue-500 rounded-xl text-xs font-bold transition">
                <i class="fas fa-clock mr-2"></i>Manage Aging Items
            </a>
        </div>

    </div><!-- end scrollable content -->
</main><!-- end main -->

<!-- ── Record Detail Modal ───────────────────────────────────────────────── -->
<div id="recordModal"
     class="fixed inset-0 z-[70] hidden bg-slate-900/70 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl w-full max-w-2xl max-h-[90vh] flex flex-col shadow-2xl overflow-hidden">

        <div class="flex-1 overflow-y-auto custom-scrollbar p-6">
            <div class="flex items-center justify-between p-6 border-b border-slate-100">
                <div>
                    <p id="rm-tracking" class="font-mono text-xs font-black text-indigo-600 mb-1"></p>
                    <p id="rm-serial" class="font-mono text-xs font-black text-indigo-600 mb-1"></p>
                    <h3 id="rm-title" class="text-lg font-black text-slate-800"></h3>
                </div>
                <button onclick="closeDetailModal()"
                        class="w-9 h-9 flex items-center justify-center rounded-full bg-slate-100 text-slate-500 hover:bg-red-100 hover:text-red-500 transition">
                    <i class="fas fa-times text-sm"></i>
                </button>
            </div>

            <div class="p-6 space-y-4">
                <!-- Image -->
                <div id="rm-image-wrap" class="hidden">
                    <img id="rm-image" src="" alt="Item photo"
                        class="w-full h-40 object-cover rounded-xl border border-slate-100">
                </div>

                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div class="bg-slate-50 rounded-xl p-3">
                        <p class="text-[10px] font-black text-slate-400 uppercase mb-1">Category</p>
                        <p id="rm-category" class="font-bold text-slate-700"></p>
                    </div>
                    <div class="bg-slate-50 rounded-xl p-3">
                        <p class="text-[10px] font-black text-slate-400 uppercase mb-1">Location</p>
                        <p id="rm-location" class="font-bold text-slate-700"></p>
                    </div>
                    <div class="bg-slate-50 rounded-xl p-3">
                        <p class="text-[10px] font-black text-slate-400 uppercase mb-1">Claimant</p>
                        <p id="rm-claimant" class="font-bold text-slate-700"></p>
                        <p id="rm-claimant-dept" class="text-[10px] text-slate-400 mt-0.5"></p>
                    </div>
                    <div class="bg-slate-50 rounded-xl p-3">
                        <p class="text-[10px] font-black text-slate-400 uppercase mb-1">Claimant ID Number</p>
                        <p id="rm-claimant-id" class="font-bold text-slate-700"></p>
                    </div>
                    <div class="bg-slate-50 rounded-xl p-3">
                        <p class="text-[10px] font-black text-slate-400 uppercase mb-1">Outcome</p>
                        <p id="rm-outcome" class="font-bold text-slate-700"></p>
                    </div>
                    <div class="bg-slate-50 rounded-xl p-3">
                        <p class="text-[10px] font-black text-slate-400 uppercase mb-1">Resolution Date</p>
                        <p id="rm-date" class="font-bold text-slate-700"></p>
                    </div>
                    <div class="bg-slate-50 rounded-xl p-3">
                        <p class="text-[10px] font-black text-slate-400 uppercase mb-1">Officer</p>
                        <p id="rm-officer" class="font-bold text-slate-700"></p>
                    </div>
                </div>

                <div id="rm-notes-wrap" class="hidden">
                    <p class="text-[10px] font-black text-slate-400 uppercase mb-1.5">Notes</p>
                    <p id="rm-notes" class="text-sm text-slate-600 bg-slate-50 rounded-xl p-3 italic"></p>
                </div>
            </div>

            <div class="p-6 pt-0">
                <button onclick="closeDetailModal()"
                        class="w-full py-3 bg-cmu-blue text-white rounded-xl font-bold text-sm hover:brightness-50 transition active:scale-95">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Dispose / Process Modal ──────────────────────────────────────────── -->
<div id="disposeModal"
     class="fixed inset-0 z-[70] hidden bg-slate-900/70 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl w-full max-w-md shadow-2xl p-8">
        <div class="w-14 h-14 bg-amber-100 text-amber-600 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl">
            <i class="fas fa-box-open"></i>
        </div>
        <h3 class="text-lg font-black text-slate-800 text-center mb-1">Process Aging Item</h3>
        <p class="text-sm text-slate-500 text-center mb-6">
            Select an outcome for <strong id="dm-title">—</strong>
            (<span id="dm-tracking" class="font-mono text-indigo-600"></span>)
        </p>

        <form id="disposeForm" method="POST" action="../core/process_archive.php" class="space-y-4">
            <input type="hidden" name="found_id"    id="dm-found-id">
            <input type="hidden" name="action"      value="archive_aging">

            <div>
                <label class="block text-xs font-black text-slate-500 uppercase mb-2">Outcome</label>
                <select name="outcome" required
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold outline-none">
                    <option value="Expired/Donated">Expired / Donated</option>
                    <option value="Disposed">Disposed / Destroyed</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-black text-slate-500 uppercase mb-2">Notes (optional)</label>
                <textarea name="notes" rows="2"
                          placeholder="e.g. Donated to Tondo Community Center..."
                          class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none resize-none"></textarea>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeDisposeModal()"
                        class="flex-1 py-3 bg-slate-100 text-slate-600 rounded-xl font-bold text-sm hover:bg-slate-200 transition">
                    Cancel
                </button>
                <button type="submit"
                        class="flex-1 py-3 bg-amber-500 text-white rounded-xl font-bold text-sm hover:bg-amber-600 transition shadow-sm">
                    Confirm & Archive
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Record Detail Modal ───────────────────────────────────────────────────
function openDetailModal(data) {
    document.getElementById('rm-tracking').textContent  = data.tracking_id;
    document.getElementById('rm-title').textContent     = data.item_title;
    document.getElementById('rm-category').textContent  = data.category;
    document.getElementById('rm-location').textContent  = data.found_location || '—';
    document.getElementById('rm-claimant').textContent  = data.claimant_name;
    document.getElementById('rm-claimant-dept').textContent = data.claimant_dept || '';
    document.getElementById('rm-outcome').textContent   = data.outcome;
    document.getElementById('rm-date').textContent      = data.resolved_date;
    document.getElementById('rm-officer').textContent   = data.officer;
    document.getElementById('rm-serial').textContent    = data.claim_serial || '—';
    document.getElementById('rm-claimant-id').textContent    = data.claimant_id_no || '—';

    const imgWrap = document.getElementById('rm-image-wrap');
    const img     = document.getElementById('rm-image');
    if (data.image) {
        img.src = data.image;
        imgWrap.classList.remove('hidden');
    } else {
        imgWrap.classList.add('hidden');
    }

    const notesWrap = document.getElementById('rm-notes-wrap');
    if (data.notes) {
        document.getElementById('rm-notes').textContent = data.notes;
        notesWrap.classList.remove('hidden');
    } else {
        notesWrap.classList.add('hidden');
    }

    document.getElementById('recordModal').classList.remove('hidden');
}

function closeDetailModal() {
    document.getElementById('recordModal').classList.add('hidden');
}

// ── Dispose Modal ─────────────────────────────────────────────────────────
function openDisposeModal(trackingId, foundId, title) {
    document.getElementById('dm-tracking').textContent = trackingId;
    document.getElementById('dm-title').textContent    = title;
    document.getElementById('dm-found-id').value       = foundId;
    document.getElementById('disposeModal').classList.remove('hidden');
}

function closeDisposeModal() {
    document.getElementById('disposeModal').classList.add('hidden');
}

// Close modals on backdrop click
['recordModal', 'disposeModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.classList.add('hidden');
    });
});

// Close on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeDetailModal();
        closeDisposeModal();
    }
});
</script>

</body>
</html>