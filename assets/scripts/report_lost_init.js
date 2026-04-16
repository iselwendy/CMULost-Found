// STATE
let selectedColors = new Set();
let selectedTraits = new Set();
let tagInput = null;   // SmartTagInput instance (set after async init)
let aiDebounceTimer = null;  // debounce handle for AI calls


// BOOT — load vocabulary then init everything
(async () => {
    tagInput = await SmartTagInput.init({
        inputId: 'keywordInput',
        containerId: 'tagContainer',
        errorId: 'tagError',
        accentColor: 'indigo',
    });

    // Populate color chips from vocabulary.json
    renderColorChips();

    // Re-evaluate quality whenever a tag pill is added or removed
    new MutationObserver(updateQuality)
        .observe(document.getElementById('tagContainer'), { childList: true });
})();


// COLOR CHIPS — built from vocabulary.json via SmartTagInput
function renderColorChips() {
    const colors = SmartTagInput.loadVocabulary
        ? (SmartTagInput._vocab?.colors ?? getFallbackColors())
        : getFallbackColors();

    // Access VOCAB through the module's exposed getStandardForCategory
    // Colors are loaded inside the module; we read them from the JSON directly
    fetch('../assets/data/vocabulary.json')
        .then(r => r.json())
        .then(vocab => {
            const colors = vocab.colors || getFallbackColors();
            const container = document.getElementById('colorChips');
            container.innerHTML = colors.map(c =>
                `<button type="button" class="trait-chip" data-group="color" data-value="${c}" onclick="toggleChip(this)">${c}</button>`
            ).join('');
        })
        .catch(() => {
            // Fallback if fetch fails
            const container = document.getElementById('colorChips');
            container.innerHTML = getFallbackColors().map(c =>
                `<button type="button" class="trait-chip" data-group="color" data-value="${c}" onclick="toggleChip(this)">${c}</button>`
            ).join('');
        });
}

function getFallbackColors() {
    return ['Black', 'White', 'Gray', 'Brown', 'Red', 'Orange', 'Yellow', 'Green', 'Blue', 'Purple', 'Pink', 'Gold', 'Silver'];
}


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


// ON CATEGORY CHANGE — load standard traits from JSON,
// then re-trigger AI if a title is already entered
function onCategoryChange() {
    const cat = document.getElementById('itemCategory').value;
    selectedTraits.clear();

    if (!cat) {
        document.getElementById('traitChips').innerHTML =
            '<span class="text-xs text-slate-400 italic">Select a category and enter a title to see suggestions.</span>';
        document.getElementById('suggestedKeywords').classList.add('hidden');
        document.getElementById('aiTraitBadge').classList.add('hidden');
        updateQuality();
        return;
    }

    // Load standard traits from vocabulary.json
    fetch('../assets/data/vocabulary.json')
        .then(r => r.json())
        .then(vocab => {
            const catData = vocab.categories[cat] || {};
            const traits = catData.traits || [];
            const keywords = catData.keywords || [];
            renderStandardTraits(traits);
            renderQuickAddKeywords(keywords, false);
            updateQuality();

            // If a title is already typed, fire AI immediately
            const title = document.getElementById('itemTitle').value.trim();
            if (title.length >= 3) triggerAISuggestions(title, cat);
        });
}


// TITLE INPUT — debounced AI trigger
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('itemTitle').addEventListener('input', function () {
        clearTimeout(aiDebounceTimer);
        const title = this.value.trim();
        const cat = document.getElementById('itemCategory').value;

        if (title.length < 3 || !cat) return;

        // Show "analyzing..." after a short delay
        aiDebounceTimer = setTimeout(() => {
            triggerAISuggestions(title, cat);
        }, 2000); // 700ms debounce — fires after user pauses typing
    });
});


// AI SUGGESTIONS — fetch from get_suggestions.php,
// merge new traits/keywords without overwriting standard ones
async function triggerAISuggestions(title, category) {
    console.log('triggerAISuggestions called:', { title, category });
    const statusEl = document.getElementById('aiStatus');
    const statusText = document.getElementById('aiStatusText');
    statusEl.classList.remove('hidden');
    statusText.textContent = 'Analyzing item for smart suggestions...';

    try {
        const res = await fetch('../core/get_suggestions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title, category, report_type: 'lost' }),
        });
        const data = await res.json();

        if (data.traits.length > 0 || data.keywords.length > 0) {
            mergeAITraits(data.traits);
            mergeAIKeywords(data.keywords);

            if (data.source === 'ai') {
                statusText.textContent = `✨ Gemini: ${data.traits.length + data.keywords.length} AI suggestions added`;
            } else if (data.source === 'vocabulary') {
                statusText.textContent = `📖 Fallback: loaded ${data.traits.length + data.keywords.length} standard suggestions from vocabulary`;
            } else if (data.source === 'rate_limited') {
                statusText.textContent = `⏳ Rate limited — showing vocabulary suggestions instead`;
            }

            statusEl.classList.remove('hidden');
            setTimeout(() => statusEl.classList.add('hidden'), 4000);
        } else {
            statusEl.classList.add('hidden');
        }
    } catch (err) {
        // Silently fail — standard vocab still works
        statusEl.classList.add('hidden');
    }
}


// RENDER HELPERS
function renderStandardTraits(traits) {
    const chips = document.getElementById('traitChips');
    document.getElementById('aiTraitBadge').classList.add('hidden');

    if (!traits.length) {
        chips.innerHTML = '<span class="text-xs text-slate-400 italic">No standard traits for this category.</span>';
        return;
    }
    chips.innerHTML = traits.map(t =>
        `<button type="button" class="trait-chip" data-group="trait" data-value="${t}" onclick="toggleChip(this)">${t}</button>`
    ).join('');
}

function mergeAITraits(aiTraits) {
    if (!aiTraits.length) return;
    const chips = document.getElementById('traitChips');

    // Remove placeholder if present
    chips.querySelectorAll('span.text-slate-400').forEach(el => el.remove());

    // Add AI chips that don't already exist in the chip list
    const existing = new Set(
        Array.from(chips.querySelectorAll('.trait-chip')).map(el => el.dataset.value.toLowerCase())
    );

    aiTraits.forEach(t => {
        if (!existing.has(t.toLowerCase())) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'trait-chip ai-chip';
            btn.dataset.group = 'trait';
            btn.dataset.value = t;
            btn.onclick = function () { toggleChip(this); };
            btn.textContent = t;
            chips.appendChild(btn);
        }
    });

    document.getElementById('aiTraitBadge').classList.remove('hidden');
}

function renderQuickAddKeywords(keywords, isAI = false) {
    const kwBox = document.getElementById('suggestedKeywords');
    const kwList = document.getElementById('suggestedKeywordList');

    if (!keywords.length) { kwBox.classList.add('hidden'); return; }

    const btnClass = isAI
        ? 'text-[11px] px-2.5 py-1 bg-purple-50 text-purple-700 border border-purple-100 rounded-full font-semibold hover:bg-purple-100 transition'
        : 'text-[11px] px-2.5 py-1 bg-indigo-50 text-indigo-600 border border-indigo-100 rounded-full font-semibold hover:bg-indigo-100 transition';

    // Merge new AI keywords alongside existing ones
    const existingTexts = new Set(
        Array.from(kwList.querySelectorAll('button')).map(el => el.textContent.replace('+ ', '').toLowerCase())
    );

    keywords.forEach(k => {
        if (!existingTexts.has(k.toLowerCase())) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = btnClass;
            btn.textContent = '+ ' + k;
            btn.addEventListener('click', () => addKeyword(k, isAI));
            kwList.appendChild(btn);
        }
    });

    kwBox.classList.remove('hidden');
}

function mergeAIKeywords(aiKeywords) {
    renderQuickAddKeywords(aiKeywords, true);
}


// QUICK-ADD HANDLER
function addKeyword(val, isAI = false) {
    if (tagInput) { tagInput.addSuggested(val, isAI); updateQuality(); }
}


// QUALITY METER
// color 25 | traits 25 | keywords 30 | photo 20
function updateQuality() {
    if (!tagInput) return;
    let score = 0;
    if (selectedColors.size > 0) score += 25;
    score += Math.min(selectedTraits.size * 5, 25);
    score += Math.min(tagInput.getTags().length * 10, 30);
    if (document.getElementById('file-upload').files.length > 0) score += 20;

    const bar = document.getElementById('qualityBar');
    const label = document.getElementById('qualityLabel');
    const hint = document.getElementById('qualityHint');
    bar.style.width = score + '%';

    const levels = [
        { max: 0, color: '#cbd5e1', text: 'Not started', cls: 'text-slate-400', msg: 'Fill in colors, traits, and keywords to improve match accuracy.' },
        { max: 40, color: '#f97316', text: 'Weak — low match chance', cls: 'text-orange-500', msg: 'Add more traits or keywords. Vague descriptions are harder to match.' },
        { max: 70, color: '#eab308', text: 'Fair — can be improved', cls: 'text-yellow-500', msg: 'Good start! Add specific keywords (brand, name, serial no.) for better accuracy.' },
        { max: 90, color: '#22c55e', text: 'Good — solid match profile', cls: 'text-green-500', msg: 'Nice! Adding a photo will push this to excellent.' },
        { max: 101, color: '#6366f1', text: 'Excellent — high match chance', cls: 'text-indigo-500', msg: 'Great detail! Your report will have the highest match probability.' },
    ];
    const lvl = levels.find(l => score <= l.max) || levels[levels.length - 1];
    bar.style.backgroundColor = lvl.color;
    label.textContent = lvl.text;
    label.className = `text-[10px] font-black uppercase ${lvl.cls}`;
    hint.textContent = lvl.msg;
}


// PHOTO UPLOAD PREVIEW
function clearPreview() {
    document.getElementById('file-upload').value = '';
    document.getElementById('attachedStatus').classList.add('hidden');
    document.getElementById('uploadPlaceholder').classList.remove('hidden');
    updateQuality();
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('file-upload').addEventListener('change', function () {
        if (this.files && this.files[0]) {
            document.getElementById('fileNameDisplay').textContent = this.files[0].name;
            document.getElementById('attachedStatus').classList.remove('hidden');
            document.getElementById('uploadPlaceholder').classList.add('hidden');
            updateQuality();
        }
    });
});


// FORM SUBMIT
document.getElementById('lostItemForm').addEventListener('submit', function (e) {
    let valid = true;

    if (selectedColors.size === 0) {
        document.getElementById('colorError').classList.remove('hidden');
        document.getElementById('colorChips').classList.add('shake');
        setTimeout(() => document.getElementById('colorChips').classList.remove('shake'), 400);
        valid = false;
    } else {
        document.getElementById('colorError').classList.add('hidden');
    }

    if (tagInput && !tagInput.validate()) valid = false;

    if (!valid) {
        e.preventDefault();
        document.getElementById('identifyingSection').scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
    }

    const parts = [];
    if (selectedColors.size > 0) parts.push('Colors: ' + [...selectedColors].join(', '));
    if (selectedTraits.size > 0) parts.push('Traits: ' + [...selectedTraits].join(', '));
    if (tagInput) parts.push(tagInput.getCompiledKeywords());
    document.getElementById('compiledMarks').value = parts.join(' | ');
});