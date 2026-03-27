/**
         * QR Scanner Logic
         * Designed to work either inline or in assets/js/qr_scanner.js
         */
const initializeScanner = () => {
    // Check if library is loaded
    if (typeof Html5Qrcode === "undefined") {
        console.error("Html5Qrcode library not loaded yet.");
        return;
    }

    const readerElem = document.getElementById("reader");
    if (!readerElem) return;

    const html5QrCode = new Html5Qrcode("reader");
    const processingPane = document.getElementById('processing-pane');
    const scanFeedback = document.getElementById('scan-feedback');
    const confirmBtn = document.getElementById('confirm-btn');

    // Result Elements
    const resTrackingId = document.getElementById('res-tracking-id');
    const resItemName = document.getElementById('res-item-name');
    const resFinderName = document.getElementById('res-finder-name');
    const resFinderDept = document.getElementById('res-finder-dept');
    const resFoundLoc = document.getElementById('res-found-loc');
    const resDescription = document.getElementById('res-description');
    const resImageContainer = document.getElementById('res-image-container');

    const beep = new Audio('https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3');
    const qrConfig = { fps: 15, qrbox: { width: 250, height: 250 }, aspectRatio: 1.0 };

    const onScanSuccess = (decodedText) => {
        try { beep.play(); } catch (e) { }
        scanFeedback.classList.remove('hidden');
        scanFeedback.classList.add('flex');
        html5QrCode.pause(true);
        fetchItemDetails(decodedText);
    };

    async function fetchItemDetails(trackingId) {
        try {
            // Use relative pathing that works from the admin/ directory
            const response = await fetch(`../core/api/get_item_details.php?tracking_id=${encodeURIComponent(trackingId)}`);
            const data = await response.json();

            if (data.success && data.item) {
                populateUI(data.item);
                unlockUI();
            } else {
                showToast(data.message || "Record not found.", "error");
                resetScannerUI();
            }
        } catch (error) {
            console.warn("API offline, using mock data for testing.");
            simulateData(trackingId);
        } finally {
            setTimeout(() => {
                scanFeedback.classList.add('hidden');
                scanFeedback.classList.remove('flex');
            }, 800);
        }
    }

    function unlockUI() {
        processingPane.classList.add('processing-active');
    }

    window.resetScannerUI = function () {
        processingPane.classList.remove('processing-active');
        document.getElementById('shelf-select').value = "";
        document.getElementById('row-input').value = "";
        html5QrCode.resume();
    };

    function populateUI(item) {
        resTrackingId.innerText = item.tracking_id || "TRK-XXXX";
        resItemName.innerText = item.item_name || "Unknown";
        resFinderName.innerText = item.finder_name || "Anonymous";
        resFinderDept.innerText = item.finder_dept || "N/A";
        resFoundLoc.innerText = item.location || "Unknown";
        resDescription.innerText = item.description || "No description provided.";

        if (item.image_url) {
            resImageContainer.innerHTML = `<img src="${item.image_url}" class="w-full h-full object-cover">`;
        } else {
            resImageContainer.innerHTML = `<i class="fas fa-image text-4xl text-slate-200"></i>`;
        }
    }

    confirmBtn.addEventListener('click', async () => {
        const shelf = document.getElementById('shelf-select').value;
        const bin = document.getElementById('row-input').value;
        const tid = resTrackingId.innerText;

        if (!shelf || !bin) {
            showToast("Please assign storage location.", "warning");
            return;
        }

        confirmBtn.disabled = true;
        confirmBtn.innerHTML = `<i class="fas fa-spinner fa-spin mr-2"></i> Updating...`;

        try {
            const response = await fetch('../core/api/update_inventory_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    tracking_id: tid,
                    shelf_location: shelf,
                    bin_number: bin,
                    status: 'in custody'
                })
            });

            const result = await response.json();
            if (result.success) {
                showToast("Turnover successful!", "success");
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(result.message || "Failed to update.", "error");
                confirmBtn.disabled = false;
                confirmBtn.innerText = "Confirm & Update Inventory";
            }
        } catch (e) {
            showToast("Network error.", "error");
            confirmBtn.disabled = false;
            confirmBtn.innerText = "Confirm & Update Inventory";
        }
    });

    function showToast(msg, type) {
        const toast = document.createElement('div');
        const color = type === 'success' ? 'bg-green-600' : (type === 'error' ? 'bg-red-600' : 'bg-amber-500');
        toast.className = `fixed bottom-10 left-1/2 -translate-x-1/2 ${color} text-white px-8 py-4 rounded-2xl font-bold shadow-2xl z-[100] transition-all duration-300 transform translate-y-10 opacity-0`;
        toast.innerText = msg;
        document.body.appendChild(toast);
        setTimeout(() => { toast.classList.remove('translate-y-10', 'opacity-0'); }, 100);
        setTimeout(() => {
            toast.classList.add('opacity-0');
            setTimeout(() => toast.remove(), 500);
        }, 3000);
    }

    function simulateData(tid) {
        populateUI({
            tracking_id: tid,
            item_name: "Demo Wallet (Simulation)",
            finder_name: "Test User",
            finder_dept: "CCS - BSIT",
            location: "Main Lobby",
            description: "Simulation mode active.",
            image_url: "https://ui-avatars.com/api/?name=Item&background=random"
        });
        unlockUI();
    }

    html5QrCode.start({ facingMode: "environment" }, qrConfig, onScanSuccess)
        .catch(err => {
            readerElem.innerHTML = `
                        <div class="p-12 text-center text-slate-400">
                            <i class="fas fa-video-slash text-5xl mb-4"></i>
                            <p class="text-sm font-bold text-slate-600">Camera Permission Required</p>
                            <button onclick="location.reload()" class="mt-4 px-6 py-2 bg-[#003366] text-white rounded-lg text-xs font-bold uppercase">Retry Access</button>
                        </div>
                    `;
        });
};

// Ensure everything is ready before running
if (document.readyState === "complete" || document.readyState === "interactive") {
    setTimeout(initializeScanner, 1);
} else {
    document.addEventListener("DOMContentLoaded", initializeScanner);
}