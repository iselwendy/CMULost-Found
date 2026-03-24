// TRAIT SUGGESTIONS PER CATEGORY
// These keywords become the matching engine fodder

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

    // Suggested quick-add keywords
    kws.innerHTML = (CATEGORY_KEYWORDS[cat] || []).map(k =>
        `<button type="button" onclick="addKeyword('${k}')"
                class="text-[11px] px-2.5 py-1 bg-indigo-50 text-indigo-600 border border-indigo-100 rounded-full font-semibold hover:bg-indigo-100 transition">
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
    // Backspace to remove last tag
    if (e.key === 'Backspace' && this.value === '' && keywords.length > 0) {
        removeKeyword(keywords[keywords.length - 1]);
    }
});

// Click anywhere on tag container to focus input
document.getElementById('tagContainer').addEventListener('click', () => keywordInput.focus());

function addKeyword(val) {
    val = val.trim().toLowerCase();
    if (!val || keywords.includes(val) || val.length < 2) return;
    if (val.length > 40) { alert('Keyword is too long. Keep it under 40 characters.'); return; }
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
    // Remove all existing tags (not the input)
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
// Scoring rubric mirrors what the matching engine values:
//   color(s)   → 25 pts
//   traits     → 25 pts (5 pts each, max 5)
//   keywords   → 30 pts (10 pts each, max 3)
//   photo      → 20 pts
function updateQuality() {
    let score = 0;

    if (selectedColors.size > 0) score += 25;
    score += Math.min(selectedTraits.size * 5, 25);
    score += Math.min(keywords.length * 10, 30);
    if (document.getElementById('file-upload').files.length > 0) score += 20;

    const bar = document.getElementById('qualityBar');
    const label = document.getElementById('qualityLabel');
    const hint = document.getElementById('qualityHint');

    bar.style.width = score + '%';

    if (score === 0) {
        bar.style.backgroundColor = '#cbd5e1';
        label.textContent = 'Not started';
        label.className = 'text-[10px] font-black uppercase text-slate-400';
        hint.textContent = 'Fill in colors, traits, and keywords to improve match accuracy.';
    } else if (score < 40) {
        bar.style.backgroundColor = '#f97316';
        label.textContent = 'Weak — low match chance';
        label.className = 'text-[10px] font-black uppercase text-orange-500';
        hint.textContent = 'Add more traits or keywords. Vague descriptions are harder to match.';
    } else if (score < 70) {
        bar.style.backgroundColor = '#eab308';
        label.textContent = 'Fair — can be improved';
        label.className = 'text-[10px] font-black uppercase text-yellow-500';
        hint.textContent = 'Good start! Add specific keywords (brand, name, serial no.) for better accuracy.';
    } else if (score < 90) {
        bar.style.backgroundColor = '#22c55e';
        label.textContent = 'Good — solid match profile';
        label.className = 'text-[10px] font-black uppercase text-green-500';
        hint.textContent = 'Nice! Adding a photo will push this to excellent.';
    } else {
        bar.style.backgroundColor = '#6366f1';
        label.textContent = 'Excellent — high match chance';
        label.className = 'text-[10px] font-black uppercase text-indigo-500';
        hint.textContent = 'Great detail! Your report will have the highest match probability.';
    }
}

// Update quality when photo is added/removed
document.getElementById('file-upload').addEventListener('change', updateQuality);


// COMPILE HIDDEN FIELD BEFORE SUBMIT
// Produces a clean structured string for the DB:
// "Colors: Black, Blue | Traits: cracked screen, sticker on back | Keywords: samsung, juan, s24"
document.getElementById('lostItemForm').addEventListener('submit', function (e) {
    // Validation
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
        // Scroll to first error
        document.getElementById('identifyingSection').scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
    }

    // Compile the structured description
    const parts = [];
    if (selectedColors.size > 0) parts.push('Colors: ' + [...selectedColors].join(', '));
    if (selectedTraits.size > 0) parts.push('Traits: ' + [...selectedTraits].join(', '));
    if (keywords.length > 0) parts.push('Keywords: ' + keywords.join(', '));

    document.getElementById('compiledMarks').value = parts.join(' | ');
});


// PHOTO UPLOAD PREVIEW (mirrors item_image_upload.js)
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