/**
 * CMU Lost & Found — Smart Keyword Tag Input  v3.0
 * Gemini-first, vocabulary.json fallback
 *
 * Drop in: assets/scripts/smart_tag_input.js
 *
 * Strategy:
 *   PRIMARY   → Gemini AI via core/get_suggestions.php
 *   FALLBACK  → vocabulary.json (used when AI fails, is unavailable, or returns empty)
 *
 * Usage:
 *   const tagInput = await SmartTagInput.init({ ... });
 *
 * Because vocabulary loading is async, init() returns a Promise.
 */

const SmartTagInput = (() => {

    // ── Loaded from vocabulary.json at init time ───────────────
    let VOCAB = {};   // full parsed JSON
    let ALL_TERMS = [];   // flat array of all canonical trait + keyword strings
    let SYNONYMS = {};   // synonym map

    // ── Vocabulary loader ──────────────────────────────────────
    async function loadVocabulary() {
        if (ALL_TERMS.length > 0) return; // already loaded
        try {
            const res = await fetch('../assets/data/vocabulary.json');
            VOCAB = await res.json();
            SYNONYMS = VOCAB.synonyms || {};

            ALL_TERMS = Object.values(VOCAB.categories || {}).flatMap(cat =>
                [...(cat.traits || []), ...(cat.keywords || [])]
            );
        } catch (e) {
            console.warn('SmartTagInput: could not load vocabulary.json', e);
        }
    }

    // ── Get standard traits + keywords for a category ──────────
    function getStandardForCategory(category) {
        const cat = (VOCAB.categories || {})[category];
        if (!cat) return { traits: [], keywords: [] };
        return {
            traits: cat.traits || [],
            keywords: cat.keywords || [],
        };
    }

    // ── Fuzzy score (0–1) ──────────────────────────────────────
    function fuzzyScore(query, candidate) {
        const q = query.toLowerCase().trim();
        const c = candidate.toLowerCase();
        if (c === q) return 1.0;
        if (c.startsWith(q)) return 0.9;
        if (c.includes(q)) return 0.75;
        const words = c.split(/\s+/);
        if (words.some(w => w.startsWith(q))) return 0.65;
        let hits = 0;
        for (let i = 0; i < q.length - 1; i++) {
            if (c.includes(q[i] + q[i + 1])) hits++;
        }
        const ratio = q.length > 1 ? hits / (q.length - 1) : 0;
        return ratio > 0.45 ? ratio * 0.55 : 0;
    }

    // ── Normalize via synonym map ──────────────────────────────
    function normalize(raw) {
        const lower = raw.toLowerCase().trim();
        if (SYNONYMS[lower])
            return { canonical: SYNONYMS[lower].toLowerCase(), wasNormalized: true };
        const allLower = ALL_TERMS.map(t => t.toLowerCase());
        if (allLower.includes(lower))
            return { canonical: lower, wasNormalized: false };
        if (lower.length >= 4) {
            for (const [syn, canon] of Object.entries(SYNONYMS)) {
                if (syn.startsWith(lower))
                    return { canonical: canon.toLowerCase(), wasNormalized: true };
            }
        }
        return { canonical: lower, wasNormalized: false, isCustom: true };
    }

    // ── Autocomplete suggestions from vocabulary ───────────────
    function getSuggestions(query, limit = 7) {
        if (!query || query.length < 2) return [];
        return ALL_TERMS
            .map(term => ({ term, score: fuzzyScore(query, term) }))
            .filter(r => r.score > 0.3)
            .sort((a, b) => b.score - a.score)
            .slice(0, limit)
            .map(r => r.term);
    }

    // ── HTML escape ────────────────────────────────────────────
    function esc(str) {
        return String(str).replace(/[&<>"']/g,
            m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    }

    // ─────────────────────────────────────────────────────────────
    // MAIN INIT
    // ─────────────────────────────────────────────────────────────
    async function init({ inputId, containerId, errorId, accentColor = 'indigo' }) {

        await loadVocabulary();

        const input = document.getElementById(inputId);
        const container = document.getElementById(containerId);
        const errorEl = errorId ? document.getElementById(errorId) : null;

        if (!input || !container) {
            console.warn('SmartTagInput: element not found', { inputId, containerId });
            return null;
        }

        // ── Accent theme ─────────────────────────────────────────
        const A = accentColor === 'green' ? {
            ring: ['focus-within:ring-2', 'focus-within:ring-green-400', 'focus-within:border-green-400'],
            dropBorder: 'border-green-100',
            highlight: 'bg-green-50 text-green-800',
            pillStd: 'bg-green-50 border-green-200 text-green-900',
            pillCustom: 'bg-slate-100 border-slate-300 text-slate-600',
            pillBtn: 'text-green-300 hover:text-red-500',
            normBadge: 'bg-green-100 text-green-700',
            aiBadge: 'bg-teal-100 text-teal-700',
            toastIcon: 'text-green-400',
        } : {
            ring: ['focus-within:ring-2', 'focus-within:ring-indigo-400', 'focus-within:border-indigo-400'],
            dropBorder: 'border-indigo-100',
            highlight: 'bg-indigo-50 text-indigo-800',
            pillStd: 'bg-indigo-50 border-indigo-200 text-indigo-900',
            pillCustom: 'bg-slate-100 border-slate-300 text-slate-600',
            pillBtn: 'text-indigo-300 hover:text-red-500',
            normBadge: 'bg-indigo-100 text-indigo-700',
            aiBadge: 'bg-purple-100 text-purple-700',
            toastIcon: 'text-yellow-400',
        };

        container.classList.add(...A.ring);

        // ── Instance state ───────────────────────────────────────
        let tags = [];
        let dropdownEl = null;
        let activeIdx = -1;

        // ── DROPDOWN ─────────────────────────────────────────────
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
                    'px-4 py-2.5 text-sm cursor-pointer transition-colors',
                    'flex items-center justify-between gap-2',
                    i === activeIdx ? A.highlight : 'hover:bg-slate-50 text-slate-700',
                ].join(' ');
                item.dataset.idx = i;
                const isStd = ALL_TERMS.map(t => t.toLowerCase()).includes(s.toLowerCase());
                item.innerHTML = `
                    <span class="font-medium">${highlightQuery(s, input.value.trim())}</span>
                    ${isStd ? `<span class="text-[9px] font-black uppercase tracking-wide text-slate-400">✓ standard</span>` : ''}`;
                item.addEventListener('mousedown', e => { e.preventDefault(); commitTag(s); });
                dropdownEl.appendChild(item);
            });

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

        // ── TAG MANAGEMENT ────────────────────────────────────────
        function commitTag(raw, isAI = false) {
            const trimmed = raw.trim();
            if (!trimmed || trimmed.length < 2) return;
            if (trimmed.length > 60) { showToast('Tag too long (max 60 chars)'); return; }

            const { canonical, wasNormalized, isCustom } = normalize(trimmed);

            if (tags.some(t => t.canonical === canonical)) {
                input.value = '';
                clearDropdown();
                showToast(`Already added: "${canonical}"`);
                return;
            }

            tags.push({ raw: trimmed, canonical, wasNormalized, isCustom: !!isCustom, isAI });
            input.value = '';
            clearDropdown();
            renderTags();
            errorEl?.classList.add('hidden');
            if (wasNormalized) showToast(`"${trimmed}" → saved as "${canonical}"`);
        }

        function removeTag(canonical) {
            tags = tags.filter(t => t.canonical !== canonical);
            renderTags();
        }

        function renderTags() {
            container.querySelectorAll('.smart-tag').forEach(el => el.remove());
            tags.forEach(tag => {
                const pill = document.createElement('span');
                const pillCls = tag.isCustom ? A.pillCustom : A.pillStd;
                pill.className = `smart-tag inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold border select-none ${pillCls}`;

                const icon = tag.isCustom
                    ? `<i class="fas fa-tag text-[9px] opacity-40" title="Custom tag"></i>`
                    : `<i class="fas fa-check text-[9px] opacity-50"></i>`;

                let badge = '';
                if (tag.isAI)
                    badge = `<span class="text-[8px] px-1 rounded font-black ${A.aiBadge}" title="AI-suggested">AI</span>`;
                else if (tag.wasNormalized)
                    badge = `<span class="text-[8px] px-1 rounded font-black ${A.normBadge}" title="Auto-normalized from: ${esc(tag.raw)}">AUTO</span>`;

                pill.innerHTML = `${icon}${esc(tag.canonical)}${badge}
                    <button type="button" class="transition ${A.pillBtn} text-[10px] leading-none" title="Remove">
                        <i class="fas fa-times"></i>
                    </button>`;
                container.insertBefore(pill, input);
                pill.querySelector('button').addEventListener('click', () => removeTag(tag.canonical));
            });
        }

        // ── KEYBOARD NAVIGATION ───────────────────────────────────
        input.addEventListener('keydown', e => {
            const items = dropdownEl
                ? Array.from(dropdownEl.querySelectorAll('[data-idx]'))
                : [];

            if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = Math.min(activeIdx + 1, items.length - 1); refreshHighlight(items); return; }
            if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = Math.max(activeIdx - 1, -1); refreshHighlight(items); return; }

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
                removeTag(tags[tags.length - 1].canonical); return;
            }
            if (e.key === 'Escape') clearDropdown();
        });

        function refreshHighlight(items) {
            items.forEach((item, i) => {
                item.className = item.className.replace(/bg-\S+|text-\S+-\d{3}/g, '').trim();
                item.classList.add(...(i === activeIdx ? A.highlight.split(' ') : ['hover:bg-slate-50', 'text-slate-700']));
            });
        }

        // ── INPUT → AUTOCOMPLETE ──────────────────────────────────
        input.addEventListener('input', function () {
            const val = this.value.trim();
            if (val.length < 2) { clearDropdown(); return; }
            buildDropdown(getSuggestions(val));
        });

        document.addEventListener('click', e => { if (!container.contains(e.target)) clearDropdown(); });
        container.addEventListener('click', () => input.focus());

        // ── TOAST ─────────────────────────────────────────────────
        function showToast(msg) {
            document.getElementById('smartTagToast')?.remove();
            const toast = document.createElement('div');
            toast.id = 'smartTagToast';
            toast.className = 'fixed bottom-6 left-1/2 -translate-x-1/2 z-[99] px-4 py-2.5 bg-slate-800 text-white text-xs font-semibold rounded-xl shadow-xl flex items-center gap-2 transition-opacity duration-300';
            toast.innerHTML = `<i class="fas fa-wand-magic-sparkles ${A.toastIcon}"></i> ${esc(msg)}`;
            document.body.appendChild(toast);
            setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 2500);
        }

        // ── PUBLIC API ────────────────────────────────────────────
        return {
            getTags: () => [...tags],
            getCanonicalList: () => tags.map(t => t.canonical),
            getCompiledKeywords: () => tags.length
                ? 'Keywords: ' + tags.map(t => t.isCustom ? `[custom: ${t.canonical}]` : t.canonical).join(', ')
                : '',
            hasCustomTags: () => tags.some(t => t.isCustom),
            hasAITags: () => tags.some(t => t.isAI),

            addSuggested: (val, isAI = false) => commitTag(val, isAI),

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

            clear: () => { tags = []; renderTags(); },
        };
    }

    // ── Public surface ─────────────────────────────────────────
    return { init, getSuggestions, normalize, loadVocabulary, getStandardForCategory };

})();