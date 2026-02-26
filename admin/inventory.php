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

// Filter logic (simulated for UI)
$status_filter = $_GET['status'] ?? 'all';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Physical Inventory | CMU Lost & Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/styles/root.css"></link>
    <link rel="stylesheet" href="../assets/styles/admin_dashboard.css"></link>
</head>
<body class="bg-slate-50 min-h-screen flex">

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
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between flex-shrink-0">
            <div>
                <h2 class="text-xl font-black text-slate-800 tracking-tight uppercase">Physical Inventory</h2>
                <p class="text-xs text-slate-500">Track item locations and manage OSA custody records.</p>
            </div>
            
            <div class="flex items-center gap-4">
                <div class="relative hidden md:block">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                    <input type="text" placeholder="Search Tracking ID..." class="pl-10 pr-4 py-2 bg-slate-100 border-transparent focus:bg-white focus:border-cmu-blue border rounded-xl text-sm transition w-64">
                </div>
                <button class="bg-cmu-blue text-white px-4 py-2 rounded-xl text-sm font-bold shadow-md hover:bg-slate-800 transition">
                    <i class="fas fa-plus mr-2"></i>Manual Entry
                </button>
            </div>
        </header>

        <!-- Main Workspace -->
        <div class="p-8 flex-grow flex flex-col gap-6 overflow-hidden">
            
            <!-- Filters & Tabs -->
            <div class="flex flex-col md:flex-row items-center justify-between gap-4 flex-shrink-0">
                <div class="flex bg-white p-1 rounded-xl border border-slate-200 shadow-sm">
                    <a href="?status=all" class="px-4 py-2 text-xs font-bold rounded-lg transition <?php echo $status_filter === 'all' ? 'bg-cmu-blue text-white shadow-md' : 'text-slate-500 hover:bg-slate-50'; ?>">All Items</a>
                    <a href="?status=custody" class="px-4 py-2 text-xs font-bold rounded-lg transition <?php echo $status_filter === 'custody' ? 'bg-cmu-blue text-white shadow-md' : 'text-slate-500 hover:bg-slate-50'; ?>">In Custody</a>
                    <a href="?status=pending" class="px-4 py-2 text-xs font-bold rounded-lg transition <?php echo $status_filter === 'pending' ? 'bg-cmu-blue text-white shadow-md' : 'text-slate-500 hover:bg-slate-50'; ?>">Pending Receipt</a>
                </div>
                
                <div class="flex items-center gap-2">
                    <span class="text-xs font-bold text-slate-400 uppercase mr-2">Display:</span>
                    <button class="w-9 h-9 flex items-center justify-center bg-white border border-slate-200 rounded-lg text-slate-600 shadow-sm"><i class="fas fa-list"></i></button>
                    <button class="w-9 h-9 flex items-center justify-center bg-slate-100 border border-slate-200 rounded-lg text-slate-400"><i class="fas fa-th-large"></i></button>
                </div>
            </div>

            <!-- Inventory Table -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm flex-grow overflow-hidden flex flex-col">
                <div class="overflow-x-auto custom-scrollbar">
                    <table class="w-full text-left border-collapse min-w-[1000px]">
                        <thead>
                            <tr class="bg-slate-50/50 border-b border-slate-100">
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Item Info</th>
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tracking ID</th>
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Shelf Location</th>
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Date Recieved</th>
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <!-- Mock Row 1 -->
                            <tr class="hover:bg-slate-50/80 transition group">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-12 h-12 rounded-lg bg-slate-200 overflow-hidden flex-shrink-0">
                                            <img src="https://images.unsplash.com/photo-1544947950-fa07a98d237f?w=100&q=80" class="w-full h-full object-cover">
                                        </div>
                                        <div>
                                            <p class="text-sm font-bold text-slate-800">Calculus 1 Textbook</p>
                                            <p class="text-[10px] text-slate-400">Category: Books</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="font-mono text-xs font-bold text-indigo-600 bg-indigo-50 px-2 py-1 rounded">TRK-88219-AM</span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="text-[10px] font-bold px-2.5 py-1 rounded-full border border-green-200 bg-green-50 text-green-700 uppercase">In Custody</span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded bg-slate-100 flex items-center justify-center border border-slate-200 text-[10px] font-black text-slate-600">B4</div>
                                        <span class="text-xs text-slate-500 italic">Shelf B, Row 4</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-500 font-medium">Oct 24, 2023</td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-center gap-2">
                                        <button class="p-2 text-slate-400 hover:text-cmu-blue transition" title="Edit Item"><i class="fas fa-pen-to-square"></i></button>
                                        <button class="p-2 text-slate-400 hover:text-indigo-600 transition" title="Verify Matches"><i class="fas fa-sync"></i></button>
                                        <button class="p-2 text-slate-400 hover:text-red-600 transition" title="Archive"><i class="fas fa-archive"></i></button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Mock Row 2 -->
                            <tr class="hover:bg-slate-50/80 transition group">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-12 h-12 rounded-lg bg-slate-100 flex items-center justify-center text-slate-400">
                                            <i class="fas fa-wallet"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm font-bold text-slate-800">Black Leather Wallet</p>
                                            <p class="text-[10px] text-slate-400">Category: Personal Effects</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="font-mono text-xs font-bold text-slate-400 bg-slate-50 px-2 py-1 rounded border border-slate-100">TRK-9901-LF</span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="text-[10px] font-bold px-2.5 py-1 rounded-full border border-amber-200 bg-amber-50 text-amber-700 uppercase">Pending Turnover</span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="text-[10px] text-slate-300 italic">Not Assigned</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-300 italic">--/--/--</td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-center gap-2">
                                        <button class="px-3 py-1 bg-cmu-blue text-white text-[10px] font-black rounded-lg uppercase tracking-tight shadow-sm hover:bg-slate-800">Scan Receipt</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination / Footer -->
                <div class="p-4 border-t border-slate-100 flex items-center justify-between flex-shrink-0 bg-slate-50/30">
                    <p class="text-[11px] text-slate-500">Showing <strong>2</strong> of <strong>124</strong> items in inventory</p>
                    <div class="flex gap-1">
                        <button class="w-8 h-8 rounded bg-white border border-slate-200 text-slate-400 flex items-center justify-center text-xs disabled:opacity-50" disabled><i class="fas fa-chevron-left"></i></button>
                        <button class="w-8 h-8 rounded bg-cmu-blue text-white flex items-center justify-center text-xs font-bold shadow-sm">1</button>
                        <button class="w-8 h-8 rounded bg-white border border-slate-200 text-slate-600 flex items-center justify-center text-xs hover:bg-slate-50">2</button>
                        <button class="w-8 h-8 rounded bg-white border border-slate-200 text-slate-600 flex items-center justify-center text-xs hover:bg-slate-50">3</button>
                        <button class="w-8 h-8 rounded bg-white border border-slate-200 text-slate-600 flex items-center justify-center text-xs hover:bg-slate-50"><i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal: Edit Shelf Location (Hidden) -->
    <div id="locationModal" class="fixed inset-0 z-[60] hidden bg-slate-900/50 flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl w-full max-w-md p-8 shadow-2xl">
            <h3 class="text-xl font-black text-slate-800 mb-2 uppercase tracking-tight">Assign Shelf Location</h3>
            <p class="text-xs text-slate-500 mb-6">Physical storage coordinates for <span class="font-bold text-cmu-blue">TRK-88219-AM</span></p>
            
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Section/Shelf</label>
                        <select class="w-full bg-slate-100 border-none rounded-xl p-3 text-sm font-bold">
                            <option>Shelf A</option>
                            <option>Shelf B</option>
                            <option>Shelf C (Valuables)</option>
                            <option>Shelf D (Textbooks)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Row Number</label>
                        <input type="number" min="1" max="10" value="1" class="w-full bg-slate-100 border-none rounded-xl p-3 text-sm font-bold">
                    </div>
                </div>
                <div class="p-4 bg-blue-50 rounded-2xl flex gap-3 border border-blue-100">
                    <i class="fas fa-info-circle text-cmu-blue mt-1"></i>
                    <p class="text-[11px] text-blue-700 leading-relaxed italic">Assigning a location updates the record immediately, helping other OSA staff locate the item for verification.</p>
                </div>
            </div>

            <div class="mt-8 flex gap-3">
                <button class="flex-grow py-3 bg-slate-100 text-slate-600 rounded-xl font-bold text-sm hover:bg-slate-200 transition">Cancel</button>
                <button class="flex-grow py-3 bg-cmu-blue text-white rounded-xl font-bold text-sm shadow-lg shadow-blue-100 hover:bg-slate-800 transition">Save Location</button>
            </div>
        </div>
    </div>

    <script>
        // Simple UI Toggle for Modal (Example)
        function toggleLocationModal() {
            document.getElementById('locationModal').classList.toggle('hidden');
        }
    </script>
</body>
</html>