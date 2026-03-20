<?php
require_once '../core/auth_functions.php';

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$user_id = $_SESSION['user_id'] ?? null; // Get user ID from session
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
    <style>
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(229, 231, 235, 0.5);
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            ring: 2px;
            border-color: #003366;
        }
    </style>
    <link rel="stylesheet" href="../assets/styles/header.css"></link>
    <link rel="stylesheet" href="../assets/styles/root.css"></link>
</head>
<body class="bg-slate-50 min-h-screen text-slate-900">

    <!-- Navbar -->
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
                <!-- Hidden Field for User Association -->
                <input type="hidden" name="reporter_id" value="<?php echo $user_id; ?>">
                <input type="hidden" name="report_type" value="found">


                <!-- Step 1: Physical Discovery -->
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
                            <select name="category" required class="w-full px-4 py-2.5 rounded-xl border border-slate-200 bg-white outline-none">
                                <option value="">Select category</option>
                                <option value="Electronics">Electronics</option>
                                <option value="Valuables">Valuables (Wallet/Keys)</option>
                                <option value="Documents">Documents/IDs</option>
                                <option value="Books">Books/Stationery</option>
                                <option value="Personal">Personal Items</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1.5 text-slate-700">Date Found</label>
                            <input type="datetime-local" name="date_found" required class="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none">

                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-1.5 text-slate-700">Where was it found?</label>
                        <div class="relative">
                            <i class="fas fa-location-dot absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                            <input type="text" name="location" required 
                                   placeholder="e.g. Admin Bldg, Ground Floor Bench"
                                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 outline-none">
                        </div>
                    </div>
                </section>

                <!-- Step 2: Verification Security -->
                <section class="space-y-4 pt-4">
                    <div class="flex items-center gap-2 border-b pb-2">
                        <h3 class="text-sm font-bold uppercase tracking-wider text-slate-400">Private Verification Marks</h3>
                        <span class="text-[10px] bg-amber-500 text-white px-2 py-0.5 rounded-full font-bold">REDACTED FROM GALLERY</span>
                    </div>

                    <p class="text-xs text-slate-500 italic bg-slate-100 p-3 rounded-lg border-l-4 border-indigo-500">
                        <i class="fas fa-user-shield mr-1"></i>
                        <strong>Guardian Protocol:</strong> Please describe a <strong>specific identifying mark that only the owner would know</strong> (e.g., a specific serial number, a name written inside, or a unique scratch).
                        <br><br>
                        <strong>This field is hidden from the public to prevent fraudulent claims.</strong>
                    </p>

                    <div>
                        <label class="block text-sm font-semibold mb-1.5 text-slate-700">Identifying Details</label>
                        <textarea name="hidden_marks" rows="3" required
                                  placeholder="e.g. 'The ID inside says Juan Dela Cruz', 'The phone lockscreen is a picture of a dog'"
                                  class="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none resize-none focus:border-cmu-blue transition-all"></textarea>
                    </div>

                    <!-- Enhanced Upload Box -->
                    <div>
                        <label class="block text-sm font-semibold mb-1.5 text-slate-700 text-indigo-600">Reference Photo</label>
                        <div id="dropZone" class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-slate-200 border-dashed rounded-xl hover:border-indigo-400 transition-all cursor-pointer group relative overflow-hidden">
                            
                            <!-- State 1: Empty / Placeholder -->
                            <div class="space-y-1 text-center transition-opacity duration-300" id="uploadPlaceholder">
                                <i class="fas fa-cloud-upload-alt text-slate-300 text-3xl mb-2 group-hover:text-indigo-500 transition-colors"></i>
                                <div class="flex text-sm text-slate-600">
                                    <label class="relative cursor-pointer bg-white rounded-md font-medium text-indigo-600 hover:underline">
                                        <span>Click to upload</span>
                                        <input id="file-upload" name="photo" type="file" class="sr-only" accept="image/*">
                                    </label>
                                    <p class="pl-1 text-slate-400">or drag and drop</p>
                                </div>
                                <p class="text-[10px] text-slate-400 uppercase tracking-widest">PNG, JPG up to 5MB</p>
                            </div>

                            <!-- State 2: Attached (Hidden by default) -->
                            <div id="attachedStatus" class="hidden absolute inset-0 bg-white/90 flex flex-col items-center justify-center p-4 text-center z-10 animate-in fade-in duration-300">
                                <div class="w-12 h-12 bg-green-100 text-green-600 rounded-full flex items-center justify-center mb-2">
                                    <i class="fas fa-check"></i>
                                </div>
                                <p class="text-sm font-bold text-slate-800" id="fileNameDisplay">image.jpg</p>
                                <p class="text-xs text-slate-500">File attached successfully</p>
                                <button type="button" onclick="clearPreview()" class="mt-3 text-xs text-red-500 font-semibold hover:underline">
                                    Change photo
                                </button>
                            </div>

                            <!-- Image Preview Layer (Optional visual) -->
                            <div id="imagePreview" class="hidden absolute inset-0 bg-white p-2">
                                <img src="" alt="Preview" class="w-full h-full object-contain rounded-lg">
                                <button type="button" onclick="clearPreview()" class="absolute top-2 right-2 bg-red-500 text-white w-6 h-6 rounded-full shadow-lg flex items-center justify-center text-xs">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="pt-6">
                    <button type="submit" class="w-full bg-cmu-blue hover:bg-slate-800 text-white font-bold py-4 rounded-xl shadow-lg transition-all transform active:scale-[0.98] flex items-center justify-center gap-2">
                        <i class="fas fa-paper-plane"></i>
                        Register Found Item
                    </button>
                </div>
            </form>
        </div>
    </main>

    <!-- Success Modal (Instructions for Turnover) -->
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

    <!-- Footer -->
    <?php require_once '../includes/footer.php'; ?>

    <script src="../assets/scripts/profile-dropdown.js"></script>
    <script src="../assets/scripts/item_image_upload.js"></script>
</body>
</html>