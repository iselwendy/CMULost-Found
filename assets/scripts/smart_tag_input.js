/**
 * CMU Lost & Found — Smart Keyword Tag Input
 * Option 4: Fuzzy Autocomplete + Synonym Normalization
 *
 * Drop this file in: assets/scripts/smart_tag_input.js
 *
 * Usage in report_lost.php / report_found.php:
 *
 *   const tagInput = SmartTagInput.init({
 *       inputId:     'keywordInput',
 *       containerId: 'tagContainer',
 *       errorId:     'tagError',
 *       accentColor: 'indigo',   // 'indigo' for lost, 'green' for found
 *   });
 *
 *   // On form submit, get compiled string:
 *   document.getElementById('compiledMarks').value = tagInput.getCompiledKeywords();
 *
 *   // Add a suggested tag programmatically (from quick-add buttons):
 *   tagInput.addSuggested('samsung');
 */

const SmartTagInput = (() => {

    // ─────────────────────────────────────────────────────────
    // MASTER VOCABULARY
    // These are the canonical terms the matching engine indexes.
    // ─────────────────────────────────────────────────────────
    const VOCABULARY = {
        appearance: [
            'cracked screen', 'scratched', 'dented', 'worn/faded', 'broken strap',
            'missing cap', 'torn cover', 'dog-eared', 'loose pages', 'bent corner',
            'burned mark', 'water damaged', 'faded color', 'peeling label',
        ],
        personalization: [
            'name written inside', 'name engraved', 'initials marked', 'monogram',
            'sticker on back', 'custom sticker', 'anime sticker', 'flag sticker',
            'doodles/drawings', 'washi tape', 'handwritten label', 'stamp/seal',
        ],
        contents: [
            'cards inside', 'cash inside', 'coins inside', 'no cash', 'receipt inside',
            'photo inside', 'id inside', 'keys attached', 'keychain attached',
            'lanyard attached', 'charger included', 'case/cover', 'screen protector',
        ],
        condition: [
            'brand new', 'slightly used', 'heavily used', 'dead battery',
            'no battery', 'locked', 'unlocked', 'screen on', 'screen off',
        ],
        brands: [
            'samsung', 'apple', 'iphone', 'xiaomi', 'oppo', 'realme', 'vivo',
            'huawei', 'asus', 'acer', 'lenovo', 'hp', 'dell', 'jbl', 'sony',
            'anker', 'baseus', 'airpods', 'aquaflask', 'hydroflask', 'thermos',
            'jansport', 'samsonite', 'nike', 'adidas',
        ],
        academic: [
            'cmu id', 'school id', 'student id', 'library card', 'philsys id',
            "driver's license", 'umid', 'sss id', 'pagibig id', 'birth certificate',
            'calculus book', 'physics book', 'chemistry book', 'engineering book',
            'accounting book', 'filipino book', 'history book', 'math notebook',
        ],
        documents: [
            'laminated', 'punched hole', 'clipped together', 'stapled',
            'folded', 'expired', 'government issued',
        ],
        form: [
            'bi-fold', 'tri-fold', 'zipper closure', 'snap button',
            'velcro', 'magnetic clasp', 'drawstring', 'backpack', 'tote bag',
            'sling bag', 'pouch', 'hardcover', 'softcover',
        ],
    };

    const ALL_CANONICAL = Object.values(VOCABULARY).flat();

    // ─────────────────────────────────────────────────────────
    // SYNONYM MAP
    // Keys = common user input (lowercase)
    // Values = canonical term to store
    // ─────────────────────────────────────────────────────────
    const SYNONYMS = {
        // Screen damage
        'broken screen': 'cracked screen',
        'busted screen': 'cracked screen',
        'shattered screen': 'cracked screen',
        'cracked': 'cracked screen',
        'smashed screen': 'cracked screen',
        'broken glass': 'cracked screen',
        // Damage
        'scratch': 'scratched',
        'scratches': 'scratched',
        'scrape': 'scratched',
        'dent': 'dented',
        'dents': 'dented',
        'bent': 'dented',
        // Name on item
        'name on it': 'name written inside',
        'has name': 'name written inside',
        'name written': 'name written inside',
        'written name': 'name written inside',
        'labeled': 'name written inside',
        'name inside': 'name written inside',
        'with name': 'name written inside',
        // Stickers
        'sticker': 'sticker on back',
        'stickers': 'sticker on back',
        'has sticker': 'sticker on back',
        'with sticker': 'sticker on back',
        // Wallet forms
        'wallet': 'bi-fold',
        'regular wallet': 'bi-fold',
        'long wallet': 'tri-fold',
        // Bag types
        'bag': 'backpack',
        'school bag': 'backpack',
        'sling': 'sling bag',
        'tote': 'tote bag',
        // Contents
        'with cards': 'cards inside',
        'has cards': 'cards inside',
        'cards': 'cards inside',
        'with money': 'cash inside',
        'has money': 'cash inside',
        'money inside': 'cash inside',
        'peso': 'cash inside',
        'bills': 'cash inside',
        'with id': 'id inside',
        'id card': 'id inside',
        // Brands
        'samsung phone': 'samsung',
        'galaxy': 'samsung',
        'redmi': 'xiaomi',
        'mi phone': 'xiaomi',
        'airpod': 'airpods',
        'air pod': 'airpods',
        'air pods': 'airpods',
        // IDs
        'school id': 'cmu id',
        'student id': 'cmu id',
        'cmu': 'cmu id',
        'government id': 'government issued',
        'govt id': 'government issued',
        'phil id': 'philsys id',
        'national id': 'philsys id',
        // Condition
        'new': 'brand new',
        'used': 'slightly used',
        'old': 'heavily used',
        'dead': 'dead battery',
        'no power': 'dead battery',
        "won't turn on": 'dead battery',
        // Misc accessories
        'keychain': 'keychain attached',
        'key chain': 'keychain attached',
        'with keys': 'keys attached',
        'case': 'case/cover',
        'phone case': 'case/cover',
        'cover': 'case/cover',
        'charger': 'charger included',
        'with charger': 'charger included',
        'lanyard': 'lanyard attached',
        'with lanyard': 'lanyard attached',
    };

    // ─────────────────────────────────────────────────────────
    // FUZZY SCORE  (0 – 1)
    // ─────────────────────────────────────────────────────────
    function fuzzyScore(query, candidate) {
        const q = query.toLowerCase().trim();
        const c = candidate.toLowerCase();

        if (c === q) return 1.0;
        if (c.startsWith(q)) return 0.9;
        if (c.includes(q)) return 0.75;

        const words = c.split(/\s+/);
        if (words.some(w => w.startsWith(q))) return 0.65;

        // Bigram overlap
        let hits = 0;
        for (let i = 0; i < q.length - 1; i++) {
            if (c.includes(q[i] + q[i + 1])) hits++;
        }
        const ratio = q.length > 1 ? hits / (q.length - 1) : 0;
        return ratio > 0.45 ? ratio * 0.55 : 0;
    }

    // ─────────────────────────────────────────────────────────
    // NORMALIZE  →  { canonical, wasNormalized, isCustom }
    // ─────────────────────────────────────────────────────────
    function normalize(raw) {
        const lower = raw.toLowerCase().trim();

        if (SYNONYMS[lower])
            return { canonical: SYNONYMS[lower], wasNormalized: true };

        if (ALL_CANONICAL.includes(lower))
            return { canonical: lower, wasNormalized: false };

        // Partial synonym prefix match (≥ 4 chars)
        if (lower.length >= 4) {
            for (const [syn, canon] of Object.entries(SYNONYMS)) {
                if (syn.startsWith(lower)) {
                    return { canonical: canon, wasNormalized: true };
                }
            }
        }

        return { canonical: lower, wasNormalized: false, isCustom: true };
    }

    // ─────────────────────────────────────────────────────────
    // GET SUGGESTIONS  (top N fuzzy matches from vocabulary)
    // ─────────────────────────────────────────────────────────
    function getSuggestions(query, limit = 7) {
        if (!query || query.length < 2) return [];
        return ALL_CANONICAL
            .map(term => ({ term, score: fuzzyScore(query, term) }))
            .filter(r => r.score > 0.3)
            .sort((a, b) => b.score - a.score)
            .slice(0, limit)
            .map(r => r.term);
    }

    // ─────────────────────────────────────────────────────────
    // ESCAPE HTML helper
    // ─────────────────────────────────────────────────────────
    function esc(str) {
        return String(str).replace(/[&<>"']/g,
            m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    }

    // ─────────────────────────────────────────────────────────
    // INIT — wire up a single tag input instance
    // ─────────────────────────────────────────────────────────
    function init({ inputId, containerId, errorId, accentColor = 'indigo' }) {

        const input = document.getElementById(inputId);
        const container = document.getElementById(containerId);
        const errorEl = errorId ? document.getElementById(errorId) : null;

        if (!input || !container) {
            console.warn('SmartTagInput: element not found', { inputId, containerId });
            return null;
        }

        // ── Accent theme ──────────────────────────────────────
        const A = accentColor === 'green' ? {
            ring: ['focus-within:ring-2', 'focus-within:ring-green-400', 'focus-within:border-green-400'],
            dropBorder: 'border-green-100',
            highlight: 'bg-green-50 text-green-800',
            pillStd: 'bg-green-50 border-green-200 text-green-900',
            pillCustom: 'bg-slate-100 border-slate-300 text-slate-600',
            pillBtnBase: 'text-green-300 hover:text-red-500',
            normBadge: 'bg-green-100 text-green-700',
            toastIcon: 'text-green-400',
        } : {
            ring: ['focus-within:ring-2', 'focus-within:ring-indigo-400', 'focus-within:border-indigo-400'],
            dropBorder: 'border-indigo-100',
            highlight: 'bg-indigo-50 text-indigo-800',
            pillStd: 'bg-indigo-50 border-indigo-200 text-indigo-900',
            pillCustom: 'bg-slate-100 border-slate-300 text-slate-600',
            pillBtnBase: 'text-indigo-300 hover:text-red-500',
            normBadge: 'bg-indigo-100 text-indigo-700',
            toastIcon: 'text-yellow-400',
        };

        container.classList.add(...A.ring);

        // ── Instance state ────────────────────────────────────
        let tags = [];   // [{ raw, canonical, isCustom, wasNormalized }]
        let dropdownEl = null;
        let activeIdx = -1;

        // ── DROPDOWN ──────────────────────────────────────────
        function buildDropdown(suggestions) {
            clearDropdown();
            if (!suggestions.length) return;

            dropdownEl = document.createElement('div');
            dropdownEl.className = [
                'absolute left-0 right-0 top-full mt-1 z-50',
                'bg-white rounded-xl shadow-xl border overflow-hidden',
                A.dropBorder,
            ].join(' ');

            suggestions.forEach((s, i) => {
                const item = document.createElement('div');
                item.className = [
                    'px-4 py-2.5 text-sm cursor-pointer',
                    'flex items-center justify-between gap-2 transition-colors',
                    i === activeIdx ? A.highlight : 'hover:bg-slate-50 text-slate-700',
                ].join(' ');
                item.dataset.idx = i;

                const isStandard = ALL_CANONICAL.includes(s);
                item.innerHTML = `
                    <span class="font-medium">${highlightQuery(s, input.value.trim())}</span>
                    ${isStandard
                        ? `<span class="text-[9px] font-black uppercase tracking-wide text-slate-400">✓ standard</span>`
                        : ''}`;

                item.addEventListener('mousedown', e => {
                    e.preventDefault();
                    commitTag(s);
                });
                dropdownEl.appendChild(item);
            });

            // Make container relatively positioned so dropdown anchors correctly
            container.style.position = 'relative';
            container.appendChild(dropdownEl);
        }

        function clearDropdown() {
            dropdownEl?.remove();
            dropdownEl = null;
            activeIdx = -1;
        }

        function highlightQuery(term, query) {
            if (!query) return esc(term);
            const idx = term.toLowerCase().indexOf(query.toLowerCase());
            if (idx < 0) return esc(term);
            return esc(term.slice(0, idx))
                + `<strong class="font-black">${esc(term.slice(idx, idx + query.length))}</strong>`
                + esc(term.slice(idx + query.length));
        }

        // ── TAGS ──────────────────────────────────────────────
        function commitTag(raw) {
            const trimmed = raw.trim();
            if (!trimmed || trimmed.length < 2) return;
            if (trimmed.length > 50) {
                showToast('Tag is too long (max 50 chars)');
                return;
            }

            const { canonical, wasNormalized, isCustom } = normalize(trimmed);

            if (tags.some(t => t.canonical === canonical)) {
                input.value = '';
                clearDropdown();
                showToast(`Already added: "${canonical}"`);
                return;
            }

            tags.push({ raw: trimmed, canonical, wasNormalized, isCustom: !!isCustom });
            input.value = '';
            clearDropdown();
            renderTags();
            errorEl?.classList.add('hidden');

            if (wasNormalized) {
                showToast(`"${trimmed}" → saved as "${canonical}"`);
            }
        }

        function removeTag(canonical) {
            tags = tags.filter(t => t.canonical !== canonical);
            renderTags();
        }

        function renderTags() {
            container.querySelectorAll('.smart-tag').forEach(el => el.remove());

            tags.forEach(tag => {
                const pill = document.createElement('span');
                const pillClass = tag.isCustom ? A.pillCustom : A.pillStd;

                pill.className = `smart-tag inline-flex items-center gap-1.5 px-2.5 py-1
                    rounded-full text-[11px] font-bold border select-none ${pillClass}`;

                // Icon
                const icon = tag.isCustom
                    ? `<i class="fas fa-tag text-[9px] opacity-40" title="Custom tag"></i>`
                    : `<i class="fas fa-check text-[9px] opacity-50"></i>`;

                // AUTO badge if synonym-normalized
                const badge = tag.wasNormalized
                    ? `<span class="text-[8px] px-1 rounded font-black ${A.normBadge}"
                            title="Auto-normalized from: ${esc(tag.raw)}">AUTO</span>`
                    : '';

                // Remove button — uses data attribute to avoid inline JS escaping issues
                const btnId = `rm-${containerId}-${tag.canonical.replace(/\W/g, '_')}`;
                pill.innerHTML = `${icon}${esc(tag.canonical)}${badge}
                    <button type="button" id="${btnId}"
                        class="transition ${A.pillBtnBase} text-[10px] leading-none"
                        title="Remove">
                        <i class="fas fa-times"></i>
                    </button>`;

                container.insertBefore(pill, input);

                // Wire remove button safely (no inline onclick)
                pill.querySelector(`#${btnId}`)
                    .addEventListener('click', () => removeTag(tag.canonical));
            });
        }

        // ── KEYBOARD NAVIGATION ───────────────────────────────
        input.addEventListener('keydown', e => {
            const items = dropdownEl
                ? Array.from(dropdownEl.querySelectorAll('[data-idx]'))
                : [];

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIdx = Math.min(activeIdx + 1, items.length - 1);
                refreshHighlight(items);
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIdx = Math.max(activeIdx - 1, -1);
                refreshHighlight(items);
                return;
            }
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                if (activeIdx >= 0 && items[activeIdx]) {
                    commitTag(getSuggestions(input.value.trim())[activeIdx] ?? input.value);
                } else if (input.value.trim()) {
                    commitTag(input.value.trim());
                }
                return;
            }
            if (e.key === 'Backspace' && input.value === '' && tags.length > 0) {
                removeTag(tags[tags.length - 1].canonical);
                return;
            }
            if (e.key === 'Escape') {
                clearDropdown();
            }
        });

        function refreshHighlight(items) {
            items.forEach((item, i) => {
                item.className = item.className
                    .replace(/bg-\S+|text-\S+-\d{3}/g, '')
                    .trim();
                const cls = i === activeIdx
                    ? A.highlight.split(' ')
                    : ['hover:bg-slate-50', 'text-slate-700'];
                item.classList.add(...cls);
            });
        }

        // ── INPUT → AUTOCOMPLETE ──────────────────────────────
        input.addEventListener('input', function () {
            const val = this.value.trim();
            if (val.length < 2) { clearDropdown(); return; }
            buildDropdown(getSuggestions(val));
        });

        // Close on outside click
        document.addEventListener('click', e => {
            if (!container.contains(e.target)) clearDropdown();
        });

        // Click anywhere on container → focus input
        container.addEventListener('click', () => input.focus());

        // ── TOAST ─────────────────────────────────────────────
        function showToast(msg) {
            document.getElementById('smartTagToast')?.remove();
            const toast = document.createElement('div');
            toast.id = 'smartTagToast';
            toast.className = [
                'fixed bottom-6 left-1/2 -translate-x-1/2 z-[99]',
                'px-4 py-2.5 bg-slate-800 text-white text-xs font-semibold',
                'rounded-xl shadow-xl flex items-center gap-2',
                'transition-opacity duration-300',
            ].join(' ');
            toast.innerHTML = `<i class="fas fa-wand-magic-sparkles ${A.toastIcon}"></i> ${esc(msg)}`;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }, 2500);
        }

        // ── PUBLIC API ────────────────────────────────────────
        return {
            /** All tag objects */
            getTags: () => [...tags],

            /** Array of canonical strings */
            getCanonicalList: () => tags.map(t => t.canonical),

            /**
             * Compiled string for hidden_marks / matching engine.
             * Format: "Keywords: cracked screen, samsung, name written inside"
             * Custom tags are flagged: "Keywords: cracked screen, [custom: my weird tag]"
             */
            getCompiledKeywords() {
                if (!tags.length) return '';
                return 'Keywords: ' + tags.map(t =>
                    t.isCustom ? `[custom: ${t.canonical}]` : t.canonical
                ).join(', ');
            },

            /** True if any non-standard custom tags were added */
            hasCustomTags: () => tags.some(t => t.isCustom),

            /** Programmatically add a tag (e.g. from quick-add buttons) */
            addSuggested: (val) => commitTag(val),

            /** Clear all tags */
            clear: () => { tags = []; renderTags(); },

            /** Validate — returns true if at least one tag exists */
            validate() {
                if (tags.length === 0) {
                    errorEl?.classList.remove('hidden');
                    container.classList.add('shake');
                    setTimeout(() => container.classList.remove('shake'), 400);
                    return false;
                }
                errorEl?.classList.add('hidden');
                return true;
            },
        };
    }

    // Public surface
    return { init, getSuggestions, normalize, VOCABULARY, SYNONYMS };

})();