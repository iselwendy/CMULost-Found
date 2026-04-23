// ── Weight sliders total ───────────────────────────────────────────────────
function updateWeights() {
    const keys = ['weight_category', 'weight_location', 'weight_keywords', 'weight_date'];
    let total = 0;
    keys.forEach(k => {
        const s = document.getElementById('slider-' + k);
        if (!s) return;
        const v = parseInt(s.value);
        total += v;
        document.getElementById('val-' + k).textContent = v;
        const bar = document.getElementById('bar-' + k);
        if (bar) bar.style.width = Math.min(100, v * 100 / 60) + '%';
    });
    const el = document.getElementById('wTotal');
    el.textContent = total;
    el.className = total === 100 ? 'text-green-600' : (total > 100 ? 'text-red-500' : 'text-amber-500');
}
updateWeights();


// ── Password visibility toggle ─────────────────────────────────────────────
function togglePwd(btn) {
    const input = btn.previousElementSibling || btn.closest('.relative').querySelector('input');
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

// ── Password strength ──────────────────────────────────────────────────────
function updatePwStrength(val) {
    const wrap = document.getElementById('pw-strength-wrap');
    if (!val) { wrap.classList.add('hidden'); return; }
    wrap.classList.remove('hidden');

    let s = 0;
    if (val.length >= 8) s++;
    if (/[A-Z]/.test(val)) s++;
    if (/[0-9]/.test(val)) s++;
    if (/[^A-Za-z0-9]/.test(val)) s++;

    const colors = ['bg-red-400', 'bg-orange-400', 'bg-yellow-400', 'bg-green-500'];
    const labels = ['Weak', 'Fair', 'Good', 'Strong'];
    const tColors = ['text-red-500', 'text-orange-500', 'text-yellow-500', 'text-green-600'];

    for (let i = 1; i <= 4; i++) {
        const bar = document.getElementById('ps' + i);
        bar.className = 'h-1 flex-1 rounded-full transition-all duration-300 ' + (i <= s ? colors[s - 1] : 'bg-slate-200');
    }
    const lbl = document.getElementById('pw-strength-label');
    lbl.textContent = labels[s - 1] || '';
    lbl.className = 'text-[10px] font-black uppercase ' + (tColors[s - 1] || '');
}

// ── Side nav active state on scroll ───────────────────────────────────────
const sections = ['gallery', 'matching', 'email', 'password', 'admins', 'locations', 'aging', 'log'];

function setActiveNav(clickedEl) {
    // Visual-only update on click; scroll listener handles the rest
}

function updateNavOnScroll() {
    let current = sections[0];
    sections.forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        const rect = el.getBoundingClientRect();
        if (rect.top <= 120) current = id;
    });
    sections.forEach(id => {
        const dot = document.getElementById('dot-' + id);
        if (dot) dot.classList.toggle('active', id === current);
    });
}

window.addEventListener('scroll', updateNavOnScroll, { passive: true });
updateNavOnScroll();

// ── Auto-dismiss flash banner after 4s ────────────────────────────────────
const flash = document.getElementById('flashBanner');
if (flash) setTimeout(() => { flash.style.opacity = '0'; flash.style.transition = 'opacity .4s'; setTimeout(() => flash.remove(), 400); }, 4000);


// ── Campus Locations: filter + paginate ───────────────────────────────────
(function () {
    const PER_PAGE = 10;
    let currentPage = 1;

    const allRows = Array.from(document.querySelectorAll('.loc-row'));

    function getFiltered() {
        const q = (document.getElementById('locSearch')?.value || '').toLowerCase().trim();
        const b = document.getElementById('locBuildingFilter')?.value || '';
        return allRows.filter(row => {
            const matchName = !q || row.dataset.name.includes(q);
            const matchBuilding = !b || row.dataset.building === b;
            return matchName && matchBuilding;
        });
    }

    function render() {
        const filtered = getFiltered();
        const total = filtered.length;
        const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));
        if (currentPage > totalPages) currentPage = totalPages;

        const start = (currentPage - 1) * PER_PAGE;
        const end = start + PER_PAGE;

        allRows.forEach(r => r.style.display = 'none');
        filtered.slice(start, end).forEach(r => r.style.display = '');

        const badge = document.getElementById('locCountBadge');
        if (badge) badge.textContent = `${total} location${total !== 1 ? 's' : ''}`;

        const info = document.getElementById('locPageInfo');
        if (info) info.textContent = total === 0
            ? 'No locations match your filters'
            : `Showing ${start + 1}–${Math.min(end, total)} of ${total}`;

        const prevBtn = document.getElementById('locPrevBtn');
        const nextBtn = document.getElementById('locNextBtn');
        if (prevBtn) prevBtn.disabled = currentPage <= 1;
        if (nextBtn) nextBtn.disabled = currentPage >= totalPages;

        const pageBtns = document.getElementById('locPageBtns');
        if (pageBtns) {
            pageBtns.innerHTML = '';
            const startP = Math.max(1, currentPage - 2);
            const endP = Math.min(totalPages, startP + 4);
            for (let p = startP; p <= endP; p++) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = `w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold transition ${p === currentPage
                        ? 'bg-cmu-blue text-white shadow-sm'
                        : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                    }`;
                btn.textContent = p;
                btn.onclick = () => { currentPage = p; render(); };
                pageBtns.appendChild(btn);
            }
        }

        const bar = document.getElementById('locPagination');
        if (bar) bar.style.display = totalPages <= 1 && total <= PER_PAGE ? 'none' : '';
    }

    window.filterLocations = function () {
        currentPage = 1;
        render();
    };

    window.locChangePage = function (delta) {
        const filtered = getFiltered();
        const totalPages = Math.max(1, Math.ceil(filtered.length / PER_PAGE));
        currentPage = Math.min(Math.max(1, currentPage + delta), totalPages);
        render();
    };

    render();
})();


// ── Action Log: paginate ───────────────────────────────────────────────────
(function () {
    const LOG_PER_PAGE = 5;
    let logPage = 1;

    const allLogRows = Array.from(document.querySelectorAll('.log-row'));
    const total = allLogRows.length;

    function renderLog() {
        const totalPages = Math.max(1, Math.ceil(total / LOG_PER_PAGE));
        if (logPage > totalPages) logPage = totalPages;

        const start = (logPage - 1) * LOG_PER_PAGE;
        const end = start + LOG_PER_PAGE;

        allLogRows.forEach((row, i) => {
            row.style.display = (i >= start && i < end) ? '' : 'none';
        });

        const info = document.getElementById('logPageInfo');
        if (info) info.textContent = total === 0
            ? 'No actions recorded'
            : `Showing ${start + 1}–${Math.min(end, total)} of ${total} entries`;

        const prevBtn = document.getElementById('logPrevBtn');
        const nextBtn = document.getElementById('logNextBtn');
        if (prevBtn) prevBtn.disabled = logPage <= 1;
        if (nextBtn) nextBtn.disabled = logPage >= totalPages;

        const pageBtns = document.getElementById('logPageBtns');
        if (pageBtns) {
            pageBtns.innerHTML = '';
            const startP = Math.max(1, logPage - 2);
            const endP = Math.min(totalPages, startP + 4);
            for (let p = startP; p <= endP; p++) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = `w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold transition ${p === logPage
                        ? 'bg-cmu-blue text-white shadow-sm'
                        : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                    }`;
                btn.textContent = p;
                btn.onclick = () => { logPage = p; renderLog(); };
                pageBtns.appendChild(btn);
            }
        }

        const bar = document.getElementById('logPagination');
        if (bar) bar.style.display = totalPages <= 1 ? 'none' : '';
    }

    window.logChangePage = function (delta) {
        const totalPages = Math.max(1, Math.ceil(total / LOG_PER_PAGE));
        logPage = Math.min(Math.max(1, logPage + delta), totalPages);
        renderLog();
    };

    renderLog();
})();