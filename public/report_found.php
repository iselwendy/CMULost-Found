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
        :root {
            --cmu-blue: #003366;
            --cmu-gold: #FFCC00;
        }
        .bg-cmu-blue { background-color: var(--cmu-blue); }
        .text-cmu-blue { color: var(--cmu-blue); }
        .border-cmu-blue { border-color: var(--cmu-blue); }
        .bg-cmu-gold { background-color: var(--cmu-gold); }
        
        .nav-link.active {
            border-bottom: 3px solid var(--cmu-gold);
            color: white;
        }

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
    <link rel="stylesheet" href="../assets/styles/profile-dropdown.css"></link>
</head>
<body class="bg-slate-50 min-h-screen text-slate-900">

    <!-- Navigation -->
    <nav class="bg-cmu-blue text-white shadow-md sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-20">
                <div class="flex items-center space-x-3">
                    <img src="../assets/images/system-icon.png" alt="Logo" class="h-12 w-12" onerror="this.src='https://ui-avatars.com/api/?name=LF&background=FFCC00&color=003366'">
                    <span class="font-bold text-xl tracking-tight hidden sm:block">CMU Lost & Found</span>
                </div>
                
                <div class="hidden md:flex items-center space-x-8">
                    <a href="index.php" class="hover:text-cmu-gold transition text-sm font-medium">Public Gallery</a>
                    <a href="report_lost.php" class="hover:text-cmu-gold transition text-sm font-medium">Report Lost</a>
                    <a href="report_found.php" class="nav-link active py-7 text-sm font-medium text-cmu-gold">Report Found</a>
                    <a href="../dashboard/my_reports.php" class="hover:text-cmu-gold transition text-sm font-medium">My Dashboard</a>
                </div>

                <!-- User Profile & Dropdown -->
                <?php require_once '../includes/profile-dropdown.php'; ?>
            </div>
        </div>
    </nav>

    <main class="max-w-2xl mx-auto px-4 py-8">
        <div class="glass-card rounded-2xl shadow-xl p-6 md:p-8">
            <header class="mb-8">
                <div class="inline-flex items-center justify-center w-12 h-12 bg-green-50 text-green-600 rounded-xl mb-4">
                    <i class="fas fa-hand-holding-heart text-xl"></i>
                </div>
                <h1 class="text-2xl font-bold text-slate-800">Report a Found Item</h1>
                <p class="text-slate-500 mt-1">Thank you for being a responsible CMU student! Your report helps return items to their rightful owners.</p>
            </header>

            <form id="foundItemForm" action="../core/process_found.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                <!-- Status Tag -->
                <input type="hidden" name="status" value="Pending Turnover">
                
                <!-- Step 1: Physical Discovery -->
                <section class="space-y-4">
                    <h3 class="text-sm font-bold uppercase tracking-wider text-slate-400 border-b pb-2 flex justify-between items-center">
                        <span>General Discovery Details</span>
                        <i class="fas fa-eye text-xs"></i>
                    </h3>
                    
                    <div>
                        <label class="block text-sm font-semibold mb-1.5 text-slate-700">What did you find?</label>
                        <input type="text" name="title" required 
                               placeholder="e.g. Silver Keychain with 3 keys"
                               class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-cmu-blue transition-all">
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
                            <input type="date" name="date_found" required 
                                   class="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none">
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

                    <div>
                        <label class="block text-sm font-semibold mb-1.5 text-slate-700">Public Description</label>
                        <textarea name="description" rows="3" required
                                  placeholder="Provide a general description that helps the owner recognize it. (e.g. 'Blue AquaFlask with some anime stickers')"
                                  class="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none resize-none focus:border-cmu-blue transition-all"></textarea>
                        <p class="text-[10px] text-slate-400 mt-1 uppercase tracking-tight">This description will be visible in the Public Gallery.</p>
                    </div>
                </section>

                <!-- Step 2: Verification Security -->
                <section class="space-y-4 pt-4">
                    <div class="flex items-center gap-2 border-b pb-2">
                        <h3 class="text-sm font-bold uppercase tracking-wider text-slate-400">Security & Privacy</h3>
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
                        <textarea name="confidential_mark" rows="3" required
                                  placeholder="e.g. 'The ID inside says Juan Dela Cruz', 'The phone lockscreen is a picture of a dog'"
                                  class="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none resize-none focus:border-cmu-blue transition-all"></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-1.5 text-slate-700 text-indigo-600">Reference Photo <span class="text-xs font-normal text-slate-400">(Required)</span></label>
                        <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-slate-200 border-dashed rounded-xl hover:border-cmu-blue transition-colors cursor-pointer group relative">
                            <div class="space-y-1 text-center" id="uploadPlaceholder">
                                <i class="fas fa-cloud-upload-alt text-slate-300 text-3xl mb-2 group-hover:text-cmu-blue transition-colors"></i>
                                <div class="flex text-sm text-slate-600">
                                    <label for="file-upload" class="relative cursor-pointer bg-white rounded-md font-medium text-cmu-blue hover:underline">
                                        <span>Click to upload</span>
                                        <input id="file-upload" name="photo" type="file" class="sr-only" accept="image/*" required>
                                    </label>
                                    <p class="pl-1 text-slate-400">or drag and drop</p>
                                </div>
                                <p class="text-[10px] text-slate-400 uppercase tracking-widest">PNG, JPG up to 5MB</p>
                            </div>
                            <!-- Image Preview Container -->
                            <div id="imagePreview" class="hidden absolute inset-0 bg-white p-2 rounded-xl">
                                <img src="" alt="Preview" class="w-full h-full object-contain rounded-lg">
                                <button type="button" onclick="clearPreview()" class="absolute top-4 right-4 bg-red-500 text-white w-8 h-8 rounded-full shadow-lg">
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

    <script>
        // Image Preview Logic
        const fileUpload = document.getElementById('file-upload');
        const previewContainer = document.getElementById('imagePreview');
        const placeholder = document.getElementById('uploadPlaceholder');
        const previewImg = previewContainer.querySelector('img');

        fileUpload.onchange = function() {
            const [file] = this.files;
            if (file) {
                previewImg.src = URL.createObjectURL(file);
                previewContainer.classList.remove('hidden');
                placeholder.classList.add('opacity-0');
            }
        };

        function clearPreview() {
            fileUpload.value = '';
            previewContainer.classList.add('hidden');
            placeholder.classList.remove('opacity-0');
        }

        // Handle Form Submission Mock (Showing Modal)
        const form = document.getElementById('foundItemForm');
        const modal = document.getElementById('successModal');
        const modalContent = document.getElementById('modalContent');

        form.onsubmit = function(e) {
            e.preventDefault(); // Prevent actual submission for demo purposes
            
            // Show Modal
            modal.classList.remove('hidden');
            setTimeout(() => {
                modalContent.classList.remove('scale-95', 'opacity-0');
                modalContent.classList.add('scale-100', 'opacity-100');
            }, 10);
        };
    </script>
    <script src="../assets/scripts/profile-dropdown.js"></script>
</body>
</html>