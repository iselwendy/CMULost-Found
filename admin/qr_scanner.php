<?php
/**
 * CMU Lost & Found - QR Intake Scanner
 * High-speed tool for OSA Admins to process physical turnovers.
 */

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../core/auth.php");
    exit();
}

require_once '../core/db_config.php';

// Pre-fill tracking ID if arriving from inventory.php
$prefill_id = htmlspecialchars($_GET['prefill'] ?? '');
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
            font-size: 12px; font-weight: bold; text-transform: uppercase; cursor: pointer;
        }
        .processing-active { opacity: 1 !important; pointer-events: auto !important; transform: scale(1) !important; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col lg:flex-row">

    <!-- ── Sidebar ───────────────────────────────────────────────────────── -->
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
                <p class="text-sm font-bold truncate"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['user_name'] ?? 'Admin'); ?></p>
                <a href="../core/logout.php"
                   class="text-xs text-cmu-blue font-bold mt-2 py-2 px-4 inline-block rounded-md bg-cmu-gold hover:rounded-full hover:text-cmu-gold hover:bg-white">
                    Logout Session
                </a>
            </div>
        </div>
    </aside>

    <!-- ── Content ───────────────────────────────────────────────────────── -->
    <main class="flex-grow flex flex-col min-w-0 h-screen overflow-hidden">

        <!-- Header -->
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between flex-shrink-0 gap-4">
            <div>
                <h2 class="text-xl font-black text-slate-800 tracking-tight uppercase">QR Intake Scanner</h2>
                <p class="text-xs text-slate-500">Scan the finder's QR code to confirm physical receipt and shelve the item.</p>
            </div>
            <button onclick="resetScannerUI()"
                    class="p-3 bg-white border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 transition" title="Reset scanner">
                <i class="fas fa-rotate"></i>
            </button>
        </header>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-8 mt-6 px-8 pb-8 overflow-y-auto">

            <!-- ── Left: Scanner ──────────────────────────────────────── -->
            <div class="space-y-6">

                <!-- Manual tracking ID entry (also used when arriving from inventory.php via ?prefill=) -->
                <div class="bg-white p-5 rounded-3xl shadow-sm border border-slate-200">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">
                        Manual Lookup
                    </p>
                    <div class="flex gap-3">
                        <div class="relative flex-grow">
                            <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                            <input type="text" id="manualTrackingId"
                                   placeholder="FND-00042"
                                   value="<?php echo $prefill_id; ?>"
                                   class="w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-sm font-mono font-bold focus:ring-2 focus:ring-cmu-blue outline-none transition uppercase">
                        </div>
                        <button onclick="lookupManual()"
                                class="px-5 py-3 bg-cmu-blue text-white rounded-2xl text-sm font-bold hover:bg-slate-800 transition">
                            Lookup
                        </button>
                    </div>
                    <?php if ($prefill_id): ?>
                    <p class="text-[10px] text-amber-600 mt-2 font-semibold">
                        <i class="fas fa-info-circle mr-1"></i>
                        Pre-filled from Inventory. Click <strong>Lookup</strong> or scan the QR code to load item details.
                    </p>
                    <?php endif; ?>
                </div>

                <!-- Camera scanner -->
                <div class="bg-white p-4 rounded-3xl shadow-sm border border-slate-200 overflow-hidden relative min-h-[360px]">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">
                        Camera Scanner
                    </p>
                    <div id="reader"></div>

                    <!-- Scan success overlay -->
                    <div id="scan-feedback"
                         class="absolute inset-0 bg-green-600/95 hidden flex-col items-center justify-center text-white z-50 rounded-3xl">
                        <div class="animate-bounce mb-4"><i class="fas fa-check-circle text-7xl"></i></div>
                        <h3 class="text-2xl font-black uppercase tracking-widest">QR Detected</h3>
                        <p class="text-sm opacity-80 mt-2">Loading item details...</p>
                    </div>
                </div>

                <!-- Instructions -->
                <div class="bg-blue-50 border border-blue-100 p-5 rounded-3xl flex gap-4">
                    <div class="w-11 h-11 bg-cmu-blue text-white rounded-2xl flex items-center justify-center text-lg flex-shrink-0">
                        <i class="fas fa-qrcode"></i>
                    </div>
                    <div>
                        <h4 class="font-bold text-cmu-blue text-sm">How to process a turnover</h4>
                        <ol class="text-xs text-blue-700 leading-relaxed mt-1 space-y-1 list-decimal list-inside">
                            <li>Ask the finder to show their Turnover QR Code from their dashboard.</li>
                            <li>Scan it with the camera above, or type the Tracking ID manually.</li>
                            <li>Assign a shelf and bin, then click <strong>Confirm</strong>.</li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- ── Right: Processing pane ─────────────────────────────── -->
            <div id="processing-pane"
                 class="opacity-40 pointer-events-none transition-all duration-500 scale-[0.98] origin-top">
                <div class="bg-white rounded-3xl shadow-xl border-2 border-slate-200 overflow-hidden">

                    <!-- Pane header -->
                    <div class="bg-slate-50 px-8 py-6 border-b border-slate-100 flex justify-between items-start">
                        <div class="min-w-0">
                            <span id="res-tracking-id"
                                  class="text-[10px] font-black bg-indigo-100 text-indigo-700 px-3 py-1 rounded-full uppercase tracking-widest">
                                Waiting for Scan
                            </span>
                            <h3 id="res-item-name" class="text-2xl font-black text-slate-800 mt-2 truncate">
                                Item Verification
                            </h3>
                            <p id="res-status-pill" class="mt-1 hidden">
                                <span class="text-[10px] font-bold px-2.5 py-1 rounded-full border border-amber-200 bg-amber-50 text-amber-700 uppercase">
                                    Pending Turnover
                                </span>
                            </p>
                        </div>
                        <button onclick="resetScannerUI()"
                                class="text-xs font-bold text-slate-400 hover:text-red-500 underline flex-shrink-0 ml-4">
                            Reset
                        </button>
                    </div>

                    <!-- Item details -->
                    <div class="p-8 space-y-6">
                        <div class="flex flex-col md:flex-row gap-6">

                            <!-- Thumbnail -->
                            <div id="res-image-container"
                                 class="w-full md:w-40 h-40 bg-slate-100 rounded-2xl flex items-center justify-center text-slate-300 overflow-hidden border border-slate-100 flex-shrink-0">
                                <i class="fas fa-image text-4xl"></i>
                            </div>

                            <!-- Info grid -->
                            <div class="flex-grow grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                                <div>
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-0.5">Finder</p>
                                    <p id="res-finder-name" class="font-bold text-slate-700">—</p>
                                    <p id="res-finder-dept" class="text-xs text-slate-400">—</p>
                                </div>
                                <div>
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-0.5">Found At</p>
                                    <p id="res-found-loc" class="font-bold text-slate-700">—</p>
                                </div>
                                <div>
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-0.5">Category</p>
                                    <p id="res-category" class="font-bold text-slate-700">—</p>
                                </div>
                                <div>
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-0.5">Date Found</p>
                                    <p id="res-date" class="font-bold text-slate-700">—</p>
                                </div>
                                <div class="col-span-full">
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-0.5">
                                        Private Notes
                                    </p>
                                    <p id="res-description" class="text-xs text-slate-600 italic bg-amber-50 border border-amber-100 rounded-xl p-3">
                                        No description available.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Shelf assignment -->
                        <div class="pt-6 border-t border-slate-100 space-y-5">
                            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest text-center">
                                Assign Physical Storage Location
                            </p>

                            <div class="bg-slate-50 p-5 rounded-2xl border border-slate-200 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="relative">
                                    <i class="fas fa-layer-group absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                    <select id="shelf-select"
                                            class="w-full bg-white border border-slate-200 rounded-xl pl-10 pr-4 py-3 text-sm font-bold focus:ring-2 focus:ring-cmu-blue outline-none appearance-none">
                                        <option value="">— Select Shelf —</option>
                                        <option value="A">Shelf A (Electronics)</option>
                                        <option value="B">Shelf B (Books / Paper)</option>
                                        <option value="C">Shelf C (Accessories)</option>
                                        <option value="D">Shelf D (Bags / Clothes)</option>
                                        <option value="V">Vault (Valuables)</option>
                                    </select>
                                </div>
                                <div class="relative">
                                    <i class="fas fa-hashtag absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                    <input type="text" id="row-input"
                                           placeholder="Bin / Row  e.g. 101"
                                           class="w-full bg-white border border-slate-200 rounded-xl pl-10 pr-4 py-3 text-sm font-bold focus:ring-2 focus:ring-cmu-blue outline-none">
                                </div>
                            </div>

                            <!-- Shelf preview badge (updates live) -->
                            <div id="shelf-preview" class="hidden text-center">
                                <span class="inline-flex items-center gap-2 px-4 py-2 bg-green-50 border border-green-200 rounded-full text-sm font-black text-green-700">
                                    <i class="fas fa-map-pin text-xs"></i>
                                    Shelf <span id="preview-shelf">—</span> · Bin <span id="preview-bin">—</span>
                                </span>
                            </div>

                            <!-- Confirm button -->
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

    <!-- ── Toast ─────────────────────────────────────────────────────────── -->
    <div id="toast-container" class="fixed bottom-10 left-1/2 -translate-x-1/2 z-[100] flex flex-col gap-2 items-center pointer-events-none"></div>

    <script>
    (function () {
        // ── State ─────────────────────────────────────────────────────────
        const PREFILL = '<?php echo $prefill_id; ?>';
        let currentTrackingId = '';
        let html5QrCode = null;

        // ── DOM refs ──────────────────────────────────────────────────────
        const processingPane   = document.getElementById('processing-pane');
        const scanFeedback     = document.getElementById('scan-feedback');
        const confirmBtn       = document.getElementById('confirm-btn');
        const manualInput      = document.getElementById('manualTrackingId');
        const shelfSelect      = document.getElementById('shelf-select');
        const rowInput         = document.getElementById('row-input');

        const resTrackingId    = document.getElementById('res-tracking-id');
        const resItemName      = document.getElementById('res-item-name');
        const resFinderName    = document.getElementById('res-finder-name');
        const resFinderDept    = document.getElementById('res-finder-dept');
        const resFoundLoc      = document.getElementById('res-found-loc');
        const resCategory      = document.getElementById('res-category');
        const resDate          = document.getElementById('res-date');
        const resDescription   = document.getElementById('res-description');
        const resImageContainer= document.getElementById('res-image-container');
        const shelfPreview     = document.getElementById('shelf-preview');
        const previewShelf     = document.getElementById('preview-shelf');
        const previewBin       = document.getElementById('preview-bin');

        // ── Shelf preview updates live ────────────────────────────────────
        function updateShelfPreview() {
            const s = shelfSelect.value;
            const b = rowInput.value.trim();
            if (s && b) {
                previewShelf.textContent = s;
                previewBin.textContent   = b;
                shelfPreview.classList.remove('hidden');
            } else {
                shelfPreview.classList.add('hidden');
            }
        }
        shelfSelect.addEventListener('change', updateShelfPreview);
        rowInput.addEventListener('input',  updateShelfPreview);

        // ── Camera scanner ────────────────────────────────────────────────
        const qrConfig = { fps: 15, qrbox: { width: 240, height: 240 }, aspectRatio: 1.0 };

        html5QrCode = new Html5Qrcode('reader');
        html5QrCode.start({ facingMode: 'environment' }, qrConfig, onScanSuccess)
            .catch(() => {
                document.getElementById('reader').innerHTML = `
                    <div class="p-12 text-center text-slate-400">
                        <i class="fas fa-video-slash text-5xl mb-4 block"></i>
                        <p class="text-sm font-bold text-slate-600">Camera permission required</p>
                        <p class="text-xs mt-1">Allow camera access or use the manual lookup above.</p>
                        <button onclick="location.reload()"
                                class="mt-4 px-6 py-2 bg-cmu-blue text-white rounded-lg text-xs font-bold uppercase">
                            Retry
                        </button>
                    </div>`;
            });

        function onScanSuccess(decodedText) {
            html5QrCode.pause(true);
            scanFeedback.classList.remove('hidden');
            scanFeedback.classList.add('flex');
            fetchItemDetails(decodedText);
        }

        // ── Manual lookup ─────────────────────────────────────────────────
        window.lookupManual = function () {
            const val = manualInput.value.trim().toUpperCase();
            if (!val) { showToast('Please enter a tracking ID.', 'warning'); return; }
            fetchItemDetails(val);
        };

        manualInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') window.lookupManual();
        });

        // ── Pre-fill auto-lookup on page load ─────────────────────────────
        if (PREFILL) {
            // Short delay so the camera has time to initialise first
            setTimeout(() => fetchItemDetails(PREFILL), 800);
        }

        // ── Fetch item from get_item_details.php ──────────────────────────
        async function fetchItemDetails(trackingId) {
            // Normalise: accept "42", "FND-42", "FND-00042" etc.
            const normalised = normaliseTrackingId(trackingId);

            try {
                const res  = await fetch(`../core/get_item_details.php?tracking_id=${encodeURIComponent(normalised)}`);
                const data = await res.json();

                if (data.success && data.item) {
                    populatePane(data.item, normalised);
                    unlockPane();
                } else {
                    showToast(data.message || 'Item not found. Check the tracking ID and try again.', 'error');
                    resumeScanner();
                }
            } catch (err) {
                console.warn('API unavailable — falling back to simulation.', err);
                simulateData(normalised);
                unlockPane();
            } finally {
                hideScanFeedback();
            }
        }

        // ── Populate result fields ────────────────────────────────────────
        // Field names match exactly what get_item_details.php returns.
        function populatePane(item, trackingId) {
            currentTrackingId = item.tracking_id || trackingId;

            resTrackingId.textContent  = currentTrackingId;
            resItemName.textContent    = item.title        || 'Unknown Item';
            resFinderName.textContent  = item.finder_name  || '—';
            resFinderDept.textContent  = item.finder_dept  || '—';
            resFoundLoc.textContent    = item.found_location || '—';
            resCategory.textContent    = item.category_name  || item.category || '—';
            resDescription.textContent = item.private_description || 'No description provided.';

            // Date
            if (item.date_reported) {
                resDate.textContent = new Date(item.date_reported).toLocaleDateString('en-PH', {
                    year: 'numeric', month: 'short', day: 'numeric'
                });
            } else {
                resDate.textContent = '—';
            }

            // Image
            if (item.image_path) {
                resImageContainer.innerHTML =
                    `<img src="${item.image_path}" class="w-full h-full object-cover" alt="Item photo"
                          onerror="this.parentElement.innerHTML='<i class=\\'fas fa-image text-4xl text-slate-200\\'></i>'">`;
            } else {
                resImageContainer.innerHTML = '<i class="fas fa-image text-4xl text-slate-200"></i>';
            }

            // Sync manual input field
            manualInput.value = currentTrackingId;
        }

        function unlockPane() {
            processingPane.classList.add('processing-active');
        }

        function hideScanFeedback() {
            setTimeout(() => {
                scanFeedback.classList.add('hidden');
                scanFeedback.classList.remove('flex');
            }, 600);
        }

        function resumeScanner() {
            hideScanFeedback();
            try { html5QrCode.resume(); } catch (_) {}
        }

        // ── Confirm button ────────────────────────────────────────────────
        confirmBtn.addEventListener('click', async () => {
            const shelf = shelfSelect.value;
            const bin   = rowInput.value.trim();
            const tid   = currentTrackingId || resTrackingId.textContent;

            if (!tid || tid === 'Waiting for Scan') {
                showToast('No item loaded. Scan a QR code or use manual lookup first.', 'warning');
                return;
            }
            if (!shelf) {
                showToast('Please select a shelf location.', 'warning');
                shelfSelect.focus();
                return;
            }
            if (!bin) {
                showToast('Please enter a bin / row number.', 'warning');
                rowInput.focus();
                return;
            }

            // Disable button to prevent double-submit
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Updating...';

            try {
                const res = await fetch('../core/update_inventory_status.php', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({
                        tracking_id:    tid,
                        shelf_location: shelf,
                        bin_number:     bin,
                        status:         'surrendered'
                    })
                });

                const result = await res.json();

                if (result.success) {
                    showToast(result.message || 'Turnover confirmed! Inventory updated.', 'success');
                    // Redirect to inventory after short delay so the toast is visible
                    setTimeout(() => {
                        window.location.href = `inventory.php?status=custody&highlight=${encodeURIComponent(tid)}`;
                    }, 1800);
                } else {
                    showToast(result.message || 'Failed to update inventory.', 'error');
                    resetConfirmButton();
                }
            } catch (e) {
                showToast('Network error — check your connection and try again.', 'error');
                resetConfirmButton();
            }
        });

        function resetConfirmButton() {
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="fas fa-check-double mr-2"></i> Confirm & Update Inventory';
        }

        // ── Reset ─────────────────────────────────────────────────────────
        window.resetScannerUI = function () {
            currentTrackingId = '';
            processingPane.classList.remove('processing-active');
            shelfSelect.value   = '';
            rowInput.value      = '';
            manualInput.value   = '';
            shelfPreview.classList.add('hidden');
            resTrackingId.textContent  = 'Waiting for Scan';
            resItemName.textContent    = 'Item Verification';
            resFinderName.textContent  = '—';
            resFinderDept.textContent  = '—';
            resFoundLoc.textContent    = '—';
            resCategory.textContent    = '—';
            resDate.textContent        = '—';
            resDescription.textContent = 'No description available.';
            resImageContainer.innerHTML = '<i class="fas fa-image text-4xl text-slate-200"></i>';
            resetConfirmButton();
            try { html5QrCode.resume(); } catch (_) {}
        };

        // ── Helpers ───────────────────────────────────────────────────────
        function normaliseTrackingId(raw) {
            raw = raw.trim().toUpperCase();
            // Plain integer → pad to FND-XXXXX
            if (/^\d+$/.test(raw)) return `FND-${raw.padStart(5, '0')}`;
            // Already FND-X or FND-XXXXX
            if (/^FND-\d+$/.test(raw)) {
                const num = raw.replace('FND-', '');
                return `FND-${num.padStart(5, '0')}`;
            }
            return raw; // pass through as-is
        }

        function simulateData(trackingId) {
            populatePane({
                tracking_id:         trackingId,
                title:               'Demo Item (Simulation Mode)',
                finder_name:         'Test Finder',
                finder_dept:         'CCS — BSIT',
                found_location:      'Main Lobby',
                category_name:       'Personal',
                private_description: 'API endpoint not yet connected. This is simulated data for UI testing.',
                image_path:          null,
                date_reported:       new Date().toISOString(),
            }, trackingId);
        }

        // ── Toast ─────────────────────────────────────────────────────────
        function showToast(msg, type = 'success') {
            const colors = {
                success: 'bg-green-600',
                error:   'bg-red-600',
                warning: 'bg-amber-500',
            };
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = [
                'px-6 py-3 rounded-2xl font-bold shadow-2xl text-white text-sm',
                'transition-all duration-300 translate-y-4 opacity-0 pointer-events-auto',
                colors[type] || 'bg-slate-700',
            ].join(' ');
            toast.textContent = msg;
            container.appendChild(toast);

            requestAnimationFrame(() => {
                toast.classList.remove('translate-y-4', 'opacity-0');
            });

            setTimeout(() => {
                toast.classList.add('opacity-0', 'translate-y-4');
                setTimeout(() => toast.remove(), 400);
            }, type === 'success' ? 2000 : 4000);
        }

    })();
    </script>
</body>
</html>