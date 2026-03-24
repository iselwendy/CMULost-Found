<?php
require_once '../core/auth_functions.php';

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$user_id = $_SESSION['user_id'] ?? null;
$stmt->execute([$user_id]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Lost Item | CMU Lost & Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="../assets/images/system-icon.png">
    <link rel="stylesheet" href="../assets/styles/header.css">
    <link rel="stylesheet" href="../assets/styles/root.css">
    <link rel="stylesheet" href="../assets/styles/report_lost.css">
</head>
<body class="bg-slate-50 min-h-screen text-slate-900">

    <?php require_once '../includes/header.php'; ?>

    <main class="max-w-2xl mx-auto px-4 py-8">
        <div class="glass-card rounded-2xl shadow-xl p-6 md:p-8">
            <header class="mb-8">
                <div class="inline-flex items-center justify-center w-12 h-12 bg-indigo-50 text-indigo-600 rounded-xl mb-4">
                    <i class="fas fa-search-plus text-xl"></i>
                </div>
                <h1 class="text-2xl font-bold text-slate-800">Report a Lost Item</h1>
                <p class="text-slate-500 mt-1">Provide details to help our matching engine find your item.</p>
            </header>

            <form action="process_report.php" method="POST" enctype="multipart/form-data" class="space-y-6" id="lostItemForm">
                <input type="hidden" name="reporter_id" value="<?php echo $user_id; ?>">
                <input type="hidden" name="report_type" value="lost">
                <input type="hidden" name="hidden_marks" id="compiledMarks">

                <!-- ── PUBLIC INFO ── -->
                <section class="space-y-4">
                    <h3 class="text-sm font-bold uppercase tracking-wider text-slate-400 border-b pb-2 flex justify-between items-center">
                        <span>Public Information</span>
                        <i class="fas fa-globe-asia text-xs"></i>
                    </h3>

                    <div>
                        <label class="block text-sm font-semibold mb-1.5 text-slate-700">Item Name / Title</label>
                        <input type="text" name="title" id="itemTitle" required
                               placeholder="e.g. Black Leather Wallet"
                               class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition-all">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold mb-1.5 text-slate-700">Category</label>
                            <select name="category" id="itemCategory" required
                                    class="w-full px-4 py-2.5 rounded-xl border border-slate-200 bg-white outline-none"
                                    onchange="updateTraitSuggestions()">
                                <option value="">Select category</option>
                                <option value="Electronics">Electronics</option>
                                <option value="Valuables">Valuables</option>
                                <option value="Documents">Documents/IDs</option>
                                <option value="Books">Books/Stationery</option>
                                <option value="Clothing">Clothing</option>
                                <option value="Personal">Personal Items</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1.5 text-slate-700">Date Lost</label>
                            <input type="datetime-local" name="date_lost" required
                                   class="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-1.5 text-slate-700">Location Last Seen</label>
                        <select name="location" id="itemLocation" required
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-200 bg-white outline-none">
                            <option value="">Select location</option>
                            <option value="Other">Other</option>
                            <option value="Main Library">Main Library</option>
                            <option value="Innovation Bldg">Innovation Bldg</option>
                            <option value="ERC Bldg">ERC Bldg</option>
                            <option value="University Canteen">University Canteen</option>
                        </select>
                    </div>
                </section>

                <!-- ── IDENTIFYING DETAILS (PRIVATE) ── -->
                <section class="space-y-5 pt-4" id="identifyingSection">
                    <div class="flex items-center gap-2 border-b pb-2">
                        <h3 class="text-sm font-bold uppercase tracking-wider text-slate-400">Private Verification Marks</h3>
                        <span class="text-[10px] bg-amber-500 text-white px-2 py-0.5 rounded-full font-bold">REDACTED FROM GALLERY</span>
                    </div>

                    <p class="text-xs text-slate-500 italic bg-slate-100 p-3 rounded-lg border-l-4 border-indigo-500">
                        <i class="fas fa-user-shield mr-1"></i>
                        <strong>Privacy Guard:</strong> These details are <u>NOT</u> shown in the public gallery.
                        They are used by OSA to verify ownership and by the <strong>matching engine</strong> to find your item.
                        The more specific you are, the faster it finds a match.
                    </p>

                    <!-- ❶ COLOR -->
                    <div>
                        <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">
                            <i class="fas fa-palette mr-1 text-indigo-400"></i> Primary Color(s)
                            <span class="text-red-400 ml-1">*</span>
                        </label>
                        <div class="flex flex-wrap gap-2" id="colorChips">
                            <?php
                            $colors = ['Black','White','Gray','Brown','Red','Orange','Yellow','Green','Blue','Purple','Pink','Gold','Silver'];
                            foreach ($colors as $c): ?>
                                <button type="button" class="trait-chip" data-group="color" data-value="<?php echo $c; ?>" onclick="toggleChip(this)">
                                    <?php echo $c; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <p id="colorError" class="text-xs text-red-500 mt-1 hidden">Please select at least one color.</p>
                    </div>

                    <!-- ❷ TRAITS -->
                    <div id="traitSection">
                        <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">
                            <i class="fas fa-fingerprint mr-1 text-indigo-400"></i> Distinguishing Traits
                            <span class="text-slate-400 text-[10px] normal-case font-normal ml-1">(select all that apply)</span>
                        </label>
                        <div class="flex flex-wrap gap-2" id="traitChips">
                            <span class="text-xs text-slate-400 italic">Select a category above to see suggestions.</span>
                        </div>
                    </div>

                    <!-- ❸ KEYWORD TAGS -->
                    <div>
                        <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-1">
                            <i class="fas fa-tags mr-1 text-indigo-400"></i> Specific Keywords
                            <span class="text-slate-400 text-[10px] normal-case font-normal ml-1">(brand, name written, serial no., sticker, etc.)</span>
                        </label>
                        <p class="text-[11px] text-slate-400 mb-2">
                            Type a keyword and press
                            <kbd class="bg-slate-100 border border-slate-200 rounded px-1 text-[10px]">Enter</kbd> or
                            <kbd class="bg-slate-100 border border-slate-200 rounded px-1 text-[10px]">,</kbd>
                            to add it. Autocomplete suggests standard terms as you type.
                        </p>

                        <div id="tagContainer"
                             class="min-h-[44px] w-full px-3 py-2 rounded-xl border border-slate-200 bg-white flex flex-wrap gap-1.5 items-center cursor-text transition-all">
                            <input type="text" id="keywordInput"
                                   placeholder="e.g. Samsung, Juan written inside, anime sticker..."
                                   class="flex-1 min-w-[160px] outline-none text-sm text-slate-700 bg-transparent py-0.5"
                                   autocomplete="off">
                        </div>
                        <div id="tagError" class="text-xs text-red-500 mt-1 hidden">Please add at least one specific keyword.</div>

                        <div id="suggestedKeywords" class="mt-2 hidden">
                            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider mb-1.5">Quick add:</p>
                            <div id="suggestedKeywordList" class="flex flex-wrap gap-1.5"></div>
                        </div>
                    </div>

                    <!-- ❹ QUALITY METER -->
                    <div id="qualityMeter" class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                        <div class="flex items-center justify-between mb-1.5">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Description Quality</p>
                            <span id="qualityLabel" class="text-[10px] font-black text-slate-400 uppercase">Not started</span>
                        </div>
                        <div class="h-1.5 bg-slate-200 rounded-full overflow-hidden">
                            <div id="qualityBar" class="h-full rounded-full bg-slate-300" style="width: 0%"></div>
                        </div>
                        <p id="qualityHint" class="text-[11px] text-slate-400 mt-1.5 italic">Fill in colors, traits, and keywords to improve match accuracy.</p>
                    </div>

                    <!-- ❺ PHOTO -->
                    <div>
                        <label class="block text-sm font-semibold mb-1.5 text-indigo-600">Reference Photo</label>
                        <div id="dropZone" class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-slate-200 border-dashed rounded-xl hover:border-indigo-400 transition-all cursor-pointer group relative overflow-hidden">
                            <div class="space-y-1 text-center" id="uploadPlaceholder">
                                <i class="fas fa-cloud-upload-alt text-slate-300 text-3xl mb-2 group-hover:text-indigo-500 transition-colors"></i>
                                <div class="flex text-sm text-slate-600 justify-center">
                                    <label class="relative cursor-pointer bg-white rounded-md font-medium text-indigo-600 hover:underline">
                                        <span>Click to upload</span>
                                        <input id="file-upload" name="photo" type="file" class="sr-only" accept="image/*">
                                    </label>
                                    <p class="pl-1 text-slate-400">or drag and drop</p>
                                </div>
                                <p class="text-[10px] text-slate-400 uppercase tracking-widest">PNG, JPG up to 5MB</p>
                            </div>
                            <div id="attachedStatus" class="hidden absolute inset-0 bg-white/90 flex flex-col items-center justify-center p-4 text-center z-10">
                                <div class="w-12 h-12 bg-green-100 text-green-600 rounded-full flex items-center justify-center mb-2">
                                    <i class="fas fa-check"></i>
                                </div>
                                <p class="text-sm font-bold text-slate-800" id="fileNameDisplay">image.jpg</p>
                                <p class="text-xs text-slate-500">File attached successfully</p>
                                <button type="button" onclick="clearPreview()" class="mt-3 text-xs text-red-500 font-semibold hover:underline">Change photo</button>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="pt-6">
                    <button type="submit" id="submitBtn"
                            class="w-full bg-cmu-blue hover:bg-slate-800 text-white font-bold py-4 rounded-xl shadow-lg transition-all transform active:scale-[0.98] flex items-center justify-center gap-2">
                        <i class="fas fa-check-circle"></i>
                        Confirm & Post Report
                    </button>
                    <p class="text-center text-[11px] text-slate-400 mt-4 px-4">
                        By submitting, you acknowledge that providing false information is a violation of the Student Code of Conduct.
                    </p>
                </div>
            </form>
        </div>
    </main>

    <?php require_once '../includes/footer.php'; ?>

    <script src="../assets/scripts/profile-dropdown.js"></script>
    <script src="../assets/scripts/item_image_upload.js"></script>
    <script src="../assets/scripts/smart_tag_input.js"></script>
    <script>
    // ─────────────────────────────────────────────
    // CATEGORY TRAIT + KEYWORD SUGGESTIONS
    // Vocabulary must match report_found.php exactly
    // so the matching engine compares like-for-like
    // ─────────────────────────────────────────────
    const CATEGORY_TRAITS = {
        'Electronics': [
            'cracked screen','scratched','sticker on back','case/cover','no case',
            'charger included','dead battery','brand label visible','screen protector'
        ],
        'Valuables': [
            'bi-fold','tri-fold','zipper closure','cards inside','no cash','has coins',
            'keychain attached','lanyard attached','name engraved','monogram'
        ],
        'Documents': [
            'laminated','torn corner','punched hole','clipped together',
            'name visible','expired','school id','government issued'
        ],
        'Books': [
            'highlighted pages','annotations','name written inside','dog-eared',
            'torn cover','bookmarked','loose pages','stamp/seal'
        ],
        'Clothing': [
            'name tag inside','embroidered','ironed-on patch','torn/ripped',
            'stained','logo visible','hooded','sleeveless'
        ],
        'Personal': [
            'dent/scratch','sticker on back','name engraved','custom design',
            'broken strap','missing cap','initials marked','worn/faded'
        ]
    };

    const CATEGORY_KEYWORDS = {
        'Electronics': ['Samsung','Apple','Xiaomi','OPPO','Realme','JBL','Anker','serial number'],
        'Valuables':   ['leather','canvas','metal','name inside','peso bills','cards inside'],
        'Documents':   ['CMU ID','PhilSys',"Driver's License",'SSS','UMID','birth certificate'],
        'Books':       ['Calculus','Physics','Chemistry','Engineering','Accounting','Filipino','History'],
        'Clothing':    ['uniform','PE shirt','jacket','hoodie','jersey'],
        'Personal':    ['AquaFlask','Hydroflask','umbrella','tote bag','drawstring','lunchbox']
    };

    // ─────────────────────────────────────────────
    // STATE
    // ─────────────────────────────────────────────
    let selectedColors = new Set();
    let selectedTraits = new Set();

    // ─────────────────────────────────────────────
    // CHIP TOGGLE
    // ─────────────────────────────────────────────
    function toggleChip(el) {
        el.classList.toggle('selected');
        const val   = el.dataset.value;
        const group = el.dataset.group;
        if (group === 'color') {
            el.classList.contains('selected') ? selectedColors.add(val) : selectedColors.delete(val);
        } else {
            el.classList.contains('selected') ? selectedTraits.add(val) : selectedTraits.delete(val);
        }
        updateQuality();
    }

    // ─────────────────────────────────────────────
    // CATEGORY-AWARE TRAIT CHIPS + QUICK-ADD KEYWORDS
    // ─────────────────────────────────────────────
    function updateTraitSuggestions() {
        const cat    = document.getElementById('itemCategory').value;
        const chips  = document.getElementById('traitChips');
        const kwList = document.getElementById('suggestedKeywordList');
        const kwBox  = document.getElementById('suggestedKeywords');

        selectedTraits.clear();

        if (!cat || !CATEGORY_TRAITS[cat]) {
            chips.innerHTML = '<span class="text-xs text-slate-400 italic">Select a category above to see suggestions.</span>';
            kwBox.classList.add('hidden');
            updateQuality();
            return;
        }

        chips.innerHTML = CATEGORY_TRAITS[cat].map(t =>
            `<button type="button" class="trait-chip" data-group="trait" data-value="${t}" onclick="toggleChip(this)">${t}</button>`
        ).join('');

        kwList.innerHTML = (CATEGORY_KEYWORDS[cat] || []).map(k =>
            `<button type="button" onclick="addKeyword('${k}')"
                class="text-[11px] px-2.5 py-1 bg-indigo-50 text-indigo-600 border border-indigo-100
                       rounded-full font-semibold hover:bg-indigo-100 transition">
                + ${k}
            </button>`
        ).join('');
        kwBox.classList.remove('hidden');
        updateQuality();
    }

    // ─────────────────────────────────────────────
    // SMART TAG INPUT
    // ─────────────────────────────────────────────
    const tagInput = SmartTagInput.init({
        inputId:     'keywordInput',
        containerId: 'tagContainer',
        errorId:     'tagError',
        accentColor: 'indigo',
    });

    // Quick-add button handler
    function addKeyword(val) {
        tagInput.addSuggested(val);
        updateQuality();
    }

    // Re-evaluate quality whenever a tag pill is added or removed
    new MutationObserver(updateQuality)
        .observe(document.getElementById('tagContainer'), { childList: true });

    // ─────────────────────────────────────────────
    // QUALITY METER
    // color 25 | traits 25 | keywords 30 | photo 20
    // ─────────────────────────────────────────────
    function updateQuality() {
        let score = 0;
        if (selectedColors.size > 0)                      score += 25;
        score += Math.min(selectedTraits.size * 5,        25);
        score += Math.min(tagInput.getTags().length * 10, 30);
        if (document.getElementById('file-upload').files.length > 0) score += 20;

        const bar   = document.getElementById('qualityBar');
        const label = document.getElementById('qualityLabel');
        const hint  = document.getElementById('qualityHint');
        bar.style.width = score + '%';

        const levels = [
            { max: 0,  color: '#cbd5e1', text: 'Not started',            cls: 'text-slate-400',  msg: 'Fill in colors, traits, and keywords to improve match accuracy.' },
            { max: 40, color: '#f97316', text: 'Weak — low match chance', cls: 'text-orange-500', msg: 'Add more traits or keywords. Vague descriptions are harder to match.' },
            { max: 70, color: '#eab308', text: 'Fair — can be improved',  cls: 'text-yellow-500', msg: 'Good start! Add specific keywords (brand, name, serial no.) for better accuracy.' },
            { max: 90, color: '#22c55e', text: 'Good — solid match profile', cls: 'text-green-500', msg: 'Nice! Adding a photo will push this to excellent.' },
            { max: 101,color: '#6366f1', text: 'Excellent — high match chance', cls: 'text-indigo-500', msg: 'Great detail! Your report will have the highest match probability.' },
        ];

        const lvl = levels.find(l => score <= l.max) || levels[levels.length - 1];
        bar.style.backgroundColor = lvl.color;
        label.textContent = lvl.text;
        label.className = `text-[10px] font-black uppercase ${lvl.cls}`;
        hint.textContent = lvl.msg;
    }

    // ─────────────────────────────────────────────
    // PHOTO UPLOAD PREVIEW
    // ─────────────────────────────────────────────
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
            updateQuality();
        }
    });

    // ─────────────────────────────────────────────
    // FORM SUBMIT — validate + compile hidden_marks
    // ─────────────────────────────────────────────
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

        if (!tagInput.validate()) valid = false;

        if (!valid) {
            e.preventDefault();
            document.getElementById('identifyingSection').scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }

        // Compile: "Colors: Black, Blue | Traits: cracked screen | Keywords: samsung, name written inside"
        const parts = [];
        if (selectedColors.size > 0) parts.push('Colors: ' + [...selectedColors].join(', '));
        if (selectedTraits.size > 0) parts.push('Traits: ' + [...selectedTraits].join(', '));
        parts.push(tagInput.getCompiledKeywords());

        document.getElementById('compiledMarks').value = parts.join(' | ');
    });
    </script>
</body>
</html>