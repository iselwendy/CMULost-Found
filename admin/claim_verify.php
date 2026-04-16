<?php
/**
 * CMU Lost & Found — Claim Verification
 *
 * Workflow:
 *   1. Admin searches for / selects a confirmed match ready for release.
 *   2. Admin runs through the verification checklist (ID check, ownership proof).
 *   3. On submit, the system:
 *        a) Generates a unique Claim Serial Number  (CLM-YYYY-XXXXX)
 *        b) Records claimant details + serial in the archive table
 *        c) Updates found_reports → 'returned', lost_reports → 'resolved',
 *           matches → 'released'
 *   4. A printable Proof-of-Claim receipt is shown to the admin to hand to
 *      the claimant. This receipt is the loser's permanent proof that the
 *      item was collected — if they ever return claiming they haven't received
 *      it, the serial number in the archive will settle the dispute.
 *
 */

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../core/auth.php");
    exit();
}

require_once '../core/db_config.php';

// ── Helper: generate a unique Claim Serial Number ─────────────────────────────
function generateClaimSerial(PDO $pdo): string
{
    $year = date('Y');
    do {
        $rand   = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $serial = "CLM-{$year}-{$rand}";
        $check  = $pdo->prepare("SELECT 1 FROM archive WHERE claim_serial = ? LIMIT 1");
        $check->execute([$serial]);
    } while ($check->fetchColumn()); // retry on the rare collision
    return $serial;
}

// ── Inputs ────────────────────────────────────────────────────────────────────
$item_id = $_GET['item_id'] ?? null; // actually match_id
$search  = trim($_GET['search'] ?? '');
$item_data   = null;
$receipt     = null; // populated after a successful handover POST

// ── HANDLE FORM SUBMISSION: complete handover ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['match_id'])) {
    try {
        $pdo->beginTransaction();

        $match_id = (int) $_POST['match_id'];
        $admin_id = (int) $_SESSION['user_id'];

        // 1. Fetch match + item + claimant details
        $stmt = $pdo->prepare("
            SELECT
                m.match_id, m.found_id, m.lost_id,
                f.title                              AS item_title,
                COALESCE(c.name, 'Other')            AS category,
                COALESCE(loc.location_name,'Unknown') AS found_location,
                f.date_found,
                CONCAT('FND-', LPAD(f.found_id,5,'0')) AS tracking_id,
                img.image_path,
                u.full_name     AS claimant_name,
                u.school_number AS claimant_id_no,
                u.department    AS claimant_dept,
                u.phone_number  AS claimant_phone
            FROM matches m
            JOIN found_reports f   ON m.found_id    = f.found_id
            JOIN lost_reports  lr  ON m.lost_id     = lr.lost_id
            JOIN users         u   ON lr.user_id    = u.user_id
            LEFT JOIN categories c ON f.category_id = c.category_id
            LEFT JOIN locations loc ON f.location_id = loc.location_id
            LEFT JOIN (
                SELECT report_id, image_path FROM item_images
                WHERE report_type = 'found' GROUP BY report_id
            ) img ON img.report_id = f.found_id
            WHERE m.match_id = ? AND m.status = 'confirmed'
            LIMIT 1
        ");
        $stmt->execute([$match_id]);
        $match = $stmt->fetch();

        if (!$match) {
            throw new RuntimeException("Match #$match_id not found or not yet confirmed.");
        }

        // 2. Generate unique claim serial
        $claim_serial = generateClaimSerial($pdo);
        date_default_timezone_set('Asia/Manila'); 
        $release_date = date('Y-m-d');
        $release_ts   = date('F d, Y \a\t g:i A');

        // 3. Update statuses
        $pdo->prepare("UPDATE found_reports SET status = 'claimed'  WHERE found_id = ?")
            ->execute([$match['found_id']]);
        $pdo->prepare("UPDATE lost_reports  SET status = 'resolved'  WHERE lost_id  = ?")
            ->execute([$match['lost_id']]);
        $pdo->prepare("UPDATE matches SET status = 'completed' WHERE match_id = ?")
            ->execute([$match_id]);

        // 4. Insert into archive with claim serial
        //    Uses INSERT ... ON DUPLICATE KEY UPDATE so re-submitting is safe.
        $pdo->prepare("
            INSERT INTO archive
                (found_id, lost_id, tracking_id, claim_serial,
                 item_title, category, found_location,
                 date_event, outcome,
                 claimant_name, claimant_id_no, claimant_dept,
                 resolved_by, resolved_date, image_path)
            VALUES
                (?, ?, ?, ?,
                 ?, ?, ?,
                 ?, 'Returned',
                 ?, ?, ?,
                 ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                claim_serial  = VALUES(claim_serial),
                resolved_date = VALUES(resolved_date),
                resolved_by   = VALUES(resolved_by)
        ")->execute([
            $match['found_id'],
            $match['lost_id'],
            $match['tracking_id'],
            $claim_serial,
            $match['item_title'],
            $match['category'],
            $match['found_location'],
            $match['date_found'],
            $match['claimant_name'],
            $match['claimant_id_no'],
            $match['claimant_dept'],
            $admin_id,
            $release_date,
            $match['image_path'],
        ]);

        $pdo->commit();

        // 5. Build receipt data to display
        $receipt = [
            'claim_serial'    => $claim_serial,
            'item_title'      => $match['item_title'],
            'tracking_id'     => $match['tracking_id'],
            'category'        => $match['category'],
            'found_location'  => $match['found_location'],
            'claimant_name'   => $match['claimant_name'],
            'claimant_id_no'  => $match['claimant_id_no'],
            'claimant_dept'   => $match['claimant_dept'],
            'claimant_phone'  => $match['claimant_phone'],
            'release_ts'      => $release_ts,
            'admin_name'      => $_SESSION['full_name'] ?? 'OSA Admin',
            'image_path'      => $match['image_path'],
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $post_error = $e->getMessage();
    }
}

// ── VERIFICATION MODE: fetch item + claimant data ─────────────────────────────
if (!$receipt && $item_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                f.found_id,
                f.title                              AS name,
                f.private_description                AS private_note,
                CONCAT('FND-', LPAD(f.found_id,5,'0')) AS tracking_id,
                COALESCE(inv.shelf_label,'Unassigned')  AS location,
                u.full_name     AS claimant,
                u.school_number AS student_no,
                u.department    AS dept,
                u.phone_number  AS phone,
                u.cmu_email     AS email,
                m.match_id,
                m.confidence_score,
                l.title         AS lost_title,
                l.date_lost,
                lr_loc.location_name AS lost_location,
                img.image_path
            FROM matches m
            JOIN found_reports f   ON m.found_id    = f.found_id
            JOIN lost_reports  l   ON m.lost_id     = l.lost_id
            JOIN users         u   ON l.user_id     = u.user_id
            LEFT JOIN locations lr_loc ON l.location_id = lr_loc.location_id
            LEFT JOIN (
                SELECT found_id, CONCAT(shelf, '-', row_bin) AS shelf_label
                FROM inventory
            ) inv ON inv.found_id = f.found_id
            LEFT JOIN (
                SELECT report_id, image_path FROM item_images
                WHERE report_type = 'found' GROUP BY report_id
            ) img ON img.report_id = f.found_id
            WHERE m.match_id = ? AND m.status = 'confirmed'
        ");
        $stmt->execute([$item_id]);
        $item_data = $stmt->fetch();
    } catch (PDOException $e) {
        $item_data = null;
    }
}

// ── SELECTION MODE: fetch confirmed matches ready for release ─────────────────
$pending_claims = [];
$selection_error = null;
if (!$receipt) {
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
                c.name              AS category,
                u.full_name         AS claimant,
                u.school_number     AS student_no,
                CONCAT('FND-', LPAD(f.found_id,5,'0')) AS tracking_id
            FROM matches m
            JOIN found_reports f ON m.found_id  = f.found_id
            JOIN lost_reports lr ON m.lost_id   = lr.lost_id
            JOIN users         u ON lr.user_id  = u.user_id
            LEFT JOIN categories c ON f.category_id = c.category_id
            $where
            ORDER BY m.matched_at DESC
            LIMIT 20
        ");
        $stmt->execute($params);
        $pending_claims = $stmt->fetchAll();
    } catch (PDOException $e) {
        $selection_error = $e->getMessage();
        error_log('[claim_verify] Selection query failed: ' . $e->getMessage());
    }
}

// ── Category icon map ─────────────────────────────────────────────────────────
$icon_map = [
    'Electronics' => 'fa-laptop',
    'Valuables'   => 'fa-wallet',
    'Documents'   => 'fa-id-card',
    'Books'       => 'fa-book',
    'Clothing'    => 'fa-shirt',
    'Personal'    => 'fa-bag-shopping',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claim Verification | OSA Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/styles/root.css">
    <link rel="stylesheet" href="../assets/styles/admin_dashboard.css">
    <style>
        .checklist-item:has(input:checked) {
            border-color: #10b981;
            background-color: #f0fdf4;
        }
        .sidebar-link.active {
            background: rgba(255,255,255,0.1);
            border-left: 4px solid #facc15;
        }

        /* ── Print styles: only the receipt shows ── */
        @media print {
            /* Hide everything by making it invisible — does NOT affect children */
            body > *:not(#receiptPrintArea) {
                display: none !important;
            }

            #receiptPrintArea {
                display: block !important;
                position: static !important;
                left: auto !important;
                top: auto !important;
                width: 100% !important;
            }

            @page {
                size: auto;
                margin: 10mm;
            }
        }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex overflow-hidden">

<!-- ── Sidebar ──────────────────────────────────────────────────────────────── -->
<aside class="w-64 bg-cmu-blue text-white flex-shrink-0 hidden lg:flex flex-col shadow-xl">
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
        <a href="claim_verify.php"    class="sidebar-link active flex items-center gap-3 p-3 rounded-xl transition"><i class="fas fa-user-check w-5"></i><span class="text-sm font-medium">Claim Verification</span></a>
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
            <p class="text-sm font-bold truncate"><?php echo htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'Admin'); ?></p>
            <a href="../core/logout.php" class="text-xs text-cmu-blue font-bold mt-2 py-2 px-4 inline-block rounded-md bg-cmu-gold hover:rounded-full hover:text-cmu-gold hover:bg-white">Logout Session</a>
        </div>
    </div>
</aside>

<!-- ── Main ─────────────────────────────────────────────────────────────────── -->
<main class="flex-grow flex flex-col min-w-0 h-screen overflow-y-auto">

    <!-- Header -->
    <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between sticky top-0 z-10">
        <div>
            <h2 class="text-xl font-black text-slate-800 tracking-tight uppercase">
                <?php if ($receipt): ?>
                    Handover Complete
                <?php elseif ($item_id && $item_data): ?>
                    Verification Checklist
                <?php else: ?>
                    Claim Verification
                <?php endif; ?>
            </h2>
            <p class="text-[10px] text-slate-500 font-bold uppercase tracking-widest">
                <?php if ($receipt): ?>
                    Print the receipt and hand it to the claimant.
                <?php elseif ($item_id && $item_data): ?>
                    <?php echo htmlspecialchars($item_data['tracking_id']); ?> — Confirm identity then release.
                <?php else: ?>
                    Select a confirmed match to begin item release.
                <?php endif; ?>
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

        <?php if (isset($post_error)): ?>
        <div class="max-w-2xl mx-auto mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-2xl text-sm flex items-center gap-3">
            <i class="fas fa-exclamation-triangle flex-shrink-0"></i>
            <span><?php echo htmlspecialchars($post_error); ?></span>
        </div>
        <?php endif; ?>

        <?php if (isset($selection_error)): ?>
        <div class="max-w-4xl mx-auto mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-2xl text-sm flex items-center gap-3">
            <i class="fas fa-database flex-shrink-0"></i>
            <span>Query error: <?php echo htmlspecialchars($selection_error); ?></span>
        </div>
        <?php endif; ?>


        <?php if ($receipt): ?>
        <!-- ══════════════════════════════════════════════════════
             STEP 3 — SUCCESS: Show printable receipt
             ══════════════════════════════════════════════════════ -->
        <div class="max-w-2xl mx-auto">

            <!-- Success banner -->
            <div class="flex items-center gap-4 bg-green-50 border border-green-200 rounded-2xl p-5 mb-6">
                <div class="w-12 h-12 bg-green-500 text-white rounded-full flex items-center justify-center text-xl flex-shrink-0 shadow-md">
                    <i class="fas fa-check"></i>
                </div>
                <div>
                    <p class="text-sm font-black text-green-800">Handover complete! The record has been archived.</p>
                    <p class="text-xs text-green-600 mt-0.5">Print the receipt below and give it to the claimant as proof of collection.</p>
                </div>
            </div>

            <!-- Receipt card (screen version) -->
            <div class="bg-white rounded-3xl shadow-xl border border-slate-200 overflow-hidden">

                <!-- Receipt header -->
                <div class="bg-cmu-blue px-8 py-6 text-white flex items-start justify-between gap-4">
                    <div>
                        <p class="text-[10px] font-black text-blue-300 uppercase tracking-widest mb-1">
                            City of Malabon University · Student Affairs Office
                        </p>
                        <h3 class="text-lg font-black leading-tight">Proof of Item Collection</h3>
                        <p class="text-xs text-blue-200 mt-1">
                            Keep this receipt as permanent proof. This serial number is registered in our records.
                        </p>
                    </div>
                    <div class="flex-shrink-0 text-right">
                        <p class="text-[10px] text-blue-300 uppercase tracking-widest">Claim Serial</p>
                        <p class="text-xl font-black font-mono text-cmu-gold tracking-widest mt-0.5">
                            <?php echo htmlspecialchars($receipt['claim_serial']); ?>
                        </p>
                    </div>
                </div>

                <!-- Receipt body -->
                <div class="p-8 space-y-6">

                    <!-- Item details -->
                    <div class="flex gap-5 items-start">
                        <?php if (!empty($receipt['image_path'])): ?>
                        <div class="w-20 h-20 rounded-xl overflow-hidden border border-slate-100 flex-shrink-0">
                            <img src="../<?php echo htmlspecialchars($receipt['image_path']); ?>"
                                 alt="Item" class="w-full h-full object-cover">
                        </div>
                        <?php endif; ?>
                        <div>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wider">Item Released</p>
                            <h4 class="text-xl font-black text-slate-800 mt-0.5"><?php echo htmlspecialchars($receipt['item_title']); ?></h4>
                            <div class="flex flex-wrap gap-3 mt-2 text-xs text-slate-500">
                                <span><i class="fas fa-tag mr-1 text-slate-300"></i><?php echo htmlspecialchars($receipt['category']); ?></span>
                                <span><i class="fas fa-map-marker-alt mr-1 text-slate-300"></i><?php echo htmlspecialchars($receipt['found_location']); ?></span>
                                <span class="font-mono font-bold text-indigo-600"><?php echo htmlspecialchars($receipt['tracking_id']); ?></span>
                            </div>
                        </div>
                    </div>

                    <hr class="border-slate-100">

                    <!-- Claimant details -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="bg-slate-50 rounded-2xl p-4">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1">Claimed By</p>
                            <p class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($receipt['claimant_name']); ?></p>
                            <p class="text-xs text-slate-500"><?php echo htmlspecialchars($receipt['claimant_dept']); ?></p>
                        </div>
                        <div class="bg-slate-50 rounded-2xl p-4">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1">School Number</p>
                            <p class="text-sm font-bold font-mono text-slate-800"><?php echo htmlspecialchars($receipt['claimant_id_no']); ?></p>
                        </div>
                        <div class="bg-slate-50 rounded-2xl p-4">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1">Date & Time Released</p>
                            <p class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($receipt['release_ts']); ?></p>
                        </div>
                        <div class="bg-slate-50 rounded-2xl p-4">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1">Released By (OSA)</p>
                            <p class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($receipt['admin_name']); ?></p>
                        </div>
                    </div>

                    <!-- Dispute notice -->
                    <div class="bg-amber-50 border border-amber-100 rounded-2xl p-4 flex gap-3">
                        <i class="fas fa-shield-alt text-amber-500 mt-0.5 flex-shrink-0"></i>
                        <p class="text-[11px] text-amber-800 leading-relaxed">
                            The Claim Serial <strong><?php echo htmlspecialchars($receipt['claim_serial']); ?></strong> is
                            permanently recorded in the OSA archive. In the event of a dispute, this serial can be used
                            to verify that the item was collected by the person named above.
                        </p>
                    </div>
                </div>

                <!-- Footer actions -->
                <div class="px-8 py-5 bg-slate-50 border-t border-slate-100 flex flex-wrap gap-3 items-center justify-between">
                    <button onclick="printReceipt()"
                            class="flex items-center gap-2 px-6 py-3 bg-cmu-blue text-white rounded-xl font-bold text-sm hover:bg-slate-800 transition shadow-sm">
                        <i class="fas fa-print"></i> Print Receipt
                    </button>
                    <a href="claim_verify.php"
                       class="px-6 py-3 border border-slate-200 text-slate-600 rounded-xl font-bold text-sm hover:bg-white transition">
                        Back to Queue
                    </a>
                </div>
            </div>
        </div>


        <?php elseif ($item_id && $item_data): ?>
        <!-- ══════════════════════════════════════════════════════
             STEP 2 — VERIFICATION CHECKLIST
             ══════════════════════════════════════════════════════ -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            <!-- Left: Claimant & item cards -->
            <div class="space-y-6">

                <!-- Back link -->
                <a href="claim_verify.php"
                   class="inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-cmu-blue uppercase tracking-widest">
                    <i class="fas fa-arrow-left"></i> Back to Queue
                </a>

                <!-- Claimant card -->
                <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Claimant on Record</p>
                    <div class="flex flex-col items-center text-center">
                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($item_data['claimant']); ?>&background=003366&color=fff&bold=true"
                             class="w-20 h-20 rounded-full mb-3 shadow-md">
                        <h4 class="text-lg font-black text-slate-800"><?php echo htmlspecialchars($item_data['claimant']); ?></h4>
                        <p class="text-xs font-bold text-cmu-blue font-mono"><?php echo htmlspecialchars($item_data['student_no']); ?></p>
                        <p class="text-[10px] text-slate-400 uppercase mt-0.5"><?php echo htmlspecialchars($item_data['dept']); ?></p>
                        <?php if (!empty($item_data['phone'])): ?>
                        <p class="text-xs text-slate-500 mt-1"><i class="fas fa-phone mr-1 text-slate-300"></i><?php echo htmlspecialchars($item_data['phone']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($item_data['email'])): ?>
                        <p class="text-xs text-slate-500 truncate max-w-full"><i class="fas fa-envelope mr-1 text-slate-300"></i><?php echo htmlspecialchars($item_data['email']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Item snapshot card -->
                <div class="bg-cmu-blue rounded-3xl p-6 text-white">
                    <p class="text-[10px] font-black text-blue-300 uppercase tracking-widest mb-3">Item to Release</p>
                    <?php if (!empty($item_data['image_path'])): ?>
                    <div class="w-full h-32 rounded-2xl overflow-hidden mb-4 border border-white/20">
                        <img src="../<?php echo htmlspecialchars($item_data['image_path']); ?>"
                             alt="Item" class="w-full h-full object-cover">
                    </div>
                    <?php endif; ?>
                    <h4 class="font-black text-base mb-1"><?php echo htmlspecialchars($item_data['name']); ?></h4>
                    <p class="text-xs text-blue-200 font-mono mb-3"><?php echo htmlspecialchars($item_data['tracking_id']); ?></p>
                    <p class="text-[10px] font-black text-blue-300 uppercase tracking-widest mb-1">Shelf Location</p>
                    <p class="text-sm font-bold text-cmu-gold"><?php echo htmlspecialchars($item_data['location']); ?></p>

                    <!-- AI confidence badge -->
                    <?php if (!empty($item_data['confidence_score'])): ?>
                    <div class="mt-4 pt-4 border-t border-white/10">
                        <p class="text-[10px] font-black text-blue-300 uppercase tracking-widest mb-1">AI Match Confidence</p>
                        <div class="flex items-center gap-2">
                            <div style="flex:1;height:6px;background:rgba(255,255,255,.2);border-radius:99px;overflow:hidden;">
                                <div style="width:<?php echo (int)$item_data['confidence_score']; ?>%;height:100%;background:#FFCC00;border-radius:99px;"></div>
                            </div>
                            <span class="text-sm font-black text-cmu-gold"><?php echo (int)$item_data['confidence_score']; ?>%</span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Private verification note -->
                <div class="bg-amber-50 border border-amber-100 rounded-2xl p-5">
                    <p class="text-[10px] font-black text-amber-600 uppercase tracking-widest mb-2">
                        <i class="fas fa-lock mr-1"></i> Private Verification Details
                    </p>
                    <p class="text-xs text-amber-800 leading-relaxed italic">
                        "<?php echo htmlspecialchars($item_data['private_note'] ?: 'No private description was provided.'); ?>"
                    </p>
                </div>
            </div>

            <!-- Right: Checklist form -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">

                    <div class="px-8 py-6 border-b border-slate-100">
                        <h3 class="text-base font-black text-slate-800 uppercase tracking-tight">Verification Checklist</h3>
                        <p class="text-xs text-slate-500 mt-1">All items must be checked before releasing the item to the claimant.</p>
                    </div>

                    <form method="POST" action="claim_verify.php" class="p-8 space-y-4">
                        <input type="hidden" name="match_id" value="<?php echo htmlspecialchars((string)$item_data['match_id']); ?>">

                        <!-- Checklist item 1: Physical ID -->
                        <label class="checklist-item flex items-start gap-4 p-5 border-2 border-slate-100 rounded-2xl cursor-pointer transition-all border-l-4 select-none">
                            <input type="checkbox" class="mt-1 w-5 h-5 rounded text-cmu-blue flex-shrink-0" required>
                            <div>
                                <p class="text-sm font-black text-slate-800">
                                    <span class="inline-block w-6 h-6 bg-cmu-blue text-white rounded-full text-[10px] font-black text-center leading-6 mr-1">1</span>
                                    Physical ID Verified
                                </p>
                                <p class="text-xs text-slate-500 mt-1 leading-relaxed">
                                    The claimant has presented their physical CMU ID card.
                                    Confirm that the name and school number match the record:
                                    <strong class="text-slate-700"><?php echo htmlspecialchars($item_data['claimant']); ?></strong>
                                    · <span class="font-mono font-bold text-indigo-600"><?php echo htmlspecialchars($item_data['student_no']); ?></span>
                                </p>
                            </div>
                        </label>

                        <!-- Checklist item 2: Ownership proof -->
                        <label class="checklist-item flex items-start gap-4 p-5 border-2 border-slate-100 rounded-2xl cursor-pointer transition-all border-l-4 select-none">
                            <input type="checkbox" class="mt-1 w-5 h-5 rounded text-cmu-blue flex-shrink-0" required>
                            <div>
                                <p class="text-sm font-black text-slate-800">
                                    <span class="inline-block w-6 h-6 bg-cmu-blue text-white rounded-full text-[10px] font-black text-center leading-6 mr-1">2</span>
                                    Ownership Proof Confirmed
                                </p>
                                <p class="text-xs text-slate-500 mt-1 leading-relaxed">
                                    The claimant has correctly described the private verification details on file
                                    (color, distinguishing marks, or exact spot lost). Their verbal description
                                    matches the <strong class="text-amber-700">Private Verification Details</strong> shown on the left.
                                </p>
                            </div>
                        </label>

                        <!-- Checklist item 3: Cross-reference with lost report -->
                        <label class="checklist-item flex items-start gap-4 p-5 border-2 border-slate-100 rounded-2xl cursor-pointer transition-all border-l-4 select-none">
                            <input type="checkbox" class="mt-1 w-5 h-5 rounded text-cmu-blue flex-shrink-0" required>
                            <div>
                                <p class="text-sm font-black text-slate-800">
                                    <span class="inline-block w-6 h-6 bg-cmu-blue text-white rounded-full text-[10px] font-black text-center leading-6 mr-1">3</span>
                                    Lost Report Cross-Referenced
                                </p>
                                <p class="text-xs text-slate-500 mt-1 leading-relaxed">
                                    The claimant's lost report
                                    (<strong class="text-slate-700">"<?php echo htmlspecialchars($item_data['lost_title'] ?? '—'); ?>"</strong>
                                    <?php if (!empty($item_data['lost_location'])): ?>
                                        lost near <strong class="text-slate-700"><?php echo htmlspecialchars($item_data['lost_location']); ?></strong>
                                    <?php endif; ?>
                                    <?php if (!empty($item_data['date_lost'])): ?>
                                        on <strong class="text-slate-700"><?php echo date('M d, Y', strtotime($item_data['date_lost'])); ?></strong>
                                    <?php endif; ?>)
                                    is consistent with the found item details.
                                </p>
                            </div>
                        </label>

                        <!-- Checklist item 4: Claimant acknowledgement -->
                        <label class="checklist-item flex items-start gap-4 p-5 border-2 border-slate-100 rounded-2xl cursor-pointer transition-all border-l-4 select-none">
                            <input type="checkbox" class="mt-1 w-5 h-5 rounded text-cmu-blue flex-shrink-0" required>
                            <div>
                                <p class="text-sm font-black text-slate-800">
                                    <span class="inline-block w-6 h-6 bg-cmu-blue text-white rounded-full text-[10px] font-black text-center leading-6 mr-1">4</span>
                                    Claimant Acknowledgement
                                </p>
                                <p class="text-xs text-slate-500 mt-1 leading-relaxed">
                                    The claimant has physically inspected the item and confirms it is their property.
                                    They understand that a Claim Serial will be generated and permanently recorded —
                                    the item cannot be reclaimed by another party after this point.
                                </p>
                            </div>
                        </label>

                        <!-- Progress indicator -->
                        <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100">
                            <div class="flex items-center justify-between text-xs font-bold text-slate-500 mb-2">
                                <span>Checklist Progress</span>
                                <span id="checkProgress">0 / 4 completed</span>
                            </div>
                            <div class="h-2 bg-slate-200 rounded-full overflow-hidden">
                                <div id="checkProgressBar" class="h-full bg-cmu-blue rounded-full transition-all duration-300" style="width:0%"></div>
                            </div>
                        </div>

                        <!-- What happens next notice -->
                        <div class="bg-indigo-50 border border-indigo-100 rounded-2xl p-4 flex gap-3">
                            <i class="fas fa-info-circle text-indigo-400 mt-0.5 flex-shrink-0"></i>
                            <div class="text-xs text-indigo-700 leading-relaxed space-y-1">
                                <p><strong>On submission, the system will automatically:</strong></p>
                                <p>① Generate a unique <strong>Claim Serial Number</strong> (CLM-YYYY-XXXXX) and save it to the archive.</p>
                                <p>② Mark the found item as <strong>"Returned"</strong> and the lost report as <strong>"Resolved"</strong>.</p>
                                <p>③ Show a <strong>printable receipt</strong> for the claimant to keep as proof of collection.</p>
                            </div>
                        </div>

                        <!-- Submit -->
                        <div class="pt-2 flex gap-3 items-center justify-between">
                            <a href="claim_verify.php" class="px-5 py-3 border border-slate-200 text-slate-600 rounded-xl font-bold text-sm hover:bg-slate-50 transition">
                                Cancel
                            </a>
                            <button type="submit" id="submitBtn"
                                    class="flex-1 py-3.5 bg-cmu-blue text-white rounded-2xl font-black text-sm uppercase tracking-wide shadow-lg hover:bg-slate-800 active:scale-[0.98] transition-all flex items-center justify-center gap-2">
                                <i class="fas fa-stamp"></i>
                                Complete Handover &amp; Generate Receipt
                            </button>
                        </div>

                        <p class="text-center text-[10px] text-slate-400 italic">
                            Case: <?php echo htmlspecialchars((string)$item_data['match_id']); ?>
                            &nbsp;·&nbsp;
                            Officer: <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?>
                        </p>
                    </form>
                </div>
            </div>
        </div>


        <?php else: ?>
        <!-- ══════════════════════════════════════════════════════
             STEP 1 — SELECTION MODE
             ══════════════════════════════════════════════════════ -->
        <div class="max-w-4xl mx-auto">

            <!-- Search -->
            <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm mb-6">
                <p class="text-xs font-black text-slate-400 uppercase tracking-widest mb-4">Search Pending Claims</p>
                <form method="GET" action="claim_verify.php" class="flex gap-3">
                    <div class="relative flex-grow">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="text" name="search"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Search by student name, school number, or item..."
                               class="w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-2 focus:ring-cmu-blue outline-none transition text-sm">
                    </div>
                    <button type="submit" class="bg-cmu-blue text-white px-7 rounded-2xl font-bold text-sm hover:bg-slate-800 transition">Search</button>
                    <?php if ($search): ?>
                    <a href="claim_verify.php" class="px-4 py-3 border border-slate-200 rounded-2xl text-xs font-bold text-slate-500 hover:bg-slate-50 transition flex items-center">
                        <i class="fas fa-times"></i>
                    </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Queue -->
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">
                Ready for Release &nbsp;·&nbsp; <?php echo count($pending_claims); ?> confirmed match<?php echo count($pending_claims) !== 1 ? 'es' : ''; ?>
            </p>

            <?php if (empty($pending_claims)): ?>
            <div class="text-center py-16 bg-white rounded-3xl border border-slate-200">
                <div class="w-16 h-16 bg-green-50 text-green-300 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h3 class="font-bold text-slate-700">No confirmed matches awaiting release</h3>
                <p class="text-xs text-slate-400 mt-1">
                    <?php echo $search ? 'No results for that search.' : 'All confirmed matches have been processed.'; ?>
                </p>
            </div>
            <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach ($pending_claims as $claim):
                    $icon = $icon_map[$claim['category']] ?? 'fa-box';
                ?>
                <div class="bg-white p-5 rounded-2xl border border-slate-200 hover:border-cmu-blue/50 hover:shadow-md transition cursor-pointer group"
                     onclick="window.location.href='?item_id=<?php echo urlencode((string)$claim['match_id']); ?>'">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-blue-50 text-cmu-blue rounded-xl flex items-center justify-center flex-shrink-0">
                            <i class="fas <?php echo $icon; ?>"></i>
                        </div>
                        <div class="flex-grow min-w-0">
                            <p class="font-black text-slate-800 text-sm truncate"><?php echo htmlspecialchars($claim['item_name']); ?></p>
                            <p class="text-xs text-slate-500 mt-0.5">
                                <span class="font-bold text-slate-700"><?php echo htmlspecialchars($claim['claimant']); ?></span>
                                &nbsp;·&nbsp; <span class="font-mono"><?php echo htmlspecialchars($claim['student_no']); ?></span>
                            </p>
                            <p class="text-[10px] font-mono text-indigo-600 mt-0.5"><?php echo htmlspecialchars($claim['tracking_id']); ?></p>
                        </div>
                        <i class="fas fa-chevron-right text-slate-300 group-hover:text-cmu-blue transition flex-shrink-0"></i>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div><!-- end .p-8 -->
</main>

<?php if ($receipt): ?>
    <div id="receiptPrintArea" style="display:none;">
        <div style="font-family:'Segoe UI',Arial,sans-serif;max-width:148mm;margin:0 auto;">
            <div style="background:#003366;color:white;padding:12mm 10mm 8mm;border-radius:4mm 4mm 0 0;">
                <p style="margin:0 0 2mm;font-size:8pt;color:#93c5fd;text-transform:uppercase;letter-spacing:.08em;font-weight:900;">
                    City of Malabon University · Student Affairs Office
                </p>
                <h1 style="margin:0 0 1mm;font-size:14pt;font-weight:900;">Proof of Item Collection</h1>
                <p style="margin:0;font-size:8pt;color:#bfdbfe;">Retain this document as permanent proof of receipt.</p>
            </div>

            <div style="background:#FFCC00;padding:5mm 10mm;display:flex;justify-content:space-between;align-items:center;">
                <span style="font-size:8pt;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#003366;">Claim Serial Number</span>
                <span style="font-size:16pt;font-weight:900;font-family:monospace;color:#003366;letter-spacing:.05em;">
                    <?php echo htmlspecialchars($receipt['claim_serial']); ?>
                </span>
            </div>

            <div style="border:1px solid #e2e8f0;border-top:none;border-radius:0 0 4mm 4mm;padding:8mm 10mm;">
                <table style="width:100%;border-collapse:collapse;margin-bottom:6mm;">
                    <tr>
                        <td colspan="2" style="padding-bottom:2mm;">
                            <p style="margin:0;font-size:7pt;font-weight:900;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;">Item Released</p>
                            <p style="margin:2mm 0 0;font-size:13pt;font-weight:900;color:#0f172a;"><?php echo htmlspecialchars($receipt['item_title']); ?></p>
                            <p style="margin:1mm 0 0;font-size:8pt;color:#64748b;">
                                <?php echo htmlspecialchars($receipt['category']); ?> &nbsp;·&nbsp;
                                <?php echo htmlspecialchars($receipt['found_location']); ?> &nbsp;·&nbsp;
                                <span style="font-family:monospace;font-weight:700;color:#4f46e5;"><?php echo htmlspecialchars($receipt['tracking_id']); ?></span>
                            </p>
                        </td>
                    </tr>
                </table>

                <hr style="border:none;border-top:1px dashed #e2e8f0;margin:0 0 6mm;">

                <table style="width:100%;border-collapse:collapse;font-size:9pt;">
                    <tr>
                        <td style="padding:3mm 3mm 3mm 0;vertical-align:top;width:50%;">
                            <p style="margin:0;font-size:7pt;font-weight:900;text-transform:uppercase;color:#94a3b8;">Claimed By</p>
                            <p style="margin:1mm 0 0;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars($receipt['claimant_name']); ?></p>
                            <p style="margin:0;color:#64748b;font-size:8pt;"><?php echo htmlspecialchars($receipt['claimant_dept']); ?></p>
                        </td>
                        <td style="padding:3mm 0 3mm 3mm;vertical-align:top;width:50%;">
                            <p style="margin:0;font-size:7pt;font-weight:900;text-transform:uppercase;color:#94a3b8;">School Number</p>
                            <p style="margin:1mm 0 0;font-weight:700;font-family:monospace;color:#0f172a;"><?php echo htmlspecialchars($receipt['claimant_id_no']); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:3mm 3mm 3mm 0;vertical-align:top;">
                            <p style="margin:0;font-size:7pt;font-weight:900;text-transform:uppercase;color:#94a3b8;">Date & Time Released</p>
                            <p style="margin:1mm 0 0;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars($receipt['release_ts']); ?></p>
                        </td>
                        <td style="padding:3mm 0 3mm 3mm;vertical-align:top;">
                            <p style="margin:0;font-size:7pt;font-weight:900;text-transform:uppercase;color:#94a3b8;">Released By (OSA Officer)</p>
                            <p style="margin:1mm 0 0;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars($receipt['admin_name']); ?></p>
                        </td>
                    </tr>
                </table>

                <hr style="border:none;border-top:1px dashed #e2e8f0;margin:5mm 0;">

                <table style="width:100%;border-collapse:collapse;margin-bottom:5mm;">
                    <tr>
                        <td style="width:45%;padding-right:5mm;text-align:center;">
                            <div style="border-top:1px solid #334155;padding-top:2mm;">
                                <p style="margin:0;font-size:7pt;font-weight:900;text-transform:uppercase;color:#64748b;">Claimant's Signature</p>
                            </div>
                        </td>
                        <td style="width:10%;"></td>
                        <td style="width:45%;padding-left:5mm;text-align:center;">
                            <div style="border-top:1px solid #334155;padding-top:2mm;">
                                <p style="margin:0;font-size:7pt;font-weight:900;text-transform:uppercase;color:#64748b;">OSA Officer's Signature</p>
                            </div>
                        </td>
                    </tr>
                </table>

                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:3mm;padding:4mm 5mm;">
                    <p style="margin:0;font-size:7pt;color:#92400e;line-height:1.5;">
                        <strong>Dispute Notice:</strong> The Claim Serial
                        <strong style="font-family:monospace;"><?php echo htmlspecialchars($receipt['claim_serial']); ?></strong>
                        is permanently recorded in the OSA archive. This document serves as proof that the item described
                        above was released to the person named herein. No further claims for this item will be entertained.
                    </p>
                </div>
            </div>

            <p style="margin:4mm 0 0;text-align:center;font-size:7pt;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;">
                CMU Lost &amp; Found System &nbsp;·&nbsp; Office of Student Affairs &nbsp;·&nbsp; <?php echo date('Y'); ?>
            </p>
        </div>
    </div>
<?php endif; ?>

<script>
// ── Checklist progress tracker ────────────────────────────────────────────────
(function () {
    const checkboxes = document.querySelectorAll('.checklist-item input[type="checkbox"]');
    if (!checkboxes.length) return;

    const bar   = document.getElementById('checkProgressBar');
    const label = document.getElementById('checkProgress');
    const total = checkboxes.length;

    function update() {
        const checked = document.querySelectorAll('.checklist-item input:checked').length;
        const pct = Math.round((checked / total) * 100);
        bar.style.width   = pct + '%';
        label.textContent = `${checked} / ${total} completed`;

        // Colour-code the bar
        if (pct === 100)      bar.classList.replace('bg-cmu-blue', 'bg-green-500');
        else if (pct >= 50)   bar.style.background = '#6366f1';
        else                  bar.style.background = '#003366';
    }

    checkboxes.forEach(cb => cb.addEventListener('change', update));
    update();
})();

function printReceipt() {
    const el = document.getElementById('receiptPrintArea');
    el.style.display = 'block';
    window.print();
    el.style.display = 'none';
}
</script>

</body>
</html>