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