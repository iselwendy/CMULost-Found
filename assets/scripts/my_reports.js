// TAB SWITCHING
function switchTab(tabId) {
    ['my-reports', 'potential-matches'].forEach(t => {
        const content = document.getElementById(`content-${t}`);
        const btn = document.getElementById(`tab-${t}`);
        const isActive = t === tabId;

        content.classList.toggle('hidden', !isActive);
        btn.classList.toggle('active', isActive);

        // border and color
        if (isActive) {
            btn.classList.remove('text-slate-400', 'border-transparent');
        } else {
            btn.classList.add('text-slate-400', 'border-transparent');
            btn.classList.remove('text-cmu-blue');
        }
    });
}

function toggleQRDropdown(e) {
    e.stopPropagation(); // prevent the document click listener from closing it immediately
    const dropdown = document.getElementById('qr-dropdown');
    const chevron = document.getElementById('qr-dropdown-chevron');
    const isHidden = dropdown.classList.contains('hidden');

    dropdown.classList.toggle('hidden', !isHidden);
    chevron.style.transform = isHidden ? 'rotate(180deg)' : '';
}

function closeQRDropdown() {
    const dropdown = document.getElementById('qr-dropdown');
    const chevron = document.getElementById('qr-dropdown-chevron');
    if (dropdown) {
        dropdown.classList.add('hidden');
        chevron.style.transform = '';
    }
}

// Close dropdown when clicking anywhere outside the card
document.addEventListener('click', function (e) {
    const card = document.getElementById('qr-dropdown')?.closest('.relative');
    if (card && !card.contains(e.target)) {
        closeQRDropdown();
    }
});