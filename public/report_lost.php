<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Lost Item | CMU Lost & Found</title>
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

        /* Custom Dropdown Styling */
        .filter-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            padding-right: 2.5rem;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(229, 231, 235, 0.5);
        }
        input:focus, select:focus, textarea:focus {
            ring: 2px;
            ring-color: #4f46e5;
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
                    <a href="report_lost.php" class="nav-link active py-7 text-sm font-medium">Report Lost</a>
                    <a href="report_found.php" class="hover:text-cmu-gold transition text-sm font-medium">Report Found</a>
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
                <div class="inline-flex items-center justify-center w-12 h-12 bg-indigo-50 text-indigo-600 rounded-xl mb-4">
                    <i class="fas fa-search-plus text-xl"></i>
                </div>
                <h1 class="text-2xl font-bold text-slate-800">Report a Lost Item</h1>
                <p class="text-slate-500 mt-1">Provide details to help our matching engine find your item.</p>
            </header>

            <!-- Duplicate Check Alert (Hidden by default) -->
            <div id="duplicateAlert" class="hidden mb-8 p-4 bg-amber-50 border border-amber-200 rounded-xl flex gap-3 animate-pulse">
                <i class="fas fa-exclamation-triangle text-amber-500 mt-1"></i>
                <div>
                    <h4 class="font-semibold text-amber-800 text-sm">Potential Match Found!</h4>
                    <p class="text-xs text-amber-700 mt-1">We noticed similar items in the <a href="index.php" class="underline font-bold">Public Gallery</a>. Please verify if your item is already turned in before submitting a new report.</p>
                </div>
            </div>

            <form action="../core/process_report.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                <!-- Hidden Field for User Association -->
                <input type="hidden" name="reporter_id" value="<?php echo $user_id; ?>">
                
                <!-- Basic Info Section -->
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
                            <select name="category" id="itemCategory" required class="w-full px-4 py-2.5 rounded-xl border border-slate-200 bg-white outline-none">
                                <option value="">Select category</option>
                                <option value="Electronics">Electronics</option>
                                <option value="Valuables">Valuables (Wallet/Keys)</option>
                                <option value="Documents">Documents/IDs</option>
                                <option value="Books">Books/Stationery</option>
                                <option value="Personal">Personal Items</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1.5 text-slate-700">Date Lost</label>
                            <input type="date" name="date_lost" required 
                                   class="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-1.5 text-slate-700">Location Last Seen</label>
                        <div class="relative">
                            <i class="fas fa-map-marker-alt absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                            <input type="text" name="location" required 
                                   placeholder="e.g. Main Library, 2nd Floor"
                                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 outline-none">
                        </div>
                    </div>
                </section>

                <!-- Private/Verification Section -->
                <section class="space-y-4 pt-4">
                    <div class="flex items-center gap-2 border-b pb-2">
                        <h3 class="text-sm font-bold uppercase tracking-wider text-slate-400">Private Verification Marks</h3>
                        <span class="text-[10px] bg-amber-500 text-white px-2 py-0.5 rounded-full font-bold">REDACTED</span>
                    </div>
                    
                    <p class="text-xs text-slate-500 italic bg-slate-100 p-3 rounded-lg border-l-4 border-indigo-500">
                        <i class="fas fa-user-shield mr-1"></i>
                        <strong>Privacy Guard:</strong> These details are <u>NOT</u> shown in the public gallery. They are used by the OSA Admin to verify your ownership during the claim interview.
                    </p>
                    
                    <div>
                        <label class="block text-sm font-semibold mb-1.5 text-slate-700">Identifying Details</label>
                        <textarea name="hidden_marks" rows="3" required
                                  placeholder="Describe internal contents, scratches, or stickers (e.g. 'Sticker of a cat on the back', 'Contains a P20 bill and my ID')"
                                  class="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none resize-none focus:ring-2 focus:ring-indigo-500 transition-all"></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-1.5 text-slate-700 text-indigo-600">Reference Photo <span class="text-xs font-normal text-slate-400">(Encouraged)</span></label>
                        <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-slate-200 border-dashed rounded-xl hover:border-indigo-400 transition-colors cursor-pointer group relative">
                            <div class="space-y-1 text-center" id="uploadPlaceholder">
                                <i class="fas fa-cloud-upload-alt text-slate-300 text-3xl mb-2 group-hover:text-cmu-blue transition-colors"></i>
                                <div class="flex text-sm text-slate-600">
                                    <label for="file-upload" class="relative cursor-pointer bg-white rounded-md font-medium text-cmu-blue hover:underline">
                                        <span>Click to upload</span>
                                        <input id="file-upload" name="photo" type="file" class="sr-only" accept="image/*">
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
                    <button type="submit" class="w-full bg-cmu-blue hover:bg-slate-800 text-white font-bold py-4 rounded-xl shadow-lg shadow-indigo-200 transition-all transform active:scale-[0.98] flex items-center justify-center gap-2">
                        <i class="fas fa-check-circle"></i>
                        Confirm & Post Report
                    </button>
                    <p class="text-center text-[11px] text-slate-400 mt-6 px-4">
                        By submitting, you acknowledge that providing false information is a violation of the Student Code of Conduct.
                    </p>
                </div>
            </form>
        </div>
    </main>

    <!-- Footer -->
    <?php require_once '../includes/footer.php'; ?>

    <script src="../assets/scripts/profile-dropdown.js">
        // Real-time Duplicate Check Logic
        const titleInput = document.getElementById('itemTitle');
        const alertBox = document.getElementById('duplicateAlert');

        titleInput.addEventListener('input', (e) => {
            const value = e.target.value.toLowerCase();
            const suspiciousKeywords = ['wallet', 'phone', 'calculator', 'bag', 'id', 'keys', 'notebook'];
            const hasMatch = suspiciousKeywords.some(keyword => value.includes(keyword));
            
            if (value.length > 3 && hasMatch) {
                alertBox.classList.remove('hidden');
                alertBox.classList.add('flex');
            } else {
                alertBox.classList.add('hidden');
                alertBox.classList.remove('flex');
            }
        });

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
    </script>
</body>
</html>