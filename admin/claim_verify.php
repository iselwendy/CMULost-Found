<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../core/auth.php");
    exit();
}

require_once '../core/db_config.php';

$item_id = $_GET['item_id'] ?? null;
$search  = trim($_GET['search'] ?? '');
$item_data = null;

// ── VERIFICATION MODE: fetch real item + claimant data ─────────
if ($item_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                f.found_id,
                f.title             AS name,
                f.private_description AS private_note,
                CONCAT('TRK-', LPAD(f.found_id, 4, '0'), '-LF') AS tracking_id,
                COALESCE(inv.shelf_label, 'Unassigned') AS location,
                u.full_name         AS claimant,
                u.school_number     AS student_no,
                u.department        AS dept,
                m.match_id
            FROM matches m
            JOIN found_reports f  ON m.found_id = f.found_id
            JOIN lost_reports  lr ON m.lost_id  = lr.lost_id
            JOIN users         u  ON lr.user_id = u.user_id
            LEFT JOIN (
                SELECT found_id, CONCAT(shelf, row_bin) AS shelf_label
                FROM inventory
            ) inv ON inv.found_id = f.found_id
            WHERE m.match_id = ?
              AND m.status   = 'confirmed'
        ");
        $stmt->execute([$item_id]);
        $item_data = $stmt->fetch();
    } catch (PDOException $e) {
        $item_data = null;
    }
}

// ── SELECTION MODE: fetch confirmed matches ready for release ──
$pending_claims = [];
try {
    $where  = "WHERE m.status = 'confirmed'";
    $params = [];

    if (!empty($search)) {
        $where  .= " AND (u.full_name LIKE ? OR u.school_number LIKE ? OR f.title LIKE ?)";
        $params  = array_fill(0, 3, '%' . $search . '%');
    }

    $stmt = $pdo->prepare("
        SELECT
            m.match_id,
            f.title             AS item_name,
            f.category_id,
            c.name              AS category,
            u.full_name         AS claimant,
            u.school_number     AS student_no,
            CONCAT('TRK-', LPAD(f.found_id, 4, '0'), '-LF') AS tracking_id
        FROM matches m
        JOIN found_reports f ON m.found_id  = f.found_id
        JOIN lost_reports lr ON m.lost_id   = lr.lost_id
        JOIN users         u ON lr.user_id  = u.user_id
        JOIN categories    c ON f.category_id = c.category_id
        $where
        ORDER BY m.updated_at DESC
        LIMIT 20
    ");
    $stmt->execute($params);
    $pending_claims = $stmt->fetchAll();
} catch (PDOException $e) {
    $pending_claims = [];
}

// ── HANDLE FORM SUBMISSION: complete handover ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['match_id'])) {
    try {
        $pdo->beginTransaction();

        $match_id = (int) $_POST['match_id'];

        // 1. Get the found_id and lost_id from the match
        $stmt = $pdo->prepare("SELECT found_id, lost_id FROM matches WHERE match_id = ?");
        $stmt->execute([$match_id]);
        $match = $stmt->fetch();

        // 2. Mark the found item as returned
        $pdo->prepare("UPDATE found_reports SET status = 'returned' WHERE found_id = ?")
            ->execute([$match['found_id']]);

        // 3. Mark the lost report as resolved
        $pdo->prepare("UPDATE lost_reports SET status = 'resolved' WHERE lost_id = ?")
            ->execute([$match['lost_id']]);

        // 4. Mark the match as released
        $pdo->prepare("UPDATE matches SET status = 'released', updated_at = NOW() WHERE match_id = ?")
            ->execute([$match_id]);

        $pdo->commit();
        echo json_encode(['success' => true]);
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claim Verification | OSA Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .checklist-item:has(input:checked) { border-color: #10b981; background-color: #f0fdf4; }
        .sidebar-link.active { background: rgba(255,255,255,0.1); border-left: 4px solid #facc15; }
    </style>
    <link rel="stylesheet" href="../assets/styles/root.css"></link>
    <link rel="stylesheet" href="../assets/styles/admin_dashboard.css"></link>
</head>
<body class="bg-slate-50 min-h-screen flex overflow-hidden">

    <!-- Sidebar Navigation -->
    <aside class="w-64 bg-cmu-blue text-white flex-shrink-0 hidden lg:flex flex-col shadow-xl">
        <div class="p-6 flex items-center gap-3 border-b border-white/10">
            <img src="../assets/images/system-icon.png" alt="Logo" class="w-10 h-10 bg-white rounded-lg p-1" onerror="this.src='https://ui-avatars.com/api/?name=OSA&background=fff&color=003366';">
            <div>
                <h1 class="font-bold text-sm leading-tight">OSA Admin</h1>
                <p class="text-[10px] text-blue-200 uppercase tracking-widest">Management Portal</p>
            </div>
        </div>

        <nav class="flex-grow p-4 space-y-2">
            <a href="dashboard.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition">
                <i class="fas fa-th-large w-5"></i>
                <span class="text-sm font-medium">Dashboard Overview</span>
            </a>
            <a href="inventory.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition">
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
            <a href="claim_verify.php" class="sidebar-link active flex items-center gap-3 p-3 rounded-xl transition">
                <i class="fas fa-user-check w-5"></i>
                <span class="text-sm font-medium">Claim Verification</span>
            </a>
            <div class="pt-4 mt-4 border-t border-white/10">
                <a href="archive.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition">
                    <i class="fas fa-archive w-5 text-blue-300"></i>
                    <span class="text-sm font-medium text-blue-100">Records Archive</span>
                </a>
            </div>
        </nav>

        <div class="p-4 border-t border-white/10">
            <div class="bg-white/5 rounded-2xl p-4">
                <p class="text-[10px] text-blue-300 uppercase font-bold mb-2">Logged in as</p>
                <p class="text-sm font-bold truncate"><?php echo $_SESSION['user_name']; ?></p>
                <a href="../core/logout.php" class="text-xs text-cmu-blue font-bold mt-2 py-2 px-4 inline-block rounded-md bg-cmu-gold hover:rounded-full hover:text-cmu-gold hover:bg-white">Logout Session</a>
            </div>
        </div>
    </aside>

    <main class="flex-grow flex flex-col min-w-0 h-screen overflow-y-auto">
        <!-- Header -->
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between sticky top-0 z-10">
            <div>
                <h2 class="text-xl font-black text-slate-800 tracking-tight uppercase">
                    <?php echo $item_id ? "Verification Checklist" : "Claim Selection"; ?>
                </h2>
                <p class="text-[10px] text-slate-500 font-bold uppercase tracking-widest">
                    <?php echo $item_id ? "Release Item: $item_id" : "Select a matched item to begin release"; ?>
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

        <div class="p-8">
            <?php if (!$item_id): ?>
                <!-- EMPTY STATE / SELECTION MODE -->
                <div class="max-w-4xl mx-auto">
                    <div class="bg-white rounded-3xl p-8 border border-slate-200 shadow-sm mb-8">
                        <h3 class="text-lg font-black text-slate-800 mb-4">Search Pending Claims</h3>
                        <form method="GET" action="claim_verify.php" class="flex gap-4">
                            <div class="relative flex-grow">
                                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                                    placeholder="Search by Student Name, ID, or Item Code..."
                                    class="w-full pl-12 pr-4 py-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-2 focus:ring-[#003366] outline-none transition">
                            </div>
                            <button type="submit" class="bg-[#003366] text-white px-8 rounded-2xl font-bold">Search</button>
                        </form>
                    </div>

                    <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-4">Ready for Release (Matched Items)</h3>
                    <?php if (empty($pending_claims)): ?>
                        <div class="text-center py-12 bg-white rounded-3xl border border-slate-200">
                            <i class="fas fa-check-circle text-green-200 text-4xl mb-3"></i>
                            <p class="text-sm font-bold text-slate-400">No confirmed matches awaiting release.</p>
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php
                            $icon_map = [
                                'Electronics' => 'fa-mobile-screen',
                                'Valuables'   => 'fa-wallet',
                                'Documents'   => 'fa-id-card',
                                'Books'       => 'fa-book',
                                'Clothing'    => 'fa-shirt',
                                'Personal'    => 'fa-bag-shopping',
                            ];
                            foreach ($pending_claims as $claim):
                                $icon = $icon_map[$claim['category']] ?? 'fa-box';
                            ?>
                            <div class="bg-white p-5 rounded-2xl border border-slate-200 flex items-center justify-between hover:border-[#003366] transition cursor-pointer group"
                                onclick="window.location.href='?item_id=<?php echo $claim['match_id']; ?>'">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 bg-blue-50 text-[#003366] rounded-xl flex items-center justify-center">
                                        <i class="fas <?php echo $icon; ?>"></i>
                                    </div>
                                    <div>
                                        <p class="font-black text-slate-800 text-sm"><?php echo htmlspecialchars($claim['item_name']); ?></p>
                                        <p class="text-xs text-slate-500">Claimant: <?php echo htmlspecialchars($claim['claimant']); ?></p>
                                        <p class="text-[10px] font-mono text-slate-400"><?php echo $claim['tracking_id']; ?></p>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-right text-slate-300 group-hover:text-[#003366]"></i>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <!-- VERIFICATION MODE (What happens after clicking an item) -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Left: Info Cards -->
                    <div class="space-y-6">
                        <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm">
                            <button onclick="window.location.href='claim_verify.php'" class="text-[10px] font-black text-[#003366] uppercase mb-4 block hover:underline">
                                <i class="fas fa-arrow-left mr-1"></i> Back to Queue
                            </button>
                            <div class="flex flex-col items-center text-center">
                                <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($item_data['claimant']); ?>&background=003366&color=fff" class="w-20 h-20 rounded-full mb-3">
                                <h4 class="text-lg font-black text-slate-800"><?php echo $item_data['claimant']; ?></h4>
                                <p class="text-xs font-bold text-[#003366]"><?php echo $item_data['student_no']; ?></p>
                                <p class="text-[10px] text-slate-500 uppercase mt-1"><?php echo $item_data['dept']; ?></p>
                            </div>
                        </div>

                        <div class="bg-[#003366] rounded-3xl p-6 text-white">
                            <h3 class="text-[10px] font-black text-blue-300 uppercase tracking-widest mb-4">Verification Note</h3>
                            <p class="text-xs italic leading-relaxed text-blue-50">"<?php echo $item_data['private_note']; ?>"</p>
                            <div class="mt-4 pt-4 border-t border-white/10 flex items-center gap-3">
                                <div class="text-[10px] font-bold">Location: <span class="text-yellow-400"><?php echo $item_data['location']; ?></span></div>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Checklist -->
                    <div class="lg:col-span-2">
                        <form id="claimForm" class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                            <input type="hidden" name="match_id" value="<?php echo htmlspecialchars($item_data['match_id']); ?>">

                            <div class="p-8">
                                <div class="space-y-4">
                                    <label class="checklist-item flex items-start gap-4 p-5 border border-slate-100 rounded-2xl cursor-pointer transition border-l-4">
                                        <input type="checkbox" class="mt-1 w-5 h-5 rounded text-[#003366]" required>
                                        <div>
                                            <p class="text-sm font-black text-slate-800">Physical ID Verification</p>
                                            <p class="text-xs text-slate-500">Cross-check the physical CMU ID card with the claimant standing at the desk.</p>
                                        </div>
                                    </label>

                                    <label class="checklist-item flex items-start gap-4 p-5 border border-slate-100 rounded-2xl cursor-pointer transition border-l-4">
                                        <input type="checkbox" class="mt-1 w-5 h-5 rounded text-[#003366]" required>
                                        <div>
                                            <p class="text-sm font-black text-slate-800">Item Ownership Proof</p>
                                            <p class="text-xs text-slate-500">Claimant has correctly identified the "Private Details" mentioned in the report.</p>
                                        </div>
                                    </label>

                                    <div class="p-5 border border-slate-100 rounded-2xl bg-slate-50">
                                        <p class="text-sm font-black text-slate-800 mb-3">Live Receipt Capture (Optional)</p>
                                        <div class="bg-white border-2 border-dashed border-slate-200 rounded-xl p-6 text-center">
                                            <i class="fas fa-camera text-2xl text-slate-300 mb-2"></i>
                                            <p class="text-[10px] font-bold text-slate-400 uppercase">Snap photo of claimant with item</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="p-8 bg-slate-50 border-t border-slate-100 flex items-center justify-between">
                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Case: <?php echo $item_id; ?></span>
                                <button type="submit" class="bg-[#003366] text-white px-8 py-4 rounded-2xl font-black uppercase text-xs shadow-lg hover:bg-slate-800 transition">
                                    Complete Handover
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Success Modal -->
    <div id="successModal" class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-[40px] max-w-md w-full p-10 text-center shadow-2xl">
            <div class="w-20 h-20 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto mb-6">
                <i class="fas fa-check text-3xl"></i>
            </div>
            <h2 class="text-2xl font-black text-slate-800 mb-2">Release Complete</h2>
            <p class="text-sm text-slate-500 mb-8">Record has been moved to Archives. An SMS receipt was sent.</p>
            <button onclick="window.location.href='claim_verify.php'" class="w-full py-4 bg-slate-800 text-white rounded-2xl font-black uppercase text-xs">Finish</button>
        </div>
    </div>

    <script>
        if (document.getElementById('claimForm')) {
            document.getElementById('claimForm').addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);

                fetch('claim_verify.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            document.getElementById('successModal').classList.remove('hidden');
                        } else {
                            alert('Error completing handover: ' + (data.error ?? 'Unknown error'));
                        }
                    })
                    .catch(() => alert('Network error. Please try again.'));
            });
        }
    </script>
</body>
</html>