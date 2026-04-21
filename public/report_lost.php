<?php
require_once '../core/auth_functions.php';

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$user_id = $_SESSION['user_id'] ?? null;
$stmt->execute([$user_id]);

// ── Prefill from gallery "Claim Item" link ────────────────────────────────
$prefill        = !empty($_GET['prefill']);
$pre_title      = $prefill ? trim($_GET['title']    ?? '') : '';
$pre_category   = $prefill ? trim($_GET['category'] ?? '') : '';
$pre_location   = $prefill ? trim($_GET['location'] ?? '') : '';
$pre_found_id   = $prefill ? (int)($_GET['found_id'] ?? 0) : 0;
// Build a datetime-local value from the date (time defaults to midnight)
$pre_date       = '';

if ($prefill && !empty($_GET['date'])) {
    $ts = strtotime($_GET['date']);
    if ($ts) $pre_date = date('Y-m-d\T00:00', $ts);
}

try {
    $loc_stmt = $pdo->query("SELECT location_id, location_name, building FROM locations WHERE is_active = 1 ORDER BY building, location_name ASC");
    $locations = $loc_stmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($locations as $loc) {
        $grouped[$loc['building']][] = $loc;
    }
} catch (PDOException $e) {
    $locations = [];
}

$pre_location_id = 0;
foreach ($locations as $loc) {
    if (strcasecmp(trim($loc['location_name']), $pre_location) === 0) {
        $pre_location_id = $loc['location_id'];
        break;
    }
}

// Map category name → select option value
$category_options = [
    'Electronics', 'Valuables', 'Documents', 'Books', 'Clothing', 'Personal', 'Other'
];
// "Documents/IDs" stored in DB as "Documents"
$pre_category_val = in_array($pre_category, $category_options) ? $pre_category : '';
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

        <?php if ($prefill && $pre_title): ?>
        <!-- Context banner shown when arriving from a "Claim Item" click -->
        <div class="mb-6 flex items-start gap-4 bg-amber-50 border border-amber-200 rounded-2xl p-5">
            <div class="w-10 h-10 bg-amber-400 text-white rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm">
                <i class="fas fa-hand-holding-heart"></i>
            </div>
            <div>
                <p class="text-sm font-black text-amber-900">Claiming a found item</p>
                <p class="text-xs text-amber-700 mt-1 leading-relaxed">
                    We've pre-filled the public details from the gallery listing for
                    <strong>"<?php echo htmlspecialchars($pre_title); ?>"</strong>.
                    Please complete the <strong>Private Verification Marks</strong> section below —
                    these are the details only the true owner would know, and are used by OSA to verify your claim.
                </p>
                <a href="index.php" class="inline-flex items-center gap-1 text-[11px] font-bold text-amber-700 hover:text-amber-900 mt-2 underline">
                    <i class="fas fa-arrow-left text-[9px]"></i> Back to gallery
                </a>
            </div>
        </div>
        <?php endif; ?>

        <div class="glass-card rounded-2xl shadow-xl p-6 md:p-8">
            <header class="mb-8">
                <div class="inline-flex items-center justify-center w-12 h-12 bg-indigo-50 text-indigo-600 rounded-xl mb-4">
                    <i class="fas fa-search-plus text-xl"></i>
                </div>
                <h1 class="text-2xl font-bold text-slate-800">
                    <?php echo $prefill ? 'Claim This Item' : 'Report a Lost Item'; ?>
                </h1>
                <p class="text-slate-500 mt-1">
                    <?php echo $prefill
                        ? 'Confirm the public details and add your private verification marks so OSA can authenticate your claim.'
                        : 'Provide details to help our matching engine find your item.'; ?>
                </p>
            </header>

            <div id="duplicateAlert" class="hidden mt-2 p-3 rounded-xl border-l-4 border-amber-400 bg-amber-50 text-amber-800 text-xs">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-start gap-2">
                        <i class="fas fa-triangle-exclamation mt-0.5 flex-shrink-0"></i>
                        <div>
                            <p class="font-bold mb-1">Similar reports already exist — is this a duplicate?</p>
                            <div id="duplicateList" class="space-y-1"></div>
                        </div>
                    </div>
                    <button type="button" onclick="document.getElementById('duplicateAlert').classList.add('hidden')"
                            class="text-amber-500 hover:text-amber-700 flex-shrink-0 mt-0.5">
                        <i class="fas fa-times text-xs"></i>
                    </button>
                </div>
            </div>

            <form action="process_report.php" method="POST" enctype="multipart/form-data" class="space-y-6" id="lostItemForm">
                <input type="hidden" name="reporter_id"  value="<?php echo $user_id; ?>">
                <input type="hidden" name="report_type"  value="lost">
                <input type="hidden" name="hidden_marks" id="compiledMarks">
                <?php if ($pre_found_id): ?>
                <input type="hidden" name="linked_found_id" value="<?php echo $pre_found_id; ?>">
                <?php endif; ?>

                <!-- ── PUBLIC INFO ── -->
                <section class="space-y-4">
                    <h3 class="text-sm font-bold uppercase tracking-wider text-slate-400 border-b pb-2 flex justify-between items-center">
                        <span>Public Information</span>
                        <i class="fas fa-globe-asia text-xs"></i>
                    </h3>

                    <div>
                        <label class="block text-sm font-semibold mb-1.5 text-slate-700">Item Name / Title</label>
                        <input type="text" name="title" id="itemTitle" required
                               value="<?php echo htmlspecialchars($pre_title); ?>"
                               placeholder="e.g. Ray-Ban sunglasses, Samsung Galaxy S24, Casio watch..."
                               class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition-all">
                        <p id="aiStatus" class="text-[10px] text-slate-400 mt-1 hidden">
                            <i class="fas fa-wand-magic-sparkles text-purple-400 mr-1"></i>
                            <span id="aiStatusText">Analyzing item for smart suggestions...</span>
                        </p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold mb-1.5 text-slate-700">Category</label>
                            <select name="category" id="itemCategory" required
                                    class="w-full px-4 py-2.5 rounded-xl border border-slate-200 bg-white outline-none"
                                    onchange="onCategoryChange()">
                                <option value="">Select category</option>
                                <?php
                                $cats = [
                                    'Electronics' => 'Electronics',
                                    'Valuables'   => 'Valuables',
                                    'Documents'   => 'Documents/IDs',
                                    'Books'       => 'Books/Stationery',
                                    'Clothing'    => 'Clothing',
                                    'Personal'    => 'Personal Items',
                                    'Other'       => 'Other',
                                ];
                                foreach ($cats as $val => $label):
                                    $sel = ($val === $pre_category_val) ? 'selected' : '';
                                ?>
                                <option value="<?php echo $val; ?>" <?php echo $sel; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1.5 text-slate-700">Date Lost</label>
                            <input type="datetime-local" name="date_lost" required
                                   value="<?php echo htmlspecialchars($pre_date); ?>"
                                   class="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none">
                        </div>
                    </div>


                    <div>
                        <label class="block text-sm font-semibold mb-1.5 text-slate-700">Where was it found?</label>
                            <!-- Room dropdown (hidden until building is selected) -->
                            <select id="buildingSelect" onchange="filterRooms()"
                                    class="w-full px-4 py-2.5 rounded-xl border border-slate-200 bg-white outline-none focus:ring-2 focus:ring-cmu-blue transition">
                                <option value="">Select building</option>
                                <?php foreach ($grouped as $building => $rooms): ?>
                                    <option value="<?php echo htmlspecialchars($building); ?>">
                                        <?php echo htmlspecialchars($building); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <!-- Room dropdown -->
                            <select id="itemLocation" name="location_id" required
                                    class="w-full px-4 py-2.5 rounded-xl border border-slate-200 bg-white outline-none focus:ring-2 focus:ring-cmu-blue transition hidden mt-2">
                                <option value="">Select room / area</option>
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
                        <div class="flex flex-wrap gap-2" id="colorChips"></div>
                        <p id="colorError" class="text-xs text-red-500 mt-1 hidden">Please select at least one color.</p>
                    </div>

                    <!-- ❷ TRAITS -->
                    <div id="traitSection">
                        <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">
                            <i class="fas fa-fingerprint mr-1 text-indigo-400"></i> Distinguishing Traits
                            <span class="text-slate-400 text-[10px] normal-case font-normal ml-1">(select all that apply)</span>
                        </label>
                        <div id="aiTraitBadge" class="hidden mb-2 inline-flex items-center gap-1.5 px-2 py-0.5 bg-purple-50 border border-purple-100 rounded-full">
                            <i class="fas fa-wand-magic-sparkles text-purple-400 text-[9px]"></i>
                            <span class="text-[10px] font-bold text-purple-600">AI-suggested traits added below</span>
                        </div>
                        <div class="flex flex-wrap gap-2" id="traitChips">
                            <span class="text-xs text-slate-400 italic">Select a category and enter a title to see suggestions.</span>
                        </div>
                    </div>

                    <!-- ❸ KEYWORD TAGS -->
                    <div>
                        <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-1">
                            <i class="fas fa-tags mr-1 text-indigo-400"></i> Specific Keywords
                            <span class="text-slate-400 text-[10px] normal-case font-normal ml-1">(brand, name written, serial no., etc.)</span>
                        </label>
                        <p class="text-[11px] text-slate-400 mb-2">
                            Type a keyword and press
                            <kbd class="bg-slate-100 border border-slate-200 rounded px-1 text-[10px]">Enter</kbd> or
                            <kbd class="bg-slate-100 border border-slate-200 rounded px-1 text-[10px]">,</kbd>
                            to add it.
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

                    <!-- ❹ EXACT SPOT (OPTIONAL) -->
                    <div>
                        <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-1">
                            <i class="fas fa-map-pin mr-1 text-indigo-400"></i> Exact Spot Lost
                            <span class="text-slate-400 text-[10px] normal-case font-normal ml-1">(optional — where you think you lost it)</span>
                        </label>
                        <input type="text" name="exact_spot" id="exactSpot"
                            placeholder="e.g. Left side bench near the entrance, Table 3 second floor canteen..."
                            class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-indigo-500 outline-none transition-all text-sm">
                        <p class="text-[10px] text-slate-400 mt-1">Kept private — helps the matching engine narrow down location signals.</p>
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
                        <div id="dropZone" class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-slate-200 border-dashed rounded-xl hover:border-indigo-400 transition-all cursor-pointer group relative min-h-[140px]">
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
                            <div id="attachedStatus" class="hidden absolute inset-0 bg-white/90 flex flex-col items-center justify-center p-4 text-center
                            z-10">
                                <div class="w-12 h-12 bg-green-100 text-green-600 rounded-full flex items-center justify-center mb-2">
                                    <i class="fas fa-check"></i>
                                </div>
                                <p class="text-sm font-bold text-slate-800" id="fileNameDisplay">image.jpg</p>
                                <p class="text-xs text-slate-500">File attached successfully</p>
                                <button type="button" onclick="clearPreview()" class="mt-3 text-xs text-red-500 font-semibold hover:underline cursor-pointer relative z-30">Change photo</button>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="pt-6">
                    <button type="submit" id="submitBtn"
                            class="w-full bg-cmu-blue hover:bg-slate-800 text-white font-bold py-4 rounded-xl shadow-lg transition-all transform active:scale-[0.98] flex items-center justify-center gap-2">
                        <i class="fas fa-check-circle"></i>
                        <?php echo $prefill ? 'Submit Claim Report' : 'Confirm & Post Report'; ?>
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
    <script src="../assets/scripts/report_lost_init.js"></script>
    <?php if ($prefill && $pre_category_val): ?>
    <script>
        // Trigger the category change handler after the page loads so traits/keywords populate
        document.addEventListener('DOMContentLoaded', function () {
            const catSelect = document.getElementById('itemCategory');
            if (catSelect.value) onCategoryChange();
        });

        const locationData = <?php echo json_encode($grouped); ?>;

        function filterRooms() {
            const building   = document.getElementById('buildingSelect').value;
            const roomSelect = document.getElementById('itemLocation');

            roomSelect.innerHTML = '<option value="">Select room / area</option>';

            if (!building) {
                roomSelect.classList.add('hidden');
                return;
            }

            const rooms = locationData[building] || [];
            rooms.forEach(room => {
                const opt = document.createElement('option');
                opt.value       = room.location_id;
                opt.textContent = room.location_name;
                roomSelect.appendChild(opt);
            });

            roomSelect.classList.remove('hidden');
        }

        <?php if ($pre_location_id): ?>
        document.addEventListener('DOMContentLoaded', function () {
            for (const [building, rooms] of Object.entries(locationData)) {
                if (rooms.some(r => r.location_id == <?php echo $pre_location_id; ?>)) {
                    document.getElementById('buildingSelect').value = building;
                    filterRooms();
                    document.getElementById('itemLocation').value = '<?php echo $pre_location_id; ?>';
                    break;
                }
            }
        });
        <?php endif; ?>

        <?php if ($prefill && $pre_category_val): ?>
        document.addEventListener('DOMContentLoaded', function () {
            const catSelect = document.getElementById('itemCategory');
            if (catSelect.value) onCategoryChange();
        });
        <?php endif; ?>
    </script>
    <?php endif; ?>
</body>
</html>