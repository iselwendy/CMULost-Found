function openQRModal(trackingId) {
    const modal = document.getElementById('qrModal');
    const qrImg = document.getElementById('qrImage');
    const qrText = document.getElementById('qrTrackingId');

    qrImg.src = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(trackingId)}`;
    qrText.innerText = trackingId;

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';
}

function closeQRModal() {
    document.getElementById('qrModal').classList.add('hidden');
    document.getElementById('qrModal').classList.remove('flex');
    document.body.style.overflow = '';
}

// ─────────────────────────────────────────────────────────────
// TIMELINE HELPERS
// ─────────────────────────────────────────────────────────────

/**
 * Render a timeline where each step has an explicit ordered index.
 * A step is "done" if its index <= the current step's index.
 * A step is "current" if its index === the current step's index.
 */
function renderTimelineOrdered(steps, currentIndex) {
    return steps.map((step, i) => {
        const done = i <= currentIndex;
        const current = i === currentIndex;

        const iconCls = done
            ? (current ? 'bg-cmu-blue text-white' : 'bg-green-500 text-white')
            : 'bg-slate-200 text-slate-400';

        const icon = current
            ? 'fa-circle-dot'
            : (done ? 'fa-check' : 'fa-circle');

        return `
            <div class="timeline-step ${done ? 'done' : ''} flex items-start gap-4 pb-3">
                <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 ${iconCls} shadow-sm z-10">
                    <i class="fas ${icon} text-xs"></i>
                </div>
                <div class="pt-0.5">
                    <p class="text-sm font-bold ${done ? 'text-slate-800' : 'text-slate-400'}">${step.label}</p>
                    <p class="text-xs ${done ? 'text-slate-500' : 'text-slate-300'} mt-0.5">${step.detail}</p>
                </div>
            </div>
        `;
    }).join('');
}

// ── Found-item timeline ───────────────────────────────────────

function buildFoundTimeline(status, surrenderedBeforeMatch) {
    const pathA = surrenderedBeforeMatch !== false;

    if (pathA) {
        // Path A: report submitted → surrendered to OSA → match found → claimed
        const steps = [
            { label: 'Report Submitted', detail: 'You submitted the found item report. Bring the item to OSA.' },
            { label: 'Turned Over to OSA', detail: 'Physical item surrendered and received by the admin.' },
            { label: 'Confirmed Match Found', detail: 'A potential owner has been found by the matching engine.' },
            { label: 'Item Returned to Owner', detail: 'Item successfully verified and returned to its rightful owner.' },
        ];

        // Map DB status → which step index is currently active (0-based)
        const indexMap = {
            'in custody': 0,
            'matched_before_surrender': 1, // Add this line
            'matched': 1,                  // Keep this for compatibility
            'surrendered': 2,
            'claimed': 3,
            'returned': 3,
            'disposed': 3,
        };

        const currentIndex = indexMap[status] ?? 0;
        return renderTimelineOrdered(steps, currentIndex);

    } else {
        // Path B: report submitted → match identified first → surrendered → claimed
        const steps = [
            { label: 'Report Submitted', detail: 'You submitted the found item report. Bring the item to OSA.' },
            { label: 'Match Identified', detail: 'A potential owner was found before the item was turned over.' },
            { label: 'Turned Over to OSA', detail: 'Physical item surrendered and received by the admin.' },
            { label: 'Item Returned to Owner', detail: 'Item successfully verified and returned to its rightful owner.' },
        ];

        const indexMap = {
            'in custody': 0,
            'matched': 1,
            'surrendered': 2,
            'claimed': 3,
            'returned': 3,
            'disposed': 3,
        };

        const currentIndex = indexMap[status] ?? 0;
        return renderTimelineOrdered(steps, currentIndex);
    }
}

// ── Lost-item timeline ────────────────────────────────────────

const LOST_STEPS = [
    { label: 'Report Posted', detail: 'Your lost item report is active and visible in the gallery.' },
    { label: 'Match Found', detail: 'A found report matches your item. Visit OSA with valid ID to claim.' },
    { label: 'Item Recovered', detail: 'You have collected your item from OSA.' },
];

const LOST_INDEX_MAP = {
    'open': 0,
    'matched': 1,
    'resolved': 2,
    'closed': 2,
};

// ── Main entry point ──────────────────────────────────────────

function buildTimeline(data) {
    if (data.type === 'found') {
        return buildFoundTimeline(data.status, data.surrenderedBeforeMatch);
    }
    const currentIndex = LOST_INDEX_MAP[data.status] ?? 0;
    return renderTimelineOrdered(LOST_STEPS, currentIndex);
}

// ─────────────────────────────────────────────────────────────
// DETAIL MODAL
// ─────────────────────────────────────────────────────────────

function buildFooter(data) {
    const footer = document.getElementById('dm-footer');
    footer.innerHTML = '';

    const closeBtn = document.createElement('button');
    closeBtn.onclick = closeDetailModal;
    closeBtn.className = 'px-6 py-3 border border-slate-200 text-slate-600 rounded-xl font-bold text-sm hover:bg-slate-50 transition';
    closeBtn.innerHTML = 'Close';
    footer.appendChild(closeBtn);

    if (data.type === 'found' && (data.status === 'in custody' || data.status === 'matched_before_surrender')) {
        const qrBtn = document.createElement('button');
        qrBtn.onclick = () => { closeDetailModal(); setTimeout(() => openQRModal(data.tracking_id), 250); };
        qrBtn.className = 'flex-1 py-3 bg-cmu-blue text-white rounded-xl font-bold text-sm hover:bg-slate-800 transition flex items-center justify-center gap-2';
        qrBtn.innerHTML = '<i class="fas fa-qrcode"></i> Get Turnover QR Code';
        footer.appendChild(qrBtn);
    } else if (data.type === 'lost' && data.status === 'matched') {
        const matchBtn = document.createElement('button');
        matchBtn.onclick = () => { closeDetailModal(); setTimeout(() => switchTab('potential-matches'), 250); };
        matchBtn.className = 'flex-1 py-3 bg-indigo-600 text-white rounded-xl font-bold text-sm hover:bg-indigo-700 transition flex items-center justify-center gap-2';
        matchBtn.innerHTML = '<i class="fas fa-bolt"></i> View Potential Matches';
        footer.appendChild(matchBtn);
    }
}

function openDetailModal(data) {
    // Type badge
    const typeBadge = document.getElementById('dm-type-badge');
    typeBadge.textContent = data.type.toUpperCase();
    typeBadge.className = `text-[10px] font-black uppercase px-2 py-0.5 rounded-full ${data.type === 'found' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'
        }`;

    // Text fields
    document.getElementById('dm-tracking').textContent = data.tracking_id;
    document.getElementById('dm-title').textContent = data.title;
    document.getElementById('dm-category').textContent = data.category;
    document.getElementById('dm-location').textContent = data.location;
    document.getElementById('dm-date-label').textContent = data.date_label;
    document.getElementById('dm-date').textContent = data.date;
    document.getElementById('dm-created').textContent = data.created_at;
    document.getElementById('dm-description').textContent = data.description || 'No description provided.';
    document.getElementById('dm-step').textContent = data.step;

    // Status badge
    const statusBadge = document.getElementById('dm-status-badge');
    statusBadge.textContent = data.label.toUpperCase();
    statusBadge.className = `px-3 py-1 rounded-full text-[10px] font-bold border ${data.badge_cls}`;

    // Progress bar — reset to 0 first so the animation is visible
    const bar = document.getElementById('dm-progress-bar');
    bar.style.width = '0%';
    bar.className = `h-full rounded-full transition-all duration-700 ${data.color}`;
    setTimeout(() => { bar.style.width = data.pct; }, 50);

    // Image
    const imgEl = document.getElementById('dm-image');
    const imgCont = document.getElementById('dm-image-container');
    const noImg = document.getElementById('dm-no-image');
    if (data.image) {
        imgEl.src = data.image;
        imgCont.classList.remove('hidden');
        noImg.classList.add('hidden');
    } else {
        imgCont.classList.add('hidden');
        noImg.classList.remove('hidden');
    }

    // Timeline
    // document.getElementById('dm-timeline').innerHTML = buildTimeline(data);

    // Footer buttons
    buildFooter(data);

    // Show modal
    const modal = document.getElementById('detailModal');
    const card = document.getElementById('detailModalCard');
    modal.classList.remove('opacity-0', 'pointer-events-none');
    modal.classList.add('opacity-100');
    requestAnimationFrame(() => {
        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    });
    document.body.style.overflow = 'hidden';
}

function closeDetailModal() {
    const modal = document.getElementById('detailModal');
    const card = document.getElementById('detailModalCard');
    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');
    setTimeout(() => {
        modal.classList.add('opacity-0', 'pointer-events-none');
        modal.classList.remove('opacity-100');
        document.body.style.overflow = '';
    }, 250);
}

// Backdrop click closes modals
document.getElementById('detailModal').addEventListener('click', function (e) {
    if (e.target === this) closeDetailModal();
});
document.getElementById('qrModal').addEventListener('click', function (e) {
    if (e.target === this) closeQRModal();
});

// Escape key closes modals
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeDetailModal();
        closeQRModal();
    }
});