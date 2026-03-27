// Initialize Scanner
const html5QrCode = new Html5Qrcode("reader");
const qrConfig = { fps: 10, qrbox: { width: 250, height: 250 } };

const onScanSuccess = (decodedText, decodedResult) => {
    // Stop scanning after first success
    html5QrCode.stop().then(() => {
        console.log("Scan stopped.");
        processScannedData(decodedText);
    }).catch((err) => console.error(err));
};

function processScannedData(trackingId) {
    // UI Feedback
    document.getElementById('scan-feedback').classList.remove('hidden');

    // Simulating an AJAX fetch from your DB
    setTimeout(() => {
        document.getElementById('scan-feedback').classList.add('hidden');

        // Populate the UI with mock data (In production, use fetch API)
        document.getElementById('res-tracking-id').innerText = trackingId;
        document.getElementById('res-item-name').innerText = "Calculus 1 Textbook";
        document.getElementById('res-finder-name').innerText = "Juan Dela Cruz";
        document.getElementById('res-finder-dept').innerText = "College of Engineering";
        document.getElementById('res-found-loc').innerText = "Innovation Building, 3rd Floor";
        document.getElementById('res-image').innerHTML = `<img src="https://images.unsplash.com/photo-1544947950-fa07a98d237f?w=300" class="w-full h-full object-cover">`;

        // Enable the processing pane
        const pane = document.getElementById('processing-pane');
        pane.classList.remove('opacity-50', 'pointer-events-none');
        pane.classList.add('opacity-100');
    }, 1000);
}

// Handle Confirmation
document.getElementById('confirm-btn').addEventListener('click', function () {
    const shelf = document.getElementById('shelf-select').value;
    const row = document.getElementById('row-input').value;

    if (!shelf || !row) {
        alert("Please assign a shelf location before confirming.");
        return;
    }

    this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Updating...';

    // In production: Send POST request to update_status.php
    setTimeout(() => {
        alert("Inventory Updated! SMS notification sent to finder.");
        window.location.href = 'inventory.php';
    }, 1500);
});

// Start Scanner on load
html5QrCode.start({ facingMode: "environment" }, qrConfig, onScanSuccess);