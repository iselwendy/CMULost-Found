<?php
/**
 * CMU Lost & Found — Manage Reports
 * admin/manage_reports.php
 *
 * Admin tool to review ALL active gallery reports (found + lost) and
 * issue an Admin Void on fraudulent / joke submissions.
 *
 * Void sets found_reports.status = 'void' or lost_reports.status = 'void',
 * which is excluded from the public gallery and all matching.
 *
 * The void action also:
 *   ① Updates the report status to 'void'
 *   ② Logs the reason in admin_action_log
 *   ③ Deletes any pending matches tied to that report
 */

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../core/auth.php");
    exit();
}

require_once '../core/db_config.php';

$admin_id   = (int) $_SESSION['user_id'];
$admin_name = htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['user_name'] ?? 'Admin');

// ── Ensure 'void' exists in the status ENUM for both tables ──────────────────
// ALTER TABLE is idempotent-safe: if 'void' already exists MySQL ignores it.
try {
    $pdo->exec("
        ALTER TABLE found_reports
        MODIFY COLUMN status ENUM(
            'in custody','matched','surrendered','claimed',
            'returned','disposed','void'
        ) NOT NULL DEFAULT 'in custody'
    ");
    $pdo->exec("
        ALTER TABLE lost_reports
        MODIFY COLUMN status ENUM(
            'open','matched','resolved','closed','void'
        ) NOT NULL DEFAULT 'open'
    ");
} catch (PDOException $e) {
    // If ENUM already includes 'void', the ALTER may fail gracefully
    error_log('[manage_reports] ALTER note: ' . $e->getMessage());
}

// ── Handle VOID POST ──────────────────────────────────────────────────────────
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'void_report') {
    $report_type = $_POST['report_type'] ?? '';
    $report_id   = (int)($_POST['report_id'] ?? 0);
    $void_reason = trim($_POST['void_reason'] ?? 'No reason provided.');

    if ($report_id > 0 && in_array($report_type, ['found', 'lost'])) {
        try {
            $pdo->beginTransaction();

            if ($report_type === 'found') {
                $pdo->prepare("UPDATE found_reports SET status = 'void' WHERE found_id = ?")
                    ->execute([$report_id]);
                $pdo->prepare("DELETE FROM matches WHERE found_id = ?")
                    ->execute([$report_id]);
                $tracking_id = 'FND-' . str_pad($report_id, 5, '0', STR_PAD_LEFT);
            } else {
                $pdo->prepare("UPDATE lost_reports SET status = 'void' WHERE lost_id = ?")
                    ->execute([$report_id]);
                $pdo->prepare("DELETE FROM matches WHERE lost_id = ?")
                    ->execute([$report_id]);
                $tracking_id = 'LST-' . str_pad($report_id, 5, '0', STR_PAD_LEFT);
            }

            $pdo->prepare("
                INSERT INTO admin_action_log
                    (admin_id, action_type, target_type, target_id, description)
                VALUES (?, 'report_voided', ?, ?, ?)
            ")->execute([
                $admin_id,
                $report_type,
                $report_id,
                "Admin voided {$tracking_id}: {$void_reason}",
            ]);

            $pdo->commit();
            $flash = ['type' => 'success', 'text' => "Report {$tracking_id} has been voided. It will no longer appear in the gallery."];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $flash = ['type' => 'error', 'text' => 'Void failed: ' . $e->getMessage()];
        }
    }
}

// ── Handle RESTORE (un-void) POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore_report') {
    $report_type = $_POST['report_type'] ?? '';
    $report_id   = (int)($_POST['report_id'] ?? 0);

    if ($report_id > 0 && in_array($report_type, ['found', 'lost'])) {
        try {
            if ($report_type === 'found') {
                $pdo->prepare("UPDATE found_reports SET status = 'in custody' WHERE found_id = ?")
                    ->execute([$report_id]);
                $tracking_id = 'FND-' . str_pad($report_id, 5, '0', STR_PAD_LEFT);
            } else {
                $pdo->prepare("UPDATE lost_reports SET status = 'open' WHERE lost_id = ?")
                    ->execute([$report_id]);
                $tracking_id = 'LST-' . str_pad($report_id, 5, '0', STR_PAD_LEFT);
            }

            $pdo->prepare("
                INSERT INTO admin_action_log
                    (admin_id, action_type, target_type, target_id, description)
                VALUES (?, 'report_restored', ?, ?, ?)
            ")->execute([
                $admin_id,
                $report_type,
                $report_id,
                "Admin restored voided report {$tracking_id}",
            ]);

            $flash = ['type' => 'success', 'text' => "Report {$tracking_id} has been restored to active status."];
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'text' => 'Restore failed: ' . $e->getMessage()];
        }
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$view_type   = $_GET['view']     ?? 'all';      // all | found | lost | voided
$search      = trim($_GET['search'] ?? '');
$category_f  = $_GET['category'] ?? '';
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 15;
$offset      = ($page - 1) * $per_page;

// ── Fetch categories for filter ───────────────────────────────────────────────
$categories = $pdo->query("SELECT category_id, name FROM categories ORDER BY name ASC")->fetchAll();

// ── Build unified query ───────────────────────────────────────────────────────
$found_status_filter = "f.status NOT IN ('claimed','disposed','returned')";
$lost_status_filter  = "l.status NOT IN ('resolved','closed')";

if ($view_type === 'voided') {
    $found_status_filter = "f.status = 'void'";
    $lost_status_filter  = "l.status = 'void'";
} elseif ($view_type === 'found') {
    $found_status_filter = "f.status NOT IN ('claimed','disposed','returned','void')";
    $lost_status_filter  = "1=0"; // exclude lost
} elseif ($view_type === 'lost') {
    $found_status_filter = "1=0"; // exclude found
    $lost_status_filter  = "l.status NOT IN ('resolved','closed','void')";
} elseif ($view_type === 'all') {
    $found_status_filter = "f.status NOT IN ('claimed','disposed','returned','void')";
    $lost_status_filter  = "l.status NOT IN ('resolved','closed','void')";
}

$search_clause = '';
$cat_clause    = '';
$search_params = [];

if (!empty($search)) {
    $search_clause = "AND (title LIKE :search OR reporter_name LIKE :search)";
    $search_params[':search'] = '%' . $search . '%';
}
if (!empty($category_f)) {
    $cat_clause = "AND category_name = :cat";
    $search_params[':cat'] = $category_f;
}

// Unified query
$base_sql = "
    SELECT * FROM (
        SELECT
            f.found_id   AS report_id,
            'found'      AS report_type,
            CONCAT('FND-', LPAD(f.found_id, 5, '0')) AS tracking_id,
            f.title,
            f.status,
            f.date_found AS event_date,
            f.created_at,
            f.private_description,
            COALESCE(c.name, 'Other') AS category_name,
            COALESCE(loc.location_name, 'Unknown') AS location_name,
            u.full_name  AS reporter_name,
            u.cmu_email  AS reporter_email,
            u.department AS reporter_dept,
            img.image_path
        FROM found_reports f
        LEFT JOIN users      u   ON f.reported_by  = u.user_id
        LEFT JOIN categories c   ON f.category_id  = c.category_id
        LEFT JOIN locations  loc ON f.location_id  = loc.location_id
        LEFT JOIN (
            SELECT report_id, image_path FROM item_images
            WHERE report_type = 'found' GROUP BY report_id
        ) img ON img.report_id = f.found_id
        WHERE {$found_status_filter}

        UNION ALL

        SELECT
            l.lost_id    AS report_id,
            'lost'       AS report_type,
            CONCAT('LST-', LPAD(l.lost_id, 5, '0')) AS tracking_id,
            l.title,
            l.status,
            l.date_lost  AS event_date,
            l.created_at,
            l.private_description,
            COALESCE(c.name, 'Other') AS category_name,
            COALESCE(loc.location_name, 'Unknown') AS location_name,
            u.full_name  AS reporter_name,
            u.cmu_email  AS reporter_email,
            u.department AS reporter_dept,
            img.image_path
        FROM lost_reports l
        LEFT JOIN users      u   ON l.user_id       = u.user_id
        LEFT JOIN categories c   ON l.category_id   = c.category_id
        LEFT JOIN locations  loc ON l.location_id   = loc.location_id
        LEFT JOIN (
            SELECT report_id, image_path FROM item_images
            WHERE report_type = 'lost' GROUP BY report_id
        ) img ON img.report_id = l.lost_id
        WHERE {$lost_status_filter}
    ) AS combined
    WHERE 1=1
    {$search_clause}
    {$cat_clause}
";

// Count
try {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM ({$base_sql}) AS counted");
    $count_stmt->execute($search_params);
    $total_items = (int) $count_stmt->fetchColumn();
    $total_pages = max(1, (int) ceil($total_items / $per_page));
} catch (PDOException $e) {
    $total_items = 0;
    $total_pages = 1;
}

// Rows
$reports = [];
try {
    $params_with_pagination = array_merge($search_params, [':limit' => $per_page, ':offset' => $offset]);
    $stmt = $pdo->prepare($base_sql . " ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
    foreach ($params_with_pagination as $key => $val) {
        $type = in_array($key, [':limit', ':offset']) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($key, $val, $type);
    }
    $stmt->execute();
    $reports = $stmt->fetchAll();
} catch (PDOException $e) {
    $db_error = $e->getMessage();
}

// ── Summary counts ─────────────────────────────────────────────────────────────
try {
    $counts = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM found_reports WHERE status NOT IN ('claimed','disposed','returned','void')) AS active_found,
            (SELECT COUNT(*) FROM lost_reports WHERE status NOT IN ('resolved','closed','void')) AS active_lost,
            (SELECT COUNT(*) FROM found_reports WHERE status = 'void') +
            (SELECT COUNT(*) FROM lost_reports WHERE status = 'void') AS voided
    ")->fetch();
} catch (PDOException $e) {
    $counts = ['active_found' => 0, 'active_lost' => 0, 'voided' => 0];
}

// ── Status badge helpers ───────────────────────────────────────────────────────
function getStatusBadge(string $status, string $type): string {
    if ($status === 'void') return 'bg-red-100 text-red-700 border-red-200';
    if ($type === 'found') {
        return match($status) {
            'in custody'  => 'bg-amber-100 text-amber-700 border-amber-200',
            'surrendered' => 'bg-blue-100 text-blue-700 border-blue-200',
            'matched'     => 'bg-indigo-100 text-indigo-700 border-indigo-200',
            default       => 'bg-slate-100 text-slate-500 border-slate-200',
        };
    }
    return match($status) {
        'open'    => 'bg-blue-100 text-blue-700 border-blue-200',
        'matched' => 'bg-indigo-100 text-indigo-700 border-indigo-200',
        default   => 'bg-slate-100 text-slate-500 border-slate-200',
    };
}

function getStatusLabel(string $status): string {
    return match($status) {
        'in custody'  => 'Pending Turnover',
        'surrendered' => 'In Custody',
        'open'        => 'Active Search',
        'void'        => '⊘ VOIDED',
        default       => ucfirst($status),
    };
}

function getCategoryIcon(string $cat): string {
    return match($cat) {
        'Electronics' => 'fa-laptop',
        'Valuables'   => 'fa-wallet',
        'Documents'   => 'fa-id-card',
        'Books'       => 'fa-book',
        'Clothing'    => 'fa-shirt',
        'Personal'    => 'fa-bag-shopping',
        default       => 'fa-box',
    };
}

function buildPageUrl(array $extra = []): string {
    return '?' . http_build_query(array_merge($_GET, $extra));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Reports | OSA Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/styles/root.css">
    <link rel="stylesheet" href="../assets/styles/admin_dashboard.css">
    <style>
        /* ── Void animation ── */
        @keyframes voidPulse {
            0%, 100% { opacity: 1; }
            50%       { opacity: .5; }
        }
        .voided-row {
            background: repeating-linear-gradient(
                -45deg,
                transparent,
                transparent 8px,
                rgba(239,68,68,.04) 8px,
                rgba(239,68,68,.04) 16px
            );
        }
        .voided-row td { opacity: .6; }

        /* ── Red void confirm modal ── */
        #voidModal .modal-inner {
            animation: slideUp .25s cubic-bezier(.34,1.56,.64,1);
        }
        @keyframes slideUp {
            from { transform: translateY(24px); opacity: 0; }
            to   { transform: translateY(0);    opacity: 1; }
        }

        /* ── Table header ── */
        .tbl-th {
            font-size: 10px;
            font-weight: 900;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: .1em;
            padding: 14px 20px;
            white-space: nowrap;
        }

        .tbl-td {
            padding: 14px 20px;
            vertical-align: middle;
        }

        /* ── Filter pill buttons ── */
        .pill-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 99px;
            font-size: 12px;
            font-weight: 700;
            border: 1.5px solid #e2e8f0;
            background: white;
            color: #64748b;
            cursor: pointer;
            transition: all .15s;
            text-decoration: none;
        }
        .pill-btn:hover { border-color: #94a3b8; color: #334155; }
        .pill-btn.active { background: #003366; border-color: #003366; color: white; }
        .pill-btn.active-voided { background: #dc2626; border-color: #dc2626; color: white; }

        /* ── Reason textarea ── */
        #voidReason:focus { outline: none; border-color: #dc2626; box-shadow: 0 0 0 2px rgba(220,38,38,.2); }

        .sidebar-link.active {
            background-color: rgba(255,255,255,.2);
            border-left: 4px solid var(--cmu-gold);
        }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex">

<!-- ── Sidebar ────────────────────────────────────────────────────────────── -->
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
        <a href="manage_reports.php"  class="sidebar-link active flex items-center gap-3 p-3 rounded-xl transition"><i class="fas fa-shield w-5"></i><span class="text-sm font-medium">Manage Reports</span></a>
        <div class="pt-4 mt-4 border-t border-white/10">
            <a href="archive.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition"><i class="fas fa-archive w-5 text-blue-300"></i><span class="text-sm font-medium text-blue-100">Records Archive</span></a>
            <a href="record_merger.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition mt-1"><i class="fas fa-code-merge w-5 text-blue-300"></i><span class="text-sm font-medium text-blue-100">Record Merger</span></a>
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

<!-- ── Main ───────────────────────────────────────────────────────────────── -->
<main class="flex-grow flex flex-col min-w-0 h-screen overflow-y-auto">

    <!-- Header -->
    <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between sticky top-0 z-10 gap-4">
        <div>
            <h2 class="text-xl font-black text-slate-800 tracking-tight uppercase">Manage Reports</h2>
            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5">
                Review gallery reports · Issue Admin Void for fraudulent or joke submissions
            </p>
        </div>
        <div class="hidden md:flex flex-col text-right">
            <span class="text-xs font-bold text-slate-400"><?php echo date('l, F j, Y'); ?></span>
            <span class="text-[10px] text-green-500 font-black uppercase"><i class="fas fa-circle text-[6px] mr-1"></i> System Online</span>
        </div>
    </header>

    <!-- Flash banner -->
    <?php if ($flash): ?>
    <div id="flashBanner" class="px-8 py-3 flex items-center gap-3 text-sm font-semibold
        <?php echo $flash['type'] === 'success' ? 'bg-green-50 border-b border-green-100 text-green-800' : 'bg-red-50 border-b border-red-100 text-red-700'; ?>">
        <i class="fas <?php echo $flash['type'] === 'success' ? 'fa-check-circle text-green-500' : 'fa-exclamation-circle text-red-500'; ?>"></i>
        <?php echo htmlspecialchars($flash['text']); ?>
        <button onclick="this.parentElement.remove()" class="ml-auto opacity-50 hover:opacity-100 text-xs">✕</button>
    </div>
    <?php endif; ?>

    <div class="p-8 space-y-6">

        <!-- ── Stat Cards ──────────────────────────────────────────────────── -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
                <div class="w-10 h-10 bg-green-100 text-green-600 rounded-xl flex items-center justify-center mb-3"><i class="fas fa-hand-holding-heart"></i></div>
                <p class="text-2xl font-black text-slate-800"><?php echo number_format($counts['active_found']); ?></p>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">Active Found Reports</p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
                <div class="w-10 h-10 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center mb-3"><i class="fas fa-search"></i></div>
                <p class="text-2xl font-black text-slate-800"><?php echo number_format($counts['active_lost']); ?></p>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">Active Lost Reports</p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
                <div class="w-10 h-10 bg-slate-100 text-slate-500 rounded-xl flex items-center justify-center mb-3"><i class="fas fa-list"></i></div>
                <p class="text-2xl font-black text-slate-800"><?php echo number_format($counts['active_found'] + $counts['active_lost']); ?></p>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">Total Active Reports</p>
            </div>
            <div class="bg-red-50 rounded-2xl border border-red-100 p-5">
                <div class="w-10 h-10 bg-red-100 text-red-600 rounded-xl flex items-center justify-center mb-3"><i class="fas fa-ban"></i></div>
                <p class="text-2xl font-black text-red-700"><?php echo number_format($counts['voided']); ?></p>
                <p class="text-[10px] font-bold text-red-400 uppercase tracking-widest mt-0.5">Voided Reports</p>
            </div>
        </div>

        <!-- ── Filter Bar ──────────────────────────────────────────────────── -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-5">
            <form method="GET" action="manage_reports.php" class="flex flex-wrap gap-3 items-center">
                <input type="hidden" name="view" value="<?php echo htmlspecialchars($view_type); ?>">

                <!-- Search -->
                <div class="relative flex-grow min-w-[220px]">
                    <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-300 text-sm"></i>
                    <input type="text" name="search"
                           value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="Search by title or reporter name..."
                           class="w-full pl-11 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-sm outline-none focus:ring-2 focus:ring-cmu-blue transition">
                </div>

                <!-- Category filter -->
                <select name="category"
                        class="bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3 text-sm font-bold text-slate-600 outline-none appearance-none cursor-pointer">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat['name']); ?>"
                            <?php echo $category_f === $cat['name'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="px-6 py-3 bg-cmu-blue text-white rounded-2xl text-sm font-bold hover:bg-slate-800 transition">
                    <i class="fas fa-filter mr-2"></i>Filter
                </button>

                <?php if ($search || $category_f): ?>
                <a href="<?php echo buildPageUrl(['search' => '', 'category' => '', 'page' => 1]); ?>"
                   class="text-xs text-slate-400 hover:text-red-500 font-bold transition flex items-center gap-1">
                    <i class="fas fa-times"></i> Clear
                </a>
                <?php endif; ?>
            </form>

            <!-- View type pills -->
            <div class="flex flex-wrap gap-2 mt-4 pt-4 border-t border-slate-100">
                <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest self-center mr-1">Show:</span>
                <?php
                $viewTabs = [
                    'all'    => ['label' => 'All Active',    'icon' => 'fa-list',             'count' => $counts['active_found'] + $counts['active_lost']],
                    'found'  => ['label' => 'Found Items',   'icon' => 'fa-hand-holding-heart','count' => $counts['active_found']],
                    'lost'   => ['label' => 'Lost Reports',  'icon' => 'fa-search',            'count' => $counts['active_lost']],
                    'voided' => ['label' => 'Voided',        'icon' => 'fa-ban',               'count' => $counts['voided']],
                ];
                foreach ($viewTabs as $key => $tab):
                    $isActive = $view_type === $key;
                    $cls = $isActive
                        ? ($key === 'voided' ? 'pill-btn active-voided' : 'pill-btn active')
                        : 'pill-btn';
                    $href = buildPageUrl(['view' => $key, 'page' => 1]);
                ?>
                <a href="<?php echo htmlspecialchars($href); ?>" class="<?php echo $cls; ?>">
                    <i class="fas <?php echo $tab['icon']; ?> text-[10px]"></i>
                    <?php echo $tab['label']; ?>
                    <span class="<?php echo $isActive ? 'bg-white/30' : 'bg-slate-100'; ?> px-1.5 py-0.5 rounded-full text-[9px] font-black">
                        <?php echo $tab['count']; ?>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── Reports Table ───────────────────────────────────────────────── -->
        <?php if (isset($db_error)): ?>
        <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-2xl text-sm flex items-center gap-2">
            <i class="fas fa-exclamation-triangle"></i> Database error: <?php echo htmlspecialchars($db_error); ?>
        </div>
        <?php endif; ?>

        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">

            <?php if (empty($reports)): ?>
            <div class="py-20 text-center">
                <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-folder-open text-slate-200 text-3xl"></i>
                </div>
                <p class="font-bold text-slate-500">No reports found</p>
                <p class="text-xs text-slate-400 mt-1">
                    <?php echo $search || $category_f ? 'Try adjusting your filters.' : 'There are no reports matching this view.'; ?>
                </p>
            </div>
            <?php else: ?>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse min-w-[1100px]">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200">
                            <th class="tbl-th">Report</th>
                            <th class="tbl-th">Type</th>
                            <th class="tbl-th">Reporter</th>
                            <th class="tbl-th">Location</th>
                            <th class="tbl-th">Date</th>
                            <th class="tbl-th">Status</th>
                            <th class="tbl-th text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">

                    <?php foreach ($reports as $row):
                        $is_voided  = $row['status'] === 'void';
                        $badge_cls  = getStatusBadge($row['status'], $row['report_type']);
                        $badge_lbl  = getStatusLabel($row['status']);
                        $cat_icon   = getCategoryIcon($row['category_name'] ?? 'Other');
                        $date_fmt   = !empty($row['event_date'])
                            ? date('M d, Y', strtotime($row['event_date']))
                            : '—';
                        $created_fmt = !empty($row['created_at'])
                            ? date('M d, Y', strtotime($row['created_at']))
                            : '—';
                        $img_src = !empty($row['image_path'])
                            ? '../' . htmlspecialchars($row['image_path'])
                            : null;
                        $row_cls = $is_voided ? 'voided-row hover:bg-red-50/30' : 'hover:bg-slate-50/80';
                    ?>
                    <tr class="transition group <?php echo $row_cls; ?>">

                        <!-- Report Info -->
                        <td class="tbl-td">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 rounded-xl bg-slate-100 overflow-hidden flex-shrink-0 border border-slate-100 flex items-center justify-center text-slate-400">
                                    <?php if ($img_src): ?>
                                        <img src="<?php echo $img_src; ?>" alt="Item"
                                             class="w-full h-full object-cover <?php echo $is_voided ? 'grayscale' : ''; ?>"
                                             onerror="this.parentElement.innerHTML='<i class=\'fas <?php echo $cat_icon; ?> text-lg\'></i>'">
                                    <?php else: ?>
                                        <i class="fas <?php echo $cat_icon; ?> text-lg"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-slate-800 truncate max-w-[220px]
                                       <?php echo $is_voided ? 'line-through text-slate-400' : ''; ?>">
                                        <?php echo htmlspecialchars($row['title']); ?>
                                    </p>
                                    <p class="font-mono text-[10px] text-indigo-400 mt-0.5">
                                        <?php echo htmlspecialchars($row['tracking_id']); ?>
                                    </p>
                                    <p class="text-[10px] text-slate-400 mt-0.5 flex items-center gap-1">
                                        <i class="fas <?php echo $cat_icon; ?> text-[8px]"></i>
                                        <?php echo htmlspecialchars($row['category_name']); ?>
                                    </p>
                                </div>
                            </div>
                        </td>

                        <!-- Type badge -->
                        <td class="tbl-td">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-black uppercase
                                <?php echo $row['report_type'] === 'found'
                                    ? 'bg-green-100 text-green-700'
                                    : 'bg-red-100 text-red-600'; ?>">
                                <i class="fas <?php echo $row['report_type'] === 'found' ? 'fa-hand-holding-heart' : 'fa-search'; ?> text-[8px]"></i>
                                <?php echo $row['report_type']; ?>
                            </span>
                        </td>

                        <!-- Reporter -->
                        <td class="tbl-td">
                            <p class="text-sm font-semibold text-slate-700 truncate max-w-[150px]">
                                <?php echo htmlspecialchars($row['reporter_name'] ?? '—'); ?>
                            </p>
                            <p class="text-[10px] text-slate-400 truncate max-w-[150px]">
                                <?php echo htmlspecialchars($row['reporter_dept'] ?? ''); ?>
                            </p>
                        </td>

                        <!-- Location -->
                        <td class="tbl-td text-xs text-slate-500 font-medium">
                            <i class="fas fa-map-marker-alt mr-1 text-slate-300"></i>
                            <?php echo htmlspecialchars($row['location_name']); ?>
                        </td>

                        <!-- Date -->
                        <td class="tbl-td">
                            <p class="text-xs font-bold text-slate-600"><?php echo $date_fmt; ?></p>
                            <p class="text-[10px] text-slate-400">Reported: <?php echo $created_fmt; ?></p>
                        </td>

                        <!-- Status -->
                        <td class="tbl-td">
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold border
                                <?php echo $badge_cls; ?>">
                                <?php if ($is_voided): ?>
                                    <i class="fas fa-ban text-[8px]"></i>
                                <?php endif; ?>
                                <?php echo $badge_lbl; ?>
                            </span>
                        </td>

                        <!-- Actions -->
                        <td class="tbl-td text-right">
                            <div class="flex items-center justify-end gap-2">
                                <!-- Preview description toggle -->
                                <?php if (!empty($row['private_description'])): ?>
                                <button type="button"
                                        title="Preview private description"
                                        onclick="toggleDesc(this)"
                                        data-desc="<?php echo htmlspecialchars($row['private_description'], ENT_QUOTES); ?>"
                                        class="p-2 text-slate-400 hover:text-cmu-blue hover:bg-blue-50 rounded-lg transition">
                                    <i class="fas fa-eye text-xs"></i>
                                </button>
                                <?php endif; ?>

                                <?php if ($is_voided): ?>
                                <!-- RESTORE button -->
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action"      value="restore_report">
                                    <input type="hidden" name="report_type" value="<?php echo $row['report_type']; ?>">
                                    <input type="hidden" name="report_id"   value="<?php echo $row['report_id']; ?>">
                                    <button type="submit"
                                            title="Restore report"
                                            onclick="return confirm('Restore this report to active status?')"
                                            class="flex items-center gap-1.5 px-3 py-1.5 bg-green-500 text-white text-[10px] font-black rounded-lg hover:bg-green-600 transition shadow-sm">
                                        <i class="fas fa-rotate-left text-[9px]"></i>
                                        Restore
                                    </button>
                                </form>
                                <?php else: ?>
                                <!-- VOID button -->
                                <button type="button"
                                        onclick="openVoidModal('<?php echo $row['report_type']; ?>', <?php echo $row['report_id']; ?>, '<?php echo addslashes($row['title']); ?>', '<?php echo $row['tracking_id']; ?>')"
                                        class="flex items-center gap-1.5 px-3 py-1.5 border border-red-200 text-red-600 text-[10px] font-black rounded-lg hover:bg-red-50 transition">
                                    <i class="fas fa-ban text-[9px]"></i>
                                    Admin Void
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>

                    <!-- Expandable description row (hidden by default) -->
                    <tr class="desc-row hidden <?php echo $is_voided ? 'voided-row' : ''; ?>">
                        <td colspan="7" class="px-20 pb-4 pt-0">
                            <div class="desc-content bg-amber-50 border border-amber-100 rounded-2xl p-4 text-xs text-amber-800 italic leading-relaxed">
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-100 flex items-center justify-between">
                <p class="text-xs text-slate-500">
                    Showing
                    <strong><?php echo min($offset + 1, $total_items); ?>–<?php echo min($offset + $per_page, $total_items); ?></strong>
                    of <strong><?php echo number_format($total_items); ?></strong> reports
                </p>
                <div class="flex gap-1">
                    <a href="<?php echo htmlspecialchars(buildPageUrl(['page' => $page - 1])); ?>"
                       class="w-8 h-8 flex items-center justify-center rounded-lg text-xs border border-slate-200 bg-white
                              <?php echo $page <= 1 ? 'opacity-40 pointer-events-none' : 'hover:bg-slate-50 text-slate-600'; ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                    <a href="<?php echo htmlspecialchars(buildPageUrl(['page' => $p])); ?>"
                       class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold
                              <?php echo $p === $page ? 'bg-cmu-blue text-white shadow-sm' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50'; ?>">
                        <?php echo $p; ?>
                    </a>
                    <?php endfor; ?>
                    <a href="<?php echo htmlspecialchars(buildPageUrl(['page' => $page + 1])); ?>"
                       class="w-8 h-8 flex items-center justify-center rounded-lg text-xs border border-slate-200 bg-white
                              <?php echo $page >= $total_pages ? 'opacity-40 pointer-events-none' : 'hover:bg-slate-50 text-slate-600'; ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>

        <!-- ── Info Banner ─────────────────────────────────────────────────── -->
        <div class="bg-slate-800 text-white rounded-3xl p-6 flex flex-col md:flex-row items-start md:items-center gap-5">
            <div class="w-12 h-12 bg-red-500/20 text-red-300 rounded-2xl flex items-center justify-center text-xl flex-shrink-0">
                <i class="fas fa-ban"></i>
            </div>
            <div class="flex-1">
                <p class="font-bold text-sm">What does Admin Void do?</p>
                <p class="text-slate-400 text-xs mt-1 leading-relaxed">
                    Voiding a report hides it from the public gallery and the matching engine — the reporter's account and submission record remain intact for accountability.
                    Use this for joke reports (e.g. "Found a dinosaur"), offensive titles, test submissions, or clear duplicates that cannot be merged.
                    Voided reports are never permanently deleted and can be restored if needed.
                </p>
            </div>
            <a href="?view=voided" class="flex-shrink-0 px-5 py-2.5 bg-red-600 hover:bg-red-500 rounded-xl text-xs font-bold transition">
                <i class="fas fa-list mr-2"></i>View All Voided
            </a>
        </div>

    </div><!-- /.p-8 -->
</main>

<!-- ══════════════════════════════════════════════════════════════════════════
     VOID CONFIRMATION MODAL
══════════════════════════════════════════════════════════════════════════ -->
<div id="voidModal"
     class="fixed inset-0 z-[80] hidden items-center justify-center p-4 bg-slate-900/70 backdrop-blur-sm">

    <div class="modal-inner bg-white rounded-3xl w-full max-w-md shadow-2xl overflow-hidden border-2 border-red-200">

        <!-- Modal header -->
        <div class="bg-red-600 px-7 py-6 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center">
                    <i class="fas fa-ban text-white text-lg"></i>
                </div>
                <div>
                    <h3 class="text-white font-black text-sm uppercase tracking-widest leading-none">Admin Void Report</h3>
                    <p class="text-red-200 text-[10px] mt-0.5 font-semibold">This action is logged and reversible.</p>
                </div>
            </div>
            <button onclick="closeVoidModal()" class="w-9 h-9 flex items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 transition">
                <i class="fas fa-times text-sm"></i>
            </button>
        </div>

        <!-- Modal body -->
        <form method="POST" id="voidForm" class="px-7 py-6 space-y-5">
            <input type="hidden" name="action"      value="void_report">
            <input type="hidden" name="report_type" id="voidReportType">
            <input type="hidden" name="report_id"   id="voidReportId">

            <!-- Target info -->
            <div class="bg-red-50 border border-red-100 rounded-2xl p-4">
                <p class="text-[10px] font-black text-red-400 uppercase tracking-widest mb-1">Report to be Voided</p>
                <p id="voidReportTitle" class="text-base font-black text-red-900"></p>
                <p id="voidTrackingId" class="font-mono text-xs text-red-500 mt-0.5"></p>
            </div>

            <!-- Reason -->
            <div>
                <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">
                    Reason for Voiding <span class="text-red-400">*</span>
                </label>
                <textarea id="voidReason" name="void_reason" required rows="3"
                          placeholder="e.g. Joke submission — student claimed to have found a T-Rex in the canteen. No legitimate item described."
                          class="w-full px-4 py-3 bg-slate-50 border-2 border-slate-200 rounded-xl text-sm leading-relaxed resize-none transition"></textarea>
                <p class="text-[10px] text-slate-400 mt-1">This reason is saved to the admin action log for audit purposes.</p>
            </div>

            <!-- Quick reason presets -->
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Quick Presets:</p>
                <div class="flex flex-wrap gap-2">
                    <?php
                    $presets = [
                        'Joke / clearly fictional report',
                        'Offensive or inappropriate content',
                        'Test/spam submission',
                        'Exact duplicate report',
                        'Item description is incoherent',
                    ];
                    foreach ($presets as $preset):
                    ?>
                    <button type="button"
                            onclick="document.getElementById('voidReason').value='<?php echo addslashes($preset); ?>'"
                            class="text-[10px] font-bold px-2.5 py-1.5 bg-slate-100 text-slate-600 rounded-lg hover:bg-slate-200 transition border border-slate-200">
                        <?php echo $preset; ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Warning notice -->
            <div class="bg-amber-50 border border-amber-100 rounded-2xl p-4 flex gap-3">
                <i class="fas fa-triangle-exclamation text-amber-500 mt-0.5 flex-shrink-0 text-sm"></i>
                <div class="text-xs text-amber-800 leading-relaxed">
                    <p><strong>What will happen:</strong></p>
                    <p class="mt-1">① The report is hidden from the public gallery and matching engine.<br>
                    ② All pending matches linked to this report are deleted.<br>
                    ③ The reporter's account is <strong>not</strong> suspended — only this report is voided.<br>
                    ④ The action is logged with your name and reason.</p>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex gap-3 pt-1">
                <button type="button" onclick="closeVoidModal()"
                        class="flex-1 py-3 border border-slate-200 text-slate-600 rounded-xl font-bold text-sm hover:bg-slate-50 transition">
                    Cancel
                </button>
                <button type="submit"
                        class="flex-1 py-3 bg-red-600 text-white rounded-xl font-black text-sm hover:bg-red-700 transition shadow-sm flex items-center justify-center gap-2 active:scale-[.98]">
                    <i class="fas fa-ban"></i>
                    Confirm Void
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Description tooltip panel (appears below a row) ───────────────────── -->
<script>
// ── Void modal ─────────────────────────────────────────────────────────────
function openVoidModal(type, id, title, trackingId) {
    document.getElementById('voidReportType').value  = type;
    document.getElementById('voidReportId').value    = id;
    document.getElementById('voidReportTitle').textContent  = title;
    document.getElementById('voidTrackingId').textContent   = trackingId;
    document.getElementById('voidReason').value      = '';

    const modal = document.getElementById('voidModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';
}

function closeVoidModal() {
    const modal = document.getElementById('voidModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.style.overflow = '';
}

document.getElementById('voidModal').addEventListener('click', function(e) {
    if (e.target === this) closeVoidModal();
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeVoidModal();
});

// ── Description toggle ─────────────────────────────────────────────────────
function toggleDesc(btn) {
    const row      = btn.closest('tr');
    const descRow  = row.nextElementSibling;
    const descDiv  = descRow.querySelector('.desc-content');
    const isHidden = descRow.classList.contains('hidden');

    if (isHidden) {
        descDiv.textContent = btn.dataset.desc || '(No description)';
        descRow.classList.remove('hidden');
        btn.querySelector('i').className = 'fas fa-eye-slash text-xs';
        btn.title = 'Hide description';
    } else {
        descRow.classList.add('hidden');
        btn.querySelector('i').className = 'fas fa-eye text-xs';
        btn.title = 'Preview private description';
    }
}

// ── Auto-dismiss flash banner ──────────────────────────────────────────────
const flashBanner = document.getElementById('flashBanner');
if (flashBanner) {
    setTimeout(() => {
        flashBanner.style.transition = 'opacity .4s';
        flashBanner.style.opacity   = '0';
        setTimeout(() => flashBanner.remove(), 400);
    }, 5000);
}
</script>
</body>
</html>