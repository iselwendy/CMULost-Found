<?php
/** CMU Lost & Found - Records Archive
 * Manages resolved items and aging inventory (60+ days).
*/

session_start(); // Assumed session helper

// Security Guard
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
header("Location: ../core/auth.php");
exit();
}

require_once '../core/db_config.php';

// Mock Data for Archive
$archived_items = [
    [
        'id' => 'TRK-2021-LF',
        'item' => 'Apple AirPods Pro',
        'category' => 'Electronics',
        'status' => 'Returned',
        'claimant' => 'Santi Rodriguez',
        'resolved_date' => '2023-10-15',
        'officer' => 'Admin_User_1'
    ],
    [
        'id' => 'TRK-8812-LF',
        'item' => 'Calculus Textbook',
        'category' => 'Books',
        'status' => 'Expired/Donated',
        'claimant' => 'N/A',
        'resolved_date' => '2023-09-30',
        'officer' => 'Admin_System'
    ]
];
?>

<!DOCTYPE html>

<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive & Aging Reports | OSA Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/styles/root.css"></link>
    <link rel="stylesheet" href="../assets/styles/admin_dashboard.css"></link>
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
            <a href="claim_verify.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition">
                <i class="fas fa-user-check w-5"></i>
                <span class="text-sm font-medium">Claim Verification</span>
            </a>
            <div class="pt-4 mt-4 border-t border-white/10">
                <a href="archive.php" class="sidebar-link active flex items-center gap-3 p-3 rounded-xl transition">
                    <i class="fas fa-archive w-5 text-white-300"></i>
                    <span class="text-sm font-medium text-white-100">Records Archive</span>
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

    <main class="flex-grow flex flex-col h-screen overflow-hidden">
        <!-- Header -->
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between sticky top-0 z-50">
            <div>
                <h2 class="text-xl font-black text-slate-800 tracking-tight uppercase">Records Archive</h2>
                <p class="text-xs font-bold text-slate-400 uppercase mt-1">Audit log of resolved and expired items</p>
            </div>
            
            <div class="flex gap-3">
                <button class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-50">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </button>
                <button class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-50">
                    <i class="fas fa-file-excel"></i> CSV Export
                </button>
            </div>
        </header>

        <!-- Main Scrollable Content -->
        <div class="p-8 overflow-y-auto flex-grow">
            
            <!-- Archive Search -->
            <div class="bg-white p-4 rounded-3xl border border-slate-200 mb-8 flex flex-wrap gap-4 items-center">
                <div class="flex-grow relative">
                    <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-300"></i>
                    <input type="text" placeholder="Search by Tracking ID, Claimant Name, or Item..." class="w-full pl-12 pr-4 py-3 bg-slate-50 border-none rounded-2xl text-sm focus:ring-2 focus:ring-blue-500 transition">
                </div>
                <select class="bg-slate-50 border-none rounded-2xl px-4 py-3 text-sm font-bold text-slate-600 outline-none">
                    <option>All Dates</option>
                    <option>Last 30 Days</option>
                    <option>Last 6 Months</option>
                    <option>Academic Year 2023-24</option>
                </select>
            </div>

            <!-- Archive Table -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-slate-50">
                        <tr class="bg-slate-50 border-b border-slate-200">
                            <th class="archive-table-header px-6 py-4">Tracking ID</th>
                            <th class="archive-table-header px-6 py-4">Item Description</th>
                            <th class="archive-table-header px-6 py-4">Category</th>
                            <th class="archive-table-header px-6 py-4">Claimant / Outcome</th>
                            <th class="archive-table-header px-6 py-4">Resolution Date</th>
                            <!-- Overriding text-align for the last column only -->
                            <th class="archive-table-header px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach($archived_items as $item): ?>
                        <tr class="hover:bg-slate-50/50 transition group">
                            <td class="px-6 py-5">
                                <span class="font-mono text-xs font-black text-slate-500"><?php echo $item['id']; ?></span>
                            </td>
                            <td class="px-6 py-5">
                                <p class="text-sm font-bold text-slate-800"><?php echo $item['item']; ?></p>
                            </td>
                            <td class="px-6 py-5">
                                <span class="text-[10px] font-black uppercase bg-slate-100 text-slate-500 px-2 py-1 rounded-md">
                                    <?php echo $item['category']; ?>
                                </span>
                            </td>
                            <td class="px-6 py-5">
                                <div class="flex flex-col">
                                    <span class="text-sm font-bold text-slate-700"><?php echo $item['claimant']; ?></span>
                                    <span class="text-[10px] font-bold text-green-500 uppercase"><?php echo $item['status']; ?></span>
                                </div>
                            </td>
                            <td class="px-6 py-5 text-xs font-bold text-slate-400">
                                <?php echo date('M d, Y', strtotime($item['resolved_date'])); ?>
                            </td>
                            <td class="px-6 py-5 text-right">
                                <button class="text-slate-400 hover:text-cmu-blue transition">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="ml-3 text-slate-400 hover:text-red-500 transition">
                                    <i class="fas fa-print"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Empty State Placeholder -->
                <div class="p-12 text-center hidden">
                    <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-folder-open text-slate-200 text-3xl"></i>
                    </div>
                    <h3 class="font-bold text-slate-800">No records found</h3>
                    <p class="text-sm text-slate-400">Try adjusting your filters or search terms.</p>
                </div>
            </div>

            <!-- Retention Policy Reminder -->
            <div class="mt-8 p-6 bg-slate-800 rounded-3xl text-white flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-white/10 rounded-2xl flex items-center justify-center text-xl text-blue-300">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div>
                        <p class="text-sm font-bold">University Retention Policy</p>
                        <p class="text-xs text-slate-400">Items are kept for a maximum of 60 days. Unclaimed items are processed for donation or disposal.</p>
                    </div>
                </div>
                <button class="px-6 py-3 bg-blue-600 hover:bg-blue-500 rounded-xl text-xs font-bold transition">
                    Manage Disposal List
                </button>
            </div>
        </div>
    </main>


</body>
</html>