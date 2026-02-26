<?php
/**
 * CMU Lost & Found - Admin Dashboard
 * Central management for OSA personnel.
 */

session_start();

// Security Guard: Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../core/auth.php");
    exit();
}

require_once '../core/db_config.php';

// Mock data for display (In production, these would be SQL COUNT queries)
$stats = [
    'pending_turnovers' => 12,
    'active_lost' => 45,
    'total_matches' => 8,
    'custody_items' => 124
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | CMU Lost & Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar-link:hover { background-color: rgba(255,255,255,0.1); }
        .sidebar-link.active { background-color: rgba(255,255,255,0.2); border-left: 4px solid var(--cmu-gold); }
    </style>
    <link rel="stylesheet" href="../assets/styles/root.css"></link>
</head>
<body class="bg-slate-50 min-h-screen flex">

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
            <a href="dashboard.php" class="sidebar-link active flex items-center gap-3 p-3 rounded-xl transition">
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

    <!-- Main Content Area -->
    <main class="flex-grow flex flex-col min-w-0 h-screen overflow-y-auto">
        
        <!-- Header -->
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between sticky top-0 z-50">
            <div class="flex items-center gap-4">
                <button class="lg:hidden text-slate-500"><i class="fas fa-bars text-xl"></i></button>
                <h2 class="text-xl font-black text-slate-800 tracking-tight uppercase">Admin Dashboard</h2>
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
            <!-- Summary Statistics -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Stat Card 1 -->
                <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-200 relative overflow-hidden group">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-amber-50 text-amber-600 rounded-2xl flex items-center justify-center text-xl">
                            <i class="fas fa-truck-ramp-box"></i>
                        </div>
                        <span class="text-[10px] font-bold text-amber-600 bg-amber-100 px-2 py-1 rounded-full uppercase">To Receive</span>
                    </div>
                    <h3 class="text-3xl font-black text-slate-800"><?php echo $stats['pending_turnovers']; ?></h3>
                    <p class="text-xs font-bold text-slate-400 uppercase tracking-tighter">Pending Turnovers</p>
                    <div class="absolute -right-2 -bottom-2 opacity-5 group-hover:opacity-10 transition-all">
                        <i class="fas fa-qrcode text-8xl"></i>
                    </div>
                </div>

                <!-- Stat Card 2 -->
                <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-200">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-blue-50 text-cmu-blue rounded-2xl flex items-center justify-center text-xl">
                            <i class="fas fa-search-location"></i>
                        </div>
                    </div>
                    <h3 class="text-3xl font-black text-slate-800"><?php echo $stats['active_lost']; ?></h3>
                    <p class="text-xs font-bold text-slate-400 uppercase tracking-tighter">Active Lost Reports</p>
                </div>

                <!-- Stat Card 3 -->
                <div class="bg-indigo-600 p-6 rounded-3xl shadow-lg shadow-indigo-100 text-white relative overflow-hidden">
                    <div class="flex items-center justify-between mb-4 relative z-10">
                        <div class="w-12 h-12 bg-white/20 text-white rounded-2xl flex items-center justify-center text-xl">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <span class="text-[10px] font-bold bg-white text-indigo-600 px-2 py-1 rounded-full uppercase">New Alerts</span>
                    </div>
                    <h3 class="text-3xl font-black relative z-10"><?php echo $stats['total_matches']; ?></h3>
                    <p class="text-xs font-bold text-indigo-100 uppercase tracking-tighter relative z-10">High-Probability Matches</p>
                    <i class="fas fa-microchip absolute -right-4 -bottom-4 text-white/10 text-8xl"></i>
                </div>

                <!-- Stat Card 4 -->
                <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-200">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-green-50 text-green-600 rounded-2xl flex items-center justify-center text-xl">
                            <i class="fas fa-warehouse"></i>
                        </div>
                    </div>
                    <h3 class="text-3xl font-black text-slate-800"><?php echo $stats['custody_items']; ?></h3>
                    <p class="text-xs font-bold text-slate-400 uppercase tracking-tighter">Items in OSA Custody</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Recent Activity / Items Needing Attention -->
                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
                        <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
                            <h4 class="font-black text-slate-800 uppercase text-sm tracking-widest">Awaiting Physical Turnover</h4>
                            <a href="qr_scanner.php" class="text-xs font-bold text-cmu-blue hover:underline">Open Scanner &rarr;</a>
                        </div>
                        <div class="divide-y divide-slate-50">
                            <!-- Item row -->
                            <div class="p-4 flex items-center gap-4 hover:bg-slate-50 transition">
                                <div class="w-14 h-14 rounded-xl bg-slate-100 flex-shrink-0 flex items-center justify-center text-slate-400">
                                    <i class="fas fa-wallet text-xl"></i>
                                </div>
                                <div class="flex-grow">
                                    <p class="text-sm font-bold text-slate-800 leading-none">Black Leather Wallet</p>
                                    <p class="text-[11px] text-slate-400 mt-1">Reported by: Juan Dela Cruz (Finder)</p>
                                </div>
                                <div class="text-right">
                                    <span class="block text-xs font-mono font-bold text-slate-500 uppercase">TRK-9901-LF</span>
                                    <button class="mt-1 text-[10px] font-black text-indigo-600 uppercase tracking-tighter">Process Receipt</button>
                                </div>
                            </div>
                            <!-- Repeat Item -->
                            <div class="p-4 flex items-center gap-4 hover:bg-slate-50 transition">
                                <div class="w-14 h-14 rounded-xl bg-slate-100 flex-shrink-0 flex items-center justify-center text-slate-400">
                                    <i class="fas fa-key text-xl"></i>
                                </div>
                                <div class="flex-grow">
                                    <p class="text-sm font-bold text-slate-800 leading-none">Silver Keyring (3 keys)</p>
                                    <p class="text-[11px] text-slate-400 mt-1">Reported by: Maria Clara (Finder)</p>
                                </div>
                                <div class="text-right">
                                    <span class="block text-xs font-mono font-bold text-slate-500 uppercase">TRK-4421-LF</span>
                                    <button class="mt-1 text-[10px] font-black text-indigo-600 uppercase tracking-tighter">Process Receipt</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions & Alerts -->
                <div class="space-y-6">
                    <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-200">
                        <h4 class="font-black text-slate-800 uppercase text-sm tracking-widest mb-4">Admin Shortcuts</h4>
                        <div class="grid grid-cols-2 gap-3">
                            <button class="p-4 bg-slate-50 rounded-2xl flex flex-col items-center gap-2 hover:bg-blue-50 hover:text-cmu-blue transition">
                                <i class="fas fa-file-export"></i>
                                <span class="text-[10px] font-bold uppercase">Export Log</span>
                            </button>
                            <button class="p-4 bg-slate-50 rounded-2xl flex flex-col items-center gap-2 hover:bg-blue-50 hover:text-cmu-blue transition">
                                <i class="fas fa-print"></i>
                                <span class="text-[10px] font-bold uppercase">Shelf Labels</span>
                            </button>
                            <button class="p-4 bg-slate-50 rounded-2xl flex flex-col items-center gap-2 hover:bg-blue-50 hover:text-cmu-blue transition">
                                <i class="fas fa-user-plus"></i>
                                <span class="text-[10px] font-bold uppercase">Add Admin</span>
                            </button>
                            <button class="p-4 bg-slate-50 rounded-2xl flex flex-col items-center gap-2 hover:bg-blue-50 hover:text-cmu-blue transition">
                                <i class="fas fa-cog"></i>
                                <span class="text-[10px] font-bold uppercase">Settings</span>
                            </button>
                        </div>
                    </div>

                    <div class="bg-amber-50 border border-amber-100 p-6 rounded-3xl">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 bg-amber-500 text-white rounded-xl flex items-center justify-center shadow-lg shadow-amber-200">
                                <i class="fas fa-clock"></i>
                            </div>
                            <h4 class="font-bold text-amber-800 text-sm">Aging Reports</h4>
                        </div>
                        <p class="text-xs text-amber-700 leading-relaxed mb-4">
                            You have <strong>5 items</strong> approaching the 60-day threshold. These must be moved to the archive or donated per university policy.
                        </p>
                        <a href="archive.php" class="block text-center py-3 bg-amber-500 text-white rounded-xl text-xs font-bold hover:bg-amber-600 transition shadow-md shadow-amber-200">Review Aging Items</a>
                    </div>
                </div>
            </div>
        </div>
    </main>

</body>
</html>