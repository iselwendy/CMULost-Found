<?php
/**
 * CMU Lost & Found - Claim Verification
 * Final step for item release and record archival.
 */

session_start();

// Admin Access Control
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../core/auth.php");
    exit();
}

require_once '../core/db_config.php';

// Simulate item data for the verification process
$item_id = $_GET['item_id'] ?? 'TRK-88219-AM';
$claimant_name = $_GET['name'] ?? 'Mark Spencer';
$claimant_id = $_GET['student_no'] ?? '2021-10452';
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
    <style>
        .step-active { @apply border-cmu-blue text-cmu-blue; }
        .step-inactive { @apply border-slate-200 text-slate-400; }
        .checklist-item:has(input:checked) { @apply bg-green-50 border-green-200; }
        .id-upload-zone { border: 2px dashed #e2e8f0; }
        .id-upload-zone:hover { border-color: #003366; background: #f8fafc; }
    </style>
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

    <!-- Main Content -->
    <main class="flex-grow flex flex-col min-w-0 h-screen overflow-y-auto">
        <!-- Header -->
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between flex-shrink-0">
            <div>
                <h2 class="text-xl font-black text-slate-800 tracking-tight uppercase">Final Claim Verification</h2>
                <p class="text-[10px] text-slate-500 font-bold uppercase tracking-widest">Processing Release for Case: <span class="text-indigo-600"><?php echo $item_id; ?></span></p>
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

        <div class="p-6 flex-grow flex gap-6 flex-col lg:flex-row">
            <!-- Left Panel: Claimant Info Card -->
            <div class="lg:col-span-1 space-y-6">
                <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm">
                        <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-4">Claimant Profile</h3>
                        <div class="flex flex-col items-center text-center">
                            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($claimant_name); ?>&background=003366&color=fff" class="w-24 h-24 rounded-full border-4 border-slate-50 mb-4">
                            <h4 class="text-xl font-black text-slate-800"><?php echo $claimant_name; ?></h4>
                            <p class="text-sm font-bold text-cmu-blue"><?php echo $claimant_id; ?></p>
                            <p class="text-xs text-slate-500 mt-1">BS Computer Science</p>
                        </div>
                        
                        <div class="mt-6 pt-6 border-t border-slate-100 space-y-3">
                            <div class="flex justify-between items-center">
                                <span class="text-[10px] font-black text-slate-400 uppercase">System Status</span>
                                <span class="text-[10px] font-black bg-blue-100 text-blue-700 px-2 py-0.5 rounded">Pre-Verified</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-[10px] font-black text-slate-400 uppercase">Contact Match</span>
                                <span class="text-[10px] font-black text-green-600">SMS Confirmed</span>
                            </div>
                        </div>
                </div>

                <div class="bg-indigo-900 rounded-3xl p-6 text-white shadow-xl shadow-indigo-100">
                        <h3 class="text-xs font-black text-indigo-300 uppercase tracking-widest mb-4">Item Details</h3>
                        <div class="flex gap-3 mb-4">
                            <div class="w-12 h-12 bg-white/10 rounded-xl flex items-center justify-center shrink-0">
                                <i class="fas fa-book text-xl text-white"></i>
                            </div>
                            <div>
                                <p class="text-sm font-black">Calculus 1 Textbook</p>
                                <p class="text-[10px] text-indigo-300 font-mono">INV: B4-S02</p>
                            </div>
                        </div>
                        <p class="text-[10px] leading-relaxed text-indigo-100 italic">"Ensure the name 'Mark S.' is verified on the inside cover as per report notes."</p>
                </div>
            </div>

            <!-- Right Panel: Verification Steps -->
            <div class="lg:col-span-2">
                    <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
                        <form action="process_claim.php" method="POST" id="claimForm">
                            <div class="p-8">
                                <h3 class="text-xl font-black text-slate-800 mb-6">Verification Checklist</h3>
                                
                                <!-- Steps -->
                                <div class="space-y-4">
                                    <!-- Step 1 -->
                                    <label class="checklist-item flex items-start gap-4 p-4 border border-slate-100 rounded-2xl cursor-pointer transition">
                                        <input type="checkbox" class="mt-1 w-5 h-5 rounded-md border-slate-300 text-cmu-blue focus:ring-cmu-blue" required>
                                        <div>
                                            <p class="text-sm font-black text-slate-800">Physical ID Match</p>
                                            <p class="text-xs text-slate-500">Student ID or Government ID presented matches the system profile.</p>
                                        </div>
                                    </label>

                                    <!-- Step 2 -->
                                    <label class="checklist-item flex items-start gap-4 p-4 border border-slate-100 rounded-2xl cursor-pointer transition">
                                        <input type="checkbox" class="mt-1 w-5 h-5 rounded-md border-slate-300 text-cmu-blue focus:ring-cmu-blue" required>
                                        <div>
                                            <p class="text-sm font-black text-slate-800">Private Detail Confirmation</p>
                                            <p class="text-xs text-slate-500">Claimant correctly identified non-public marks/contents of the item.</p>
                                        </div>
                                    </label>

                                    <!-- Step 3: ID Upload/Capture -->
                                    <div class="p-4 border border-slate-100 rounded-2xl bg-slate-50/50">
                                        <p class="text-sm font-black text-slate-800 mb-3">Electronic ID Capture</p>
                                        <div class="id-upload-zone rounded-xl p-8 flex flex-col items-center justify-center transition cursor-pointer bg-white">
                                            <i class="fas fa-id-card text-3xl text-slate-300 mb-3"></i>
                                            <p class="text-xs font-bold text-slate-500 uppercase tracking-widest">Scan or Upload ID Image</p>
                                            <input type="file" class="hidden" accept="image/*">
                                        </div>
                                    </div>
                                </div>

                                <!-- Staff Notes -->
                                <div class="mt-8">
                                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 block">Resolution Staff Notes</label>
                                    <textarea rows="3" class="w-full bg-slate-50 border border-slate-200 rounded-2xl p-4 text-sm focus:ring-2 focus:ring-cmu-blue outline-none transition" placeholder="Add any final observations or hand-over notes..."></textarea>
                                </div>
                            </div>

                            <!-- Footer Actions -->
                            <div class="p-8 bg-slate-50 border-t border-slate-100 flex items-center justify-between">
                                <div class="flex items-center gap-2 text-slate-400">
                                    <i class="fas fa-info-circle"></i>
                                    <span class="text-[10px] font-bold uppercase tracking-widest">Auto-Archiving Enabled</span>
                                </div>
                                <button type="submit" class="bg-cmu-blue text-white px-8 py-4 rounded-2xl font-black uppercase text-xs shadow-lg shadow-blue-100 hover:bg-slate-800 transition flex items-center gap-2">
                                    Complete Release & Archive
                                    <i class="fas fa-check-double"></i>
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Footer Disclaimer -->
                    <p class="text-center text-[10px] text-slate-400 font-medium mt-6 uppercase tracking-widest">
                        By clicking complete, the item will be removed from the public gallery and a claim serial number will be issued.
                    </p>
            </div>
        </div>

    </main>

    <!-- Success Modal (Hidden by default) -->
    <div id="successModal" class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-[40px] max-w-md w-full p-8 text-center shadow-2xl">
            <div class="w-20 h-20 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto mb-6">
                <i class="fas fa-award text-4xl"></i>
            </div>
            <h2 class="text-2xl font-black text-slate-800 mb-2">Item Successfully Released</h2>
            <p class="text-sm text-slate-500 mb-8 font-medium">Claim Serial Number has been generated and sent to the student via SMS.</p>
            
            <div class="bg-slate-50 p-6 rounded-3xl border border-slate-100 mb-8">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Receipt Serial</p>
                <p class="text-2xl font-black text-cmu-blue tracking-widest">CMU-772-991</p>
            </div>

            <button onclick="window.location.href='dashboard.php'" class="w-full py-4 bg-slate-800 text-white rounded-2xl font-black uppercase text-xs hover:bg-slate-700 transition">
                Return to Dashboard
            </button>
        </div>
    </div>

    <script>
        // Simple form handling simulation
        document.getElementById('claimForm').addEventListener('submit', function(e) {
            e.preventDefault();
            document.getElementById('successModal').classList.remove('hidden');
        });
    </script>
</body>
</html>