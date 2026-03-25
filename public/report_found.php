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
    <title>Report Found Item | CMU Lost & Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="../assets/images/system-icon.png">
    <link rel="stylesheet" href="../assets/styles/header.css">
    <link rel="stylesheet" href="../assets/styles/root.css">
    <link rel="stylesheet" href="../assets/styles/report_found.css">
</head>
<body class="bg-slate-50 min-h-screen text-slate-900">

    <?php require_once '../includes/header.php'; ?>

    <main class="max-w-2xl mx-auto px-4 py-8">
        <div class="glass-card rounded-2xl shadow-xl p-6 md:p-8">
            <header class="mb-8">
                <div class="inline-flex items-center justify-center w-12 h-12 bg-green-50 text-green-600 rounded-xl mb-4">
                    <i class="fas fa-hand-holding-heart text-xl"></i>
                </div>
                <h1 class="text-2xl font-bold text-slate-800">Report a Found Item</h1>
                <p class="text-slate-500 mt-1">Thank you for being a responsible CMU student! Your report helps return items to their rightful owners.</p>
            </header>

            <form id="foundItemForm" action="process_report.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                <input type="hidden" name="reporter_id" value="<?php echo $user_id; ?>">
                <input type="hidden" name="report_type" value="found">
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
                               placeholder="e.g. Ray-Ban sunglasses, Samsung Galaxy S24, Casio watch..."
                               class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-green-400 focus:border-green-400 outline-none transition-all">
                        <p id="aiStatus" class="text-[10px] text-slate-400 mt-1 hidden">
                            <i class="fas fa-wand-magic-sparkles text-teal-400 mr-1"></i>
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
                                <option value="Electronics">Electronics</option>
                                <option value="Valuables">Valuables</option>
                                <option value="Documents">Documents/IDs</option>
                                <option value="Books">Books/Stationery</option>
                                <option value="Clothing">Clothing</option>
                                <option value="Personal">Personal Items</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1.5 text-slate-700">Date Found</label>
                            <input type="datetime-local" name="date_found" required
                                   class="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-1.5 text-slate-700">Where was it found?</label>
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

                <!-- ── GUARDIAN PROTOCOL ── -->
                <section class="space-y-5 pt-4" id="identifyingSection">
                    <div class="flex items-center gap-2 border-b pb-2">
                        <h3 class="text-sm font-bold uppercase tracking-wider text-slate-400">Guardian Protocol</h3>
                        <span class="text-[10px] bg-amber-500 text-white px-2 py-0.5 rounded-full font-bold">REDACTED FROM GALLERY</span>
                    </div>

                    <p class="text-xs text-slate-500 italic bg-slate-100 p-3 rounded-lg border-l-4 border-green-500">
                        <i class="fas fa-user-shield mr-1"></i>
                        <strong>Guardian Protocol:</strong> Describe what you observed about the item.
                        These details are <u>NOT</u> shown publicly — only OSA admins can see them.
                        They are used to <strong>verify the true owner</strong> and to power the <strong>matching engine</strong>.
                    </p>

                    <!-- ❶ COLOR -->
                    <div>
                        <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">
                            <i class="fas fa-palette mr-1 text-green-500"></i> Primary Color(s) of the Item
                            <span class="text-red-400 ml-1">*</span>
                        </label>
                        <div class="flex flex-wrap gap-2" id="colorChips">
                            <!-- Populated from vocabulary.json -->
                        </div>
                        <p id="colorError" class="text-xs text-red-500 mt-1 hidden">Please select at least one color.</p>
                    </div>

                    <!-- ❷ TRAITS -->
                    <div id="traitSection">
                        <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">
                            <i class="fas fa-fingerprint mr-1 text-green-500"></i> Observed Traits
                            <span class="text-slate-400 text-[10px] normal-case font-normal ml-1">(select all that you noticed)</span>
                        </label>
                        <div id="aiTraitBadge" class="hidden mb-2 inline-flex items-center gap-1.5 px-2 py-0.5 bg-teal-50 border border-teal-100 rounded-full">
                            <i class="fas fa-wand-magic-sparkles text-teal-400 text-[9px]"></i>
                            <span class="text-[10px] font-bold text-teal-600">AI-suggested traits added below</span>
                        </div>
                        <div class="flex flex-wrap gap-2" id="traitChips">
                            <span class="text-xs text-slate-400 italic">Select a category and enter a title to see suggestions.</span>
                        </div>
                    </div>

                    <!-- ❸ KEYWORD TAGS -->
                    <div>
                        <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-1">
                            <i class="fas fa-tags mr-1 text-green-500"></i> Specific Observations
                            <span class="text-slate-400 text-[10px] normal-case font-normal ml-1">(name on item, brand, visible text, serial no., etc.)</span>
                        </label>
                        <p class="text-[11px] text-slate-400 mb-2">
                            Type an observation and press
                            <kbd class="bg-slate-100 border border-slate-200 rounded px-1 text-[10px]">Enter</kbd> or
                            <kbd class="bg-slate-100 border border-slate-200 rounded px-1 text-[10px]">,</kbd>
                            to add it. Autocomplete suggests standard terms as you type.
                        </p>
                        <div id="tagContainer"
                             class="min-h-[44px] w-full px-3 py-2 rounded-xl border border-slate-200 bg-white flex flex-wrap gap-1.5 items-center cursor-text transition-all">
                            <input type="text" id="keywordInput"
                                   placeholder='e.g. "Juan Dela Cruz" written inside, dog lockscreen, CMU ID...'
                                   class="flex-1 min-w-[160px] outline-none text-sm text-slate-700 bg-transparent py-0.5"
                                   autocomplete="off">
                        </div>
                        <div id="tagError" class="text-xs text-red-500 mt-1 hidden">Please add at least one specific observation.</div>

                        <div id="suggestedKeywords" class="mt-2 hidden">
                            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider mb-1.5">Quick add:</p>
                            <div id="suggestedKeywordList" class="flex flex-wrap gap-1.5"></div>
                        </div>
                    </div>

                    <!-- ❹ EXACT SPOT -->
                    <div>
                        <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-1">
                            <i class="fas fa-map-pin mr-1 text-green-500"></i> Exact Spot Found
                            <span class="text-slate-400 text-[10px] normal-case font-normal ml-1">(be as specific as possible)</span>
                        </label>
                        <input type="text" name="exact_spot" id="exactSpot"
                               placeholder="e.g. Left side bench near the entrance, Table 3 second floor..."
                               class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-green-400 outline-none transition-all text-sm">
                        <p class="text-[10px] text-slate-400 mt-1">Kept private — a strong ownership signal for verification.</p>
                    </div>

                    <!-- ❺ QUALITY METER -->
                    <div id="qualityMeter" class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                        <div class="flex items-center justify-between mb-1.5">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Match Quality</p>
                            <span id="qualityLabel" class="text-[10px] font-black text-slate-400 uppercase">Not started</span>
                        </div>
                        <div class="h-1.5 bg-slate-200 rounded-full overflow-hidden">
                            <div id="qualityBar" class="h-full rounded-full bg-slate-300" style="width: 0%"></div>
                        </div>
                        <p id="qualityHint" class="text-[11px] text-slate-400 mt-1.5 italic">Fill in colors, traits, and observations to help find the owner faster.</p>
                    </div>

                    <!-- ❻ PHOTO -->
                    <div>
                        <label class="block text-sm font-semibold mb-1.5 text-slate-700">
                            Reference Photo <span class="text-xs font-normal text-slate-400">(Required)</span>
                        </label>
                        <div id="dropZone" class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-slate-200 border-dashed rounded-xl hover:border-green-400 transition-all cursor-pointer group relative overflow-hidden">
                            <div class="space-y-1 text-center" id="uploadPlaceholder">
                                <i class="fas fa-cloud-upload-alt text-slate-300 text-3xl mb-2 group-hover:text-green-500 transition-colors"></i>
                                <div class="flex text-sm text-slate-600 justify-center">
                                    <label class="relative cursor-pointer bg-white rounded-md font-medium text-green-600 hover:underline">
                                        <span>Click to upload</span>
                                        <input id="file-upload" name="photo" type="file" class="sr-only" accept="image/*" required>
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
                                <p class="text-xs text-slate-500">Photo attached successfully</p>
                                <button type="button" onclick="clearPreview()" class="mt-3 text-xs text-red-500 font-semibold hover:underline">Change photo</button>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="pt-6">
                    <button type="submit"
                            class="w-full bg-cmu-blue hover:bg-slate-800 text-white font-bold py-4 rounded-xl shadow-lg transition-all transform active:scale-[0.98] flex items-center justify-center gap-2">
                        <i class="fas fa-paper-plane"></i>
                        Register Found Item
                    </button>
                </div>
            </form>
        </div>
    </main>

    <!-- Success Modal -->
    <div id="successModal" class="fixed inset-0 z-[60] hidden flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
        <div class="bg-white rounded-3xl max-w-md w-full p-8 shadow-2xl transform transition-all scale-95 opacity-0 duration-300" id="modalContent">
            <div class="text-center">
                <div class="w-20 h-20 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-check text-4xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-slate-800 mb-2">Report Submitted!</h2>
                <p class="text-slate-500 mb-6">Your report is now marked as <strong>"Pending Turnover."</strong></p>
                <div class="bg-slate-50 border border-slate-200 rounded-2xl p-6 mb-6 text-left space-y-4">
                    <h4 class="font-bold text-cmu-blue text-sm uppercase tracking-wider text-center">Next Steps</h4>
                    <div class="flex gap-4">
                        <div class="flex-shrink-0 w-8 h-8 bg-cmu-gold text-cmu-blue font-bold rounded-full flex items-center justify-center text-sm">1</div>
                        <p class="text-xs text-slate-600">Bring the item to the <strong>Office of Student Affairs (OSA)</strong> within 24 hours.</p>
                    </div>
                    <div class="flex gap-4">
                        <div class="flex-shrink-0 w-8 h-8 bg-cmu-gold text-cmu-blue font-bold rounded-full flex items-center justify-center text-sm">2</div>
                        <p class="text-xs text-slate-600">Present your <strong>Turnover QR Code</strong> found in your dashboard to the Admin.</p>
                    </div>
                </div>
                <a href="../dashboard/my_reports.php" class="block w-full bg-cmu-blue text-white font-bold py-4 rounded-xl hover:bg-slate-800 transition-colors">
                    View My Dashboard
                </a>
            </div>
        </div>
    </div>

    <?php require_once '../includes/footer.php'; ?>

    <script src="../assets/scripts/profile-dropdown.js"></script>
    <script src="../assets/scripts/item_image_upload.js"></script>
    <script src="../assets/scripts/smart_tag_input.js"></script>
    <script src="../assets/scripts/report_found_init.js"></script>
</body>
</html>