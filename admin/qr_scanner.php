<?php
/**
 * CMU Lost & Found - QR Intake Scanner
 * High-speed tool for OSA Admins to process physical turnovers.
 */

session_start();

// Security Guard — fixed: use 'user_role' to match auth.php session key
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
    <script src="https://unpkg.com/html5-qrcode"></script>
    <link rel="stylesheet" href="../assets/styles/root.css">
    <link rel="stylesheet" href="../assets/styles/admin_dashboard.css">
    <style>
        #reader { border: none !important; border-radius: 1.5rem; overflow: hidden; }
        #reader__dashboard_section_csr button {
            background: #003366 !important; color: white !important;
            border-radius: 0.5rem !important; padding: 8px 16px !important;
            font-size: 12px; font-weight: bold; text-transform: uppercase;
            cursor: pointer;
        }
        .sidebar-link.active { background: rgba(255,255,255,0.1); border-left: 4px solid #FFD700; }
        .processing-active { opacity: 1 !important; pointer-events: auto !important; transform: scale(1) !important; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col lg:flex-row">

    <!-- Sidebar -->
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
                <p class="text-sm font-bold truncate"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                <a href="../core/logout.php" class="text-xs text-cmu-blue font-bold mt-2 py-2 px-4 inline-block rounded-md bg-cmu-gold hover:rounded-full hover:text-cmu-gold hover:bg-white">Logout Session</a>
            </div>
        </div>
    </aside>

    <!-- Content -->
    <main class="flex-grow flex flex-col min-w-0 h-screen overflow-hidden">

        <!-- Header -->
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between flex-shrink-0 gap-4">
            <div>
                <h2 class="text-xl font-black text-slate-800 tracking-tight uppercase">QR Intake Scanner</h2>
                <p class="text-xs text-slate-500">Scan finder's QR code to confirm physical receipt of item.</p>
            </div>
            <button onclick="location.reload()" class="p-3 bg-white border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50">
                <i class="fas fa-rotate"></i>
            </button>
        </header>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-8 mt-6 px-8 pb-8 overflow-y-auto">

            <!-- Scanner Section -->
            <div class="space-y-6">
                <div class="bg-white p-4 rounded-3xl shadow-sm border border-slate-200 overflow-hidden relative min-h-[400px]">
                    <div id="reader"></div>

                    <!-- Scan Success Overlay -->
                    <div id="scan-feedback" class="absolute inset-0 bg-green-600/95 hidden flex-col items-center justify-center text-white z-50">
                        <div class="animate-bounce mb-4"><i class="fas fa-check-circle text-7xl"></i></div>
                        <h3 class="text-2xl font-black uppercase tracking-widest">Item Identified</h3>
                        <p class="text-sm opacity-80 mt-2">Loading record details...</p>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-100 p-6 rounded-3xl flex gap-4">
                    <div class="w-12 h-12 bg-cmu-blue text-white rounded-2xl flex items-center justify-center text-xl flex-shrink-0">
                        <i class="fas fa-qrcode"></i>
                    </div>
                    <div>
                        <h4 class="font-bold text-cmu-blue text-sm">Scanner Instructions</h4>
                        <p class="text-xs text-blue-700 leading-relaxed mt-1">
                            Scan the QR code shown by the finder. The system will pull the item's report for verification before you assign a shelf location.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Item Processing Section -->
            <div id="processing-pane" class="opacity-40 pointer-events-none transition-all duration-500 scale-[0.98] origin-top">
                <div class="bg-white rounded-3xl shadow-xl border-2 border-slate-200 overflow-hidden">
                    <div class="bg-slate-50 px-8 py-6 border-b border-slate-100 flex justify-between items-center">
                        <div>
                            <span id="res-tracking-id" class="text-[10px] font-black bg-indigo-100 text-indigo-700 px-3 py-1 rounded-full uppercase tracking-widest">
                                Waiting for Scan
                            </span>
                            <h3 id="res-item-name" class="text-2xl font-black text-slate-800 mt-2">Item Verification</h3>
                        </div>
                        <button onclick="resetScannerUI()" class="text-xs font-bold text-slate-400 hover:text-red-500 underline">
                            Reset Scanner
                        </button>
                    </div>

                    <div class="p-8 space-y-6">
                        <div class="flex flex-col md:flex-row gap-6">
                            <div id="res-image-container"
                                 class="w-full md:w-40 h-40 bg-slate-100 rounded-2xl flex items-center justify-center text-slate-300 overflow-hidden border border-slate-100 flex-shrink-0">
                                <i class="fas fa-image text-4xl"></i>
                            </div>
                            <div class="flex-grow grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <p class="text-[10px] font-black text-slate-400 uppercase">Finder Details</p>
                                    <p id="res-finder-name" class="text-sm font-bold text-slate-700">---</p>
                                    <p id="res-finder-dept" class="text-xs text-slate-500">---</p>
                                </div>
                                <div>
                                    <p class="text-[10px] font-black text-slate-400 uppercase">Found Location</p>
                                    <p id="res-found-loc" class="text-sm font-bold text-slate-700">---</p>
                                </div>
                                <div class="col-span-full">
                                    <p class="text-[10px] font-black text-slate-400 uppercase">Description</p>
                                    <p id="res-description" class="text-xs text-slate-600 italic">No description available.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Shelf Assignment -->
                        <div class="pt-6 border-t border-slate-100 space-y-5">
                            <div class="bg-slate-50 p-5 rounded-2xl border border-slate-200">
                                <label class="block text-[10px] font-black text-slate-500 uppercase mb-3 text-center">
                                    Assign Physical Storage
                                </label>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div class="relative">
                                        <i class="fas fa-layer-group absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                        <select id="shelf-select"
                                                class="w-full bg-white border border-slate-200 rounded-xl pl-10 pr-4 py-3 text-sm font-bold focus:ring-2 focus:ring-cmu-blue outline-none appearance-none">
                                            <option value="">Select Shelf</option>
                                            <option value="A">Shelf A (Electronics)</option>
                                            <option value="B">Shelf B (Books/Paper)</option>
                                            <option value="C">Shelf C (Accessories)</option>
                                            <option value="D">Shelf D (Bags/Clothes)</option>
                                            <option value="V">Vault (Valuables)</option>
                                        </select>
                                    </div>
                                    <div class="relative">
                                        <i class="fas fa-hashtag absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                        <input type="text" id="row-input" placeholder="Bin/Row (e.g. 101)"
                                               class="w-full bg-white border border-slate-200 rounded-xl pl-10 pr-4 py-3 text-sm font-bold focus:ring-2 focus:ring-cmu-blue outline-none">
                                    </div>
                                </div>
                            </div>

                            <button id="confirm-btn"
                                    class="w-full py-4 bg-cmu-blue text-white rounded-2xl font-black uppercase tracking-widest text-sm shadow-lg hover:opacity-90 transition transform active:scale-[0.97]">
                                <i class="fas fa-check-double mr-2"></i> Confirm & Update Inventory
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const html5QrCode   = new Html5Qrcode("reader");
        const processingPane = document.getElementById('processing-pane');
        const scanFeedback  = document.getElementById('scan-feedback');
        const confirmBtn    = document.getElementById('confirm-btn');

        // Display elements
        const resTrackingId     = document.getElementById('res-tracking-id');
        const resItemName       = document.getElementById('res-item-name');
        const resFinderName     = document.getElementById('res-finder-name');
        const resFinderDept     = document.getElementById('res-finder-dept');
        const resFoundLoc       = document.getElementById('res-found-loc');
        const resDescription    = document.getElementById('res-description');
        const resImageContainer = document.getElementById('res-image-container');

        const qrConfig = { fps: 15, qrbox: { width: 250, height: 250 }, aspectRatio: 1.0 };

        // ── Scan success handler ──────────────────────────────────────────────
        const onScanSuccess = (decodedText) => {
            // Pause scanner immediately to prevent duplicate scans
            html5QrCode.pause(true);

            scanFeedback.classList.remove('hidden');
            scanFeedback.classList.add('flex');

            fetchItemDetails(decodedText);
        };

        // ── Fetch item from backend ───────────────────────────────────────────
        async function fetchItemDetails(trackingId) {
            try {
                const response = await fetch(
                    `../core/get_item_details.php?tracking_id=${encodeURIComponent(trackingId)}`
                );

                // If the server returns non-JSON (e.g. PHP error page), this will throw
                const data = await response.json();

                if (data.success && data.item) {
                    populateUI(data.item);
                    unlockPane();
                } else {
                    showToast(data.message || 'Record not found for this QR code.', 'error');
                    html5QrCode.resume();
                }
            } catch (err) {
                // API not yet set up — fall back to simulation so the UI is testable
                console.warn('API unavailable — using simulated data.', err);
                simulateData(trackingId);
                unlockPane();
            } finally {
                setTimeout(() => {
                    scanFeedback.classList.add('hidden');
                    scanFeedback.classList.remove('flex');
                }, 800);
            }
        }

        // ── Populate result fields ────────────────────────────────────────────
        function populateUI(item) {
            resTrackingId.innerText  = item.tracking_id  || 'N/A';
            resItemName.innerText    = item.title   || 'Unknown Item';
            resFinderName.innerText  = item.finder_name  || 'Anonymous';
            resFinderDept.innerText  = item.finder_dept  || 'N/A';
            resFoundLoc.innerText    = item.found_location    || 'Unknown';
            resDescription.innerText = item.private_description  || 'No description provided.';

            resImageContainer.innerHTML = item.image_path
                ? `<img src="${item.image_path}" class="w-full h-full object-cover" alt="Item photo">`
                : `<i class="fas fa-image text-4xl text-slate-200"></i>`;
        }

        function unlockPane() {
            processingPane.classList.add('processing-active');
        }

        // ── Reset so admin can scan another item ──────────────────────────────
        window.resetScannerUI = function () {
            processingPane.classList.remove('processing-active');
            document.getElementById('shelf-select').value = '';
            document.getElementById('row-input').value    = '';
            resTrackingId.innerText  = 'Waiting for Scan';
            resItemName.innerText    = 'Item Verification';
            resFinderName.innerText  = '---';
            resFinderDept.innerText  = '---';
            resFoundLoc.innerText    = '---';
            resDescription.innerText = 'No description available.';
            resImageContainer.innerHTML = `<i class="fas fa-image text-4xl text-slate-200"></i>`;
            html5QrCode.resume();
        };

        // ── Confirm button: update inventory ─────────────────────────────────
        confirmBtn.addEventListener('click', async () => {
            const shelf = document.getElementById('shelf-select').value;
            const bin   = document.getElementById('row-input').value.trim();
            const tid   = resTrackingId.innerText;

            if (!shelf || !bin) {
                showToast('Please assign a shelf and bin before confirming.', 'warning');
                return;
            }

            confirmBtn.disabled = true;
            confirmBtn.innerHTML = `<i class="fas fa-spinner fa-spin mr-2"></i> Updating...`;

            try {
                const response = await fetch('../core/update_inventory_status.php', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({
                        tracking_id:    tid,
                        shelf_location: shelf,
                        bin_number:     bin,
                        status:         'surrendered'
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showToast('Turnover confirmed! Inventory updated.', 'success');
                    setTimeout(() => location.reload(), 1800);
                } else {
                    showToast(result.message || 'Failed to update inventory.', 'error');
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = `<i class="fas fa-check-double mr-2"></i> Confirm & Update Inventory`;
                }
            } catch (e) {
                showToast('Network error — check your connection.', 'error');
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = `<i class="fas fa-check-double mr-2"></i> Confirm & Update Inventory`;
            }
        });

        // ── Toast notification ────────────────────────────────────────────────
        function showToast(msg, type) {
            const colors = { success: 'bg-green-600', error: 'bg-red-600', warning: 'bg-amber-500' };
            const toast  = document.createElement('div');
            toast.className = `fixed bottom-10 left-1/2 -translate-x-1/2 ${colors[type] || 'bg-slate-700'} text-white px-8 py-4 rounded-2xl font-bold shadow-2xl z-[100] transition-all duration-300 translate-y-10 opacity-0`;
            toast.innerText = msg;
            document.body.appendChild(toast);
            setTimeout(() => toast.classList.remove('translate-y-10', 'opacity-0'), 100);
            setTimeout(() => {
                toast.classList.add('opacity-0');
                setTimeout(() => toast.remove(), 500);
            }, 3500);
        }

        // ── Fallback simulation (when API files don't exist yet) ──────────────
        function simulateData(tid) {
            populateUI({
                tracking_id:  tid,
                title:    'Demo Item (Simulation Mode)',
                finder_name:  'Test Finder',
                finder_dept:  'CCS — BSIT',
                found_location:     'Main Lobby',
                private_description:  'API endpoint not yet connected. This is simulated data.',
                image_path:    null
            });
        }

        // ── Start camera ──────────────────────────────────────────────────────
        html5QrCode.start({ facingMode: 'environment' }, qrConfig, onScanSuccess)
            .catch(() => {
                document.getElementById('reader').innerHTML = `
                    <div class="p-12 text-center text-slate-400">
                        <i class="fas fa-video-slash text-5xl mb-4 block"></i>
                        <p class="text-sm font-bold text-slate-600">Camera permission required</p>
                        <p class="text-xs mt-1">Please allow camera access and reload the page.</p>
                        <button onclick="location.reload()"
                                class="mt-4 px-6 py-2 bg-cmu-blue text-white rounded-lg text-xs font-bold uppercase">
                            Retry
                        </button>
                    </div>`;
            });
    });
    </script>
</body>
</html>