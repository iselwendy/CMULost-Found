<?php
/**
 * CMU Lost & Found - Matching Portal
 * Side-by-side comparison of Found vs Lost reports.
 */

session_start();

// Security Guard
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../core/auth.php");
    exit();
}

require_once '../core/db_config.php';

// Simulate a selected "Found Item" for comparison
$found_id = $_GET['found_id'] ?? 'TRK-88219-AM';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Matching Portal | CMU Lost & Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/styles/root.css"></link>
    <link rel="stylesheet" href="../assets/styles/admin_dashboard.css"></link>
</head>
<body class="bg-slate-50 min-h-screen flex overflow-hidden">

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
            <a href="qr_scanner.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition">
                <i class="fas fa-qrcode w-5"></i>
                <span class="text-sm font-medium">QR Intake Scanner</span>
            </a>
            <a href="matching_portal.php" class="sidebar-link active flex items-center gap-3 p-3 rounded-xl transition">
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

    <!-- Main Workspace -->
    <main class="flex-grow flex flex-col min-w-0 h-screen overflow-y-auto">
        <!-- Header -->
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between flex-shrink-0">
            <div>
                <h2 class="text-xl font-black text-slate-800 tracking-tight uppercase">AI Matching Portal</h2>
                <p class="text-xs text-slate-500">Compare physical inventory against student-submitted lost reports.</p>
            </div>
            <div class="flex items-center gap-3">
                <button class="px-4 py-2 border border-slate-200 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-50 transition">
                    <i class="fas fa-filter mr-2"></i>Filter Queue
                </button>
            </div>
        </header>

        <div class="p-6 flex-grow flex gap-6 overflow-hidden">
            
            <!-- Left Column: Potential Found Queue -->
            <div class="w-1/3 flex flex-col gap-4 overflow-hidden">
                <div class="flex items-center justify-between">
                    <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest">Verification Queue</h3>
                    <span class="bg-cmu-blue text-white text-[10px] px-2 py-0.5 rounded-full">12 Pending</span>
                </div>
                
                <div class="flex-grow overflow-y-auto pr-2 space-y-3 custom-scrollbar">
                    <!-- Active Item -->
                    <div class="bg-white p-4 rounded-2xl border-2 border-cmu-blue shadow-sm cursor-pointer transition">
                        <div class="flex gap-3">
                            <img src="https://images.unsplash.com/photo-1544947950-fa07a98d237f?w=100" class="w-16 h-16 rounded-xl object-cover bg-slate-100">
                            <div class="min-w-0">
                                <p class="text-sm font-black text-slate-800 truncate">Calculus 1 Textbook</p>
                                <p class="text-[10px] font-bold text-indigo-600 uppercase mb-1">TRK-88219-AM</p>
                                <div class="flex items-center gap-2">
                                    <span class="text-[9px] bg-green-100 text-green-700 px-2 py-0.5 rounded font-bold">3 Matches Found</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Other Queue Items -->
                    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm opacity-70 hover:opacity-100 cursor-pointer transition">
                        <div class="flex gap-3">
                            <div class="w-16 h-16 rounded-xl bg-slate-100 flex items-center justify-center text-slate-400">
                                <i class="fas fa-key text-xl"></i>
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-slate-800 truncate">Honda Key Fob</p>
                                <p class="text-[10px] text-slate-400 mb-1 font-mono">TRK-2210-LF</p>
                                <span class="text-[9px] bg-slate-100 text-slate-500 px-2 py-0.5 rounded font-bold uppercase">No Matches</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Comparison Portal -->
            <div class="w-2/3 bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
                <!-- Top Compare Header -->
                <div class="bg-slate-50 px-8 py-4 border-b border-slate-200 flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="text-center">
                            <p class="text-[10px] font-black text-slate-400 uppercase">Comparison Engine</p>
                            <h4 class="text-sm font-black text-cmu-blue">Manual Verification Mode</h4>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button class="p-2 text-slate-400 hover:text-red-500 transition"><i class="fas fa-times-circle"></i></button>
                    </div>
                </div>

                <!-- Split View -->
                <div class="flex-grow flex divide-x divide-slate-100 overflow-hidden">
                    
                    <!-- Side A: THE FOUND ITEM (Physical) -->
                    <div class="w-1/2 p-6 overflow-y-auto custom-scrollbar">
                        <div class="mb-6">
                            <span class="text-[10px] font-black bg-slate-800 text-white px-3 py-1 rounded-full uppercase tracking-tighter">Physical Inventory</span>
                            <h5 class="text-xl font-black text-slate-800 mt-3 leading-none">Calculus 1 Textbook</h5>
                            <p class="text-xs text-slate-500 mt-1 italic">Found at: Innovation Building, 3rd Floor</p>
                        </div>
                        
                        <img src="https://images.unsplash.com/photo-1544947950-fa07a98d237f?w=600" class="w-full h-48 rounded-2xl object-cover mb-6 border border-slate-100">
                        
                        <div class="space-y-4">
                            <div>
                                <label class="text-[10px] font-black text-slate-400 uppercase">Staff Notes</label>
                                <p class="text-xs text-slate-700 bg-slate-50 p-3 rounded-xl border border-slate-100 mt-1">
                                    Clean condition. Name "Mark S." written on the first page inside. Blue cover.
                                </p>
                            </div>
                            <div class="flex justify-between p-3 bg-indigo-50 rounded-xl border border-indigo-100">
                                <div>
                                    <p class="text-[10px] font-black text-indigo-400 uppercase">Shelf Location</p>
                                    <p class="text-sm font-black text-indigo-700">Shelf B, Row 4</p>
                                </div>
                                <i class="fas fa-map-marker-alt text-indigo-300"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Side B: THE LOST REPORT (Student Submission) -->
                    <div class="w-1/2 p-6 overflow-y-auto custom-scrollbar bg-slate-50/30">
                        <div class="mb-6 flex justify-between items-start">
                            <div>
                                <span class="text-[10px] font-black bg-cmu-gold text-cmu-blue px-3 py-1 rounded-full uppercase tracking-tighter">Reported Lost</span>
                                <h5 class="text-xl font-black text-slate-800 mt-3 leading-none underline decoration-cmu-gold/30">Calculus Book</h5>
                            </div>
                            <div class="text-right">
                                <div class="score-high text-[10px] font-black px-2 py-1 rounded-lg">92% MATCH</div>
                            </div>
                        </div>

                        <div class="w-full h-48 rounded-2xl bg-slate-100 flex flex-col items-center justify-center border-2 border-dashed border-slate-200 text-slate-400 mb-6">
                            <i class="fas fa-camera text-2xl mb-2"></i>
                            <p class="text-[10px] font-bold uppercase tracking-widest">No Photo Provided</p>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <label class="text-[10px] font-black text-slate-400 uppercase">Student Description</label>
                                <p class="text-xs text-slate-700 bg-white p-3 rounded-xl border border-slate-100 mt-1">
                                    I lost my Calculus textbook. It has my name "Mark" on the inside cover. I think I left it in the Innovation Building library.
                                </p>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div class="p-3 bg-white rounded-xl border border-slate-100 shadow-sm">
                                    <p class="text-[10px] font-black text-slate-400 uppercase">Reporter</p>
                                    <p class="text-xs font-bold text-slate-800">Mark Spencer</p>
                                </div>
                                <div class="p-3 bg-white rounded-xl border border-slate-100 shadow-sm">
                                    <p class="text-[10px] font-black text-slate-400 uppercase">Report Date</p>
                                    <p class="text-xs font-bold text-slate-800">Oct 23, 2023</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Footer -->
                <div class="p-6 bg-white border-t border-slate-200 flex gap-4">
                    <button class="flex-grow py-4 bg-slate-100 text-slate-500 rounded-2xl font-black uppercase text-xs hover:bg-slate-200 transition">
                        Not a Match
                    </button>
                    <button class="flex-grow py-4 bg-cmu-blue text-white rounded-2xl font-black uppercase text-xs shadow-lg shadow-blue-100 hover:bg-slate-800 transition flex items-center justify-center gap-2">
                        <i class="fas fa-comment-sms"></i>
                        Confirm & Notify Owner via SMS
                    </button>
                </div>
            </div>
        </div>
    </main>

</body>
</html>