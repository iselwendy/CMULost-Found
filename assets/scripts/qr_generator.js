function switchTab(tab) {
    // Update Tab Styling
    const tabs = ['my-reports', 'potential-matches'];
    tabs.forEach(t => {
        const el = document.getElementById(`tab-${t}`);
        const content = document.getElementById(`content-${t}`);
        if (t === tab) {
            el.classList.add('tab-active');
            el.classList.remove('text-slate-400');
            content.classList.remove('hidden');
        } else {
            el.classList.remove('tab-active');
            el.classList.add('text-slate-400');
            content.classList.add('hidden');
        }
    });
}

/**
 * Dynamic QR Modal Logic
 * @param {string} trackingId - The unique ID for the turnover transaction
 */
function openQRModal(trackingId) {
    const qrImg = document.getElementById('qrImage');
    const qrText = document.getElementById('qrTrackingId');

    // Generate QR code URL using the tracking ID
    // Using goqr.me API for reliable generation
    const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(trackingId)}`;

    qrImg.src = qrUrl;
    qrText.innerText = trackingId;

    document.getElementById('qrModal').classList.remove('hidden');
}

function closeQRModal() {
    document.getElementById('qrModal').classList.add('hidden');
}

// Close modal when clicking outside
window.onclick = function (event) {
    const modal = document.getElementById('qrModal');
    if (event.target == modal) {
        closeQRModal();
    }
}