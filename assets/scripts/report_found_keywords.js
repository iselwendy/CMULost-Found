// TRAIT SUGGESTIONS — same vocabulary as report_lost.php
// so the matching engine compares apples to apples

const CATEGORY_TRAITS = {
    'Electronics': [
        'cracked screen', 'scratched', 'sticker on back', 'case/cover', 'no case',
        'charger included', 'dead battery', 'brand label visible', 'screen protector'
    ],
    'Valuables': [
        'bi-fold', 'tri-fold', 'zipper', 'cards inside', 'no cash', 'has coins',
        'keychain attached', 'lanyard', 'name engraved', 'monogram'
    ],
    'Documents': [
        'laminated', 'torn corner', 'punched hole', 'clipped together',
        'name visible', 'expired ID', 'school ID', 'government ID'
    ],
    'Books': [
        'highlighted pages', 'annotations', 'name written inside', 'dog-eared',
        'torn cover', 'bookmarked', 'loose pages', 'stamp/seal'
    ],
    'Clothing': [
        'stained', 'torn/ripped', 'faded color', 'logo/print', 'button missing',
        'zipper broken', 'hooded', 'oversized', 'name tag attached', 'embroidered'
    ],
    'Personal': [
        'dent/scratch', 'sticker', 'engraved name', 'custom design',
        'broken strap', 'missing cap', 'initials marked', 'worn/faded'
    ]
};

const CATEGORY_KEYWORDS = {
    'Electronics': ['Samsung', 'Apple', 'Xiaomi', 'OPPO', 'Realme', 'JBL', 'Anker', 'serial number'],
    'Valuables': ['leather', 'canvas', 'metal', 'name inside', 'peso bills', 'cards inside'],
    'Documents': ['CMU ID', 'PhilSys', 'Driver\'s License', 'SSS', 'UMID', 'birth certificate'],
    'Books': ['Calculus', 'Physics', 'Chemistry', 'Engineering', 'Accounting', 'Filipino', 'History'],
    'Clothing': ['jacket', 'hoodie', 'uniform', 'necktie', 'cardigan', 'cap', 'jersey', 'rubber shoes', 't-shirt'],
    'Personal': ['AquaFlask', 'Hydroflask', 'umbrella', 'tote bag', 'drawstring', 'lunchbox']
};


// STATE
let selectedColors = new Set();
let selectedTraits = new Set();
let keywords = [];

// CHIP TOGGLE
function toggleChip(el) {
    el.classList.toggle('selected');
    const val = el.dataset.value;
    const group = el.dataset.group;
    if (group === 'color') {
        el.classList.contains('selected') ? selectedColors.add(val) : selectedColors.delete(val);
    } else {
        el.classList.contains('selected') ? selectedTraits.add(val) : selectedTraits.delete(val);
    }
    updateQuality();
}

// CATEGORY-AWARE TRAIT CHIPS
function updateTraitSuggestions() {
    const cat = document.getElementById('itemCategory').value;
    const chips = document.getElementById('traitChips');
    const kws = document.getElementById('suggestedKeywordList');
    const kwBox = document.getElementById('suggestedKeywords');

    selectedTraits.clear();

    if (!cat || !CATEGORY_TRAITS[cat]) {
        chips.innerHTML = '<span class="text-xs text-slate-400 italic">Select a category above to see suggestions.</span>';
        kwBox.classList.add('hidden');
        return;
    }

    chips.innerHTML = CATEGORY_TRAITS[cat].map(t =>
        `<button type="button" class="trait-chip" data-group="trait" data-value="${t}" onclick="toggleChip(this)">${t}</button>`
    ).join('');

    kws.innerHTML = (CATEGORY_KEYWORDS[cat] || []).map(k =>
        `<button type="button" onclick="addKeyword('${k}')"
                class="text-[11px] px-2.5 py-1 bg-green-50 text-green-700 border border-green-100 rounded-full font-semibold hover:bg-green-100 transition">
                + ${k}
            </button>`
    ).join('');
    kwBox.classList.remove('hidden');

    updateQuality();
}


// KEYWORD TAG INPUT
const keywordInput = document.getElementById('keywordInput');

keywordInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ',') {
        e.preventDefault();
        const val = this.value.trim().replace(/,$/, '');
        if (val) addKeyword(val);
        this.value = '';
    }
    if (e.key === 'Backspace' && this.value === '' && keywords.length > 0) {
        removeKeyword(keywords[keywords.length - 1]);
    }
});

document.getElementById('tagContainer').addEventListener('click', () => keywordInput.focus());

function addKeyword(val) {
    val = val.trim().toLowerCase();
    if (!val || keywords.includes(val) || val.length < 2) return;
    if (val.length > 40) { alert('Observation is too long. Keep it under 40 characters.'); return; }
    keywords.push(val);
    renderTags();
    updateQuality();
}

function removeKeyword(val) {
    keywords = keywords.filter(k => k !== val);
    renderTags();
    updateQuality();
}

function renderTags() {
    document.querySelectorAll('.keyword-tag').forEach(el => el.remove());
    keywords.forEach(k => {
        const tag = document.createElement('span');
        tag.className = 'keyword-tag';
        tag.innerHTML = `${escapeHtml(k)} <button type="button" onclick="removeKeyword('${escapeHtml(k)}')" title="Remove"><i class="fas fa-times"></i></button>`;
        document.getElementById('tagContainer').insertBefore(tag, keywordInput);
    });
}

function escapeHtml(str) {
    return str.replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
}


// QUALITY METER
// Scoring:
//   color(s)    → 20 pts
//   traits      → 20 pts (4 pts each, max 5)
//   keywords    → 30 pts (10 pts each, max 3)
//   exact spot  → 15 pts
//   photo       → 15 pts (required for found items)
function updateQuality() {
    let score = 0;

    if (selectedColors.size > 0) score += 20;
    score += Math.min(selectedTraits.size * 4, 20);
    score += Math.min(keywords.length * 10, 30);
    if (document.getElementById('exactSpot').value.trim().length > 5) score += 15;
    if (document.getElementById('file-upload').files.length > 0) score += 15;

    const bar = document.getElementById('qualityBar');
    const label = document.getElementById('qualityLabel');
    const hint = document.getElementById('qualityHint');

    bar.style.width = score + '%';

    if (score === 0) {
        bar.style.backgroundColor = '#cbd5e1';
        label.textContent = 'Not started';
        label.className = 'text-[10px] font-black uppercase text-slate-400';
        hint.textContent = 'Fill in colors, traits, and observations to help the owner be found faster.';
    } else if (score < 40) {
        bar.style.backgroundColor = '#f97316';
        label.textContent = 'Weak — low match chance';
        label.className = 'text-[10px] font-black uppercase text-orange-500';
        hint.textContent = 'Add more traits or observations. Vague reports are harder to match.';
    } else if (score < 70) {
        bar.style.backgroundColor = '#eab308';
        label.textContent = 'Fair — can be improved';
        label.className = 'text-[10px] font-black uppercase text-yellow-500';
        hint.textContent = 'Good start! Add specific observations (name on item, brand) for better accuracy.';
    } else if (score < 90) {
        bar.style.backgroundColor = '#22c55e';
        label.textContent = 'Good — solid match profile';
        label.className = 'text-[10px] font-black uppercase text-green-500';
        hint.textContent = 'Nice! The exact spot and a photo will push this to excellent.';
    } else {
        bar.style.backgroundColor = '#16a34a';
        label.textContent = 'Excellent — high match chance';
        label.className = 'text-[10px] font-black uppercase text-green-700';
        hint.textContent = 'Great detail! This report will have the highest match probability.';
    }
}

// Hook quality meter to exact spot and photo
document.getElementById('exactSpot').addEventListener('input', updateQuality);
document.getElementById('file-upload').addEventListener('change', updateQuality);

// COMPILE HIDDEN FIELD BEFORE SUBMIT
// Format: "Colors: Black | Traits: cracked screen | Keywords: samsung, juan | Exact Spot: stairs near entrance"
document.getElementById('foundItemForm').addEventListener('submit', function (e) {
    let valid = true;

    if (selectedColors.size === 0) {
        document.getElementById('colorError').classList.remove('hidden');
        document.getElementById('colorChips').classList.add('shake');
        setTimeout(() => document.getElementById('colorChips').classList.remove('shake'), 400);
        valid = false;
    } else {
        document.getElementById('colorError').classList.add('hidden');
    }

    if (keywords.length === 0) {
        document.getElementById('tagError').classList.remove('hidden');
        document.getElementById('tagContainer').classList.add('shake');
        setTimeout(() => document.getElementById('tagContainer').classList.remove('shake'), 400);
        valid = false;
    } else {
        document.getElementById('tagError').classList.add('hidden');
    }

    if (!valid) {
        e.preventDefault();
        document.getElementById('identifyingSection').scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
    }

    // Compile structured string
    const parts = [];
    if (selectedColors.size > 0) parts.push('Colors: ' + [...selectedColors].join(', '));
    if (selectedTraits.size > 0) parts.push('Traits: ' + [...selectedTraits].join(', '));
    if (keywords.length > 0) parts.push('Keywords: ' + keywords.join(', '));

    const spot = document.getElementById('exactSpot').value.trim();
    if (spot) parts.push('Exact Spot: ' + spot);

    document.getElementById('compiledMarks').value = parts.join(' | ');
});


// PHOTO UPLOAD PREVIEW
function clearPreview() {
    document.getElementById('file-upload').value = '';
    document.getElementById('attachedStatus').classList.add('hidden');
    document.getElementById('uploadPlaceholder').classList.remove('hidden');
    updateQuality();
}

document.getElementById('file-upload').addEventListener('change', function () {
    if (this.files && this.files[0]) {
        document.getElementById('fileNameDisplay').textContent = this.files[0].name;
        document.getElementById('attachedStatus').classList.remove('hidden');
        document.getElementById('uploadPlaceholder').classList.add('hidden');
    }
});