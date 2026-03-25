// ── Tab switcher ─────────────────────────────────────
function switchTab(tab) {
    const tabs = ['found', 'lost'];
    tabs.forEach(t => {
        const btn = document.getElementById('tab-' + t);
        const panel = document.getElementById('panel-' + t);
        if (t === tab) {
            btn.classList.add('active');
            btn.classList.remove('text-gray-400');
            btn.classList.add('text-gray-700');
            panel.classList.remove('hidden');
        } else {
            btn.classList.remove('active');
            btn.classList.remove('text-gray-700');
            btn.classList.add('text-gray-400');
            panel.classList.add('hidden');
        }
    });
}

// ── Profile picture preview & auto-submit ────────────
function previewAndSubmit(input) {
    if (!input.files || !input.files[0]) return;

    const file = input.files[0];
    const maxMB = 2;

    if (file.size > maxMB * 1024 * 1024) {
        showToast('File is too large. Maximum size is 2MB.', 'error');
        input.value = '';
        return;
    }

    // Show preview immediately
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('profilePreview').src = e.target.result;
    };
    reader.readAsDataURL(file);

    // Submit the form
    document.getElementById('avatarForm').submit();
}

// ── Delete report ─────────────────────────────────────
function confirmDelete(type, id) {
    const url = type === 'found'
        ? `../dashboard/delete_report.php?type=found&id=${id}`
        : `../dashboard/delete_report.php?type=lost&id=${id}`;
    document.getElementById('deleteConfirmBtn').href = url;
    document.getElementById('deleteModal').classList.remove('hidden');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
}

// Close modal on backdrop click
document.getElementById('deleteModal').addEventListener('click', function (e) {
    if (e.target === this) closeDeleteModal();
});

// ── Toast helper ──────────────────────────────────────
function showToast(msg, type = 'success') {
    const existing = document.getElementById('toastNotif');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.id = 'toastNotif';
    const bgClass = type === 'error' ? 'bg-red-600' : 'bg-green-600';
    toast.className = `fixed top-20 left-1/2 -translate-x-1/2 z-50 flex items-center gap-3 ${bgClass} text-white px-6 py-3 rounded-2xl shadow-xl text-sm font-bold transition-opacity duration-300`;
    toast.innerHTML = `<i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'check-circle'}"></i> ${msg}`;
    document.body.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 3500);
}

// ── Auto-dismiss flash message ────────────────────────
const flashMsg = document.getElementById('flashMsg');
if (flashMsg) {
    setTimeout(() => {
        flashMsg.style.transition = 'opacity 0.4s';
        flashMsg.style.opacity = '0';
        setTimeout(() => flashMsg.remove(), 400);
    }, 3500);
}

// ── Activate tab from URL hash ────────────────────────
const hash = window.location.hash;
if (hash === '#lost') switchTab('lost');