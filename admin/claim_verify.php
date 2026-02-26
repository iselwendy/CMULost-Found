<?php
/**
 * CMU Lost & Found - Claim Verification
 * Handles both the search for a claim and the final verification checklist.
 */

session_start();

// Admin Access Control
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../core/auth.php");
    exit();
}

require_once '../core/db_config.php';

// Check if we are in "Active Verification" mode or "Search" mode
$item_id = $_GET['item_id'] ?? null;

// Mock data logic for when an item IS selected
$item_data = null;
if ($item_id) {
    // In production, you would: SELECT * FROM items JOIN users ON items.potential_owner = users.id WHERE item_id = $item_id
    $item_data = [
        'id' => $item_id,
        'name' => 'Calculus 1 Textbook',
        'location' => 'B4-S02',
        'claimant' => 'Mark Spencer',
        'student_no' => '2021-10452',
        'dept' => 'BS Computer Science',
        'private_note' => "Name 'Mark S.' written on page 45."
    ];
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
                        <div class="flex gap-4">
                            <div class="relative flex-grow">
                                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                <input type="text" placeholder="Search by Student Name, ID, or Item Code..." 
                                       class="w-full pl-12 pr-4 py-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-2 focus:ring-[#003366] outline-none transition">
                            </div>
                            <button class="bg-[#003366] text-white px-8 rounded-2xl font-bold">Search</button>
                        </div>
                    </div>

                    <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-4">Ready for Release (Matched Items)</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Example Queue Item -->
                        <div class="bg-white p-5 rounded-2xl border border-slate-200 flex items-center justify-between hover:border-[#003366] transition cursor-pointer group"
                             onclick="window.location.href='?item_id=TRK-88219-AM'">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 bg-blue-50 text-[#003366] rounded-xl flex items-center justify-center font-bold">
                                    <i class="fas fa-book"></i>
                                </div>
                                <div>
                                    <p class="font-black text-slate-800 text-sm">Calculus 1 Textbook</p>
                                    <p class="text-xs text-slate-500">Claimant: Mark Spencer</p>
                                </div>
                            </div>
                            <i class="fas fa-chevron-right text-slate-300 group-hover:text-[#003366]"></i>
                        </div>
                        
                        <!-- Another Example -->
                        <div class="bg-white p-5 rounded-2xl border border-slate-200 flex items-center justify-between opacity-60">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 bg-slate-100 text-slate-400 rounded-xl flex items-center justify-center font-bold">
                                    <i class="fas fa-wallet"></i>
                                </div>
                                <div>
                                    <p class="font-black text-slate-800 text-sm">Black Leather Wallet</p>
                                    <p class="text-xs text-slate-500">Waiting for Student...</p>
                                </div>
                            </div>
                            <span class="text-[8px] font-black uppercase bg-slate-100 px-2 py-1 rounded">Pending SMS</span>
                        </div>
                    </div>
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
        if(document.getElementById('claimForm')) {
            document.getElementById('claimForm').addEventListener('submit', function(e) {
                e.preventDefault();
                document.getElementById('successModal').classList.remove('hidden');
            });
        }
    </script>
</body>
</html>