<?php
/**
 * CMU Lost & Found - QR Intake Scanner
 * High-speed tool for OSA Admins to process physical turnovers.
 */

session_start();

// Security Guard
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../core/auth.php");
    exit();
}

require_once '../core/db_config.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Intake Scanner | CMU Lost & Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- QR Scanner Library -->
    <script src="https://unpkg.com/html5-qrcode"></script>
    <link rel="stylesheet" href="../assets/styles/root.css"></link>
    <link rel="stylesheet" href="../assets/styles/admin_dashboard.css"></link>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col lg:flex-row">

    <!-- Sidebar (Same as Dashboard for consistency) -->
    <aside class="w-64 bg-cmu-blue text-white flex-shrink-0 hidden lg:flex flex-col shadow-xl sticky top-0 h-screen">
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
            <a href="qr_scanner.php" class="sidebar-link active flex items-center gap-3 p-3 rounded-xl transition">
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

    <!-- Content -->
    <main class="flex-grow flex flex-col min-w-0 h-screen overflow-hidden">

        <!-- Navbar -->
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between flex-shrink-0 gap-4">
            <div>
                <h2 class="text-xl font-black text-slate-800 tracking-tight uppercase">QR Intake Scanner</h2>
                <p class="text-xs text-slate-500">Scan finder's QR code to confirm physical receipt of item.</p>
            </div>

            <div class="flex gap-2">
                <button onclick="location.reload()" class="p-3 bg-white border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50"><i class="fas fa-rotate"></i></button>
            </div>
        </header>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-8 mt-6 px-8">
            
            <!-- Scanner Section -->
            <div class="space-y-6">
                <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-200 overflow-hidden relative">
                    <div id="reader" class="w-full"></div>
                    
                    <!-- Scanner Overlay -->
                    <div id="scan-feedback" class="absolute inset-0 bg-green-500/90 hidden flex-col items-center justify-center text-white z-50">
                        <i class="fas fa-check-circle text-6xl mb-4"></i>
                        <h3 class="text-2xl font-black uppercase">Scan Successful</h3>
                        <p class="text-sm opacity-90">Retrieving item data...</p>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-100 p-6 rounded-3xl flex gap-4">
                    <div class="w-12 h-12 bg-cmu-blue text-white rounded-2xl flex items-center justify-center text-xl flex-shrink-0">
                        <i class="fas fa-info"></i>
                    </div>
                    <div>
                        <h4 class="font-bold text-cmu-blue text-sm">Instructions</h4>
                        <p class="text-xs text-blue-700 leading-relaxed mt-1">
                            Position the QR code presented by the finder within the frame. The system will automatically detect the <strong>Tracking ID</strong> and pull the report details for verification.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Result & Processing Section -->
            <div id="processing-pane" class="space-y-6 opacity-50 pointer-events-none transition-all duration-500">
                <div class="bg-white rounded-3xl shadow-xl border-2 border-slate-200 overflow-hidden">
                    <div class="bg-slate-50 px-8 py-6 border-b border-slate-100">
                        <span id="res-tracking-id" class="text-[10px] font-black bg-indigo-100 text-indigo-700 px-3 py-1 rounded-full uppercase tracking-widest">No Active Scan</span>
                        <h3 id="res-item-name" class="text-2xl font-black text-slate-800 mt-2">Item Details</h3>
                    </div>

                    <div class="p-8 space-y-6">
                        <div class="flex gap-6">
                            <div id="res-image" class="w-32 h-32 bg-slate-100 rounded-2xl flex items-center justify-center text-slate-300 overflow-hidden">
                                <i class="fas fa-image text-3xl"></i>
                            </div>
                            <div class="flex-grow space-y-3">
                                <div>
                                    <p class="text-[10px] font-black text-slate-400 uppercase">Finder Information</p>
                                    <p id="res-finder-name" class="text-sm font-bold text-slate-700">---</p>
                                    <p id="res-finder-dept" class="text-xs text-slate-500">---</p>
                                </div>
                                <div>
                                    <p class="text-[10px] font-black text-slate-400 uppercase">Found Location</p>
                                    <p id="res-found-loc" class="text-sm font-bold text-slate-700">---</p>
                                </div>
                            </div>
                        </div>

                        <!-- Processing Form -->
                        <div class="pt-6 border-t border-slate-100 space-y-4">
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2">Assign Physical Shelf Location</label>
                                <div class="grid grid-cols-2 gap-3">
                                    <select id="shelf-select" class="bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold focus:ring-2 focus:ring-cmu-blue">
                                        <option value="">Select Shelf</option>
                                        <option value="A">Shelf A (Electronics)</option>
                                        <option value="B">Shelf B (Books/Paper)</option>
                                        <option value="C">Shelf C (Accessories)</option>
                                        <option value="D">Shelf D (Bags/Clothes)</option>
                                    </select>
                                    <input type="text" id="row-input" placeholder="Row/Bin (e.g. 104)" class="bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold">
                                </div>
                            </div>

                            <button id="confirm-btn" class="w-full py-4 bg-cmu-blue text-white rounded-2xl font-black uppercase tracking-widest text-sm shadow-lg shadow-blue-100 hover:bg-slate-800 transition transform hover:-translate-y-1">
                                Confirm Receipt & Update Inventory
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="assets/scripts/qr_scanner.js"></script>
</body>
</html>