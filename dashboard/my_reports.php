<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reports | CMU Lost & Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
        
        .tab-active {
            border-bottom: 3px solid var(--cmu-blue);
            color: var(--cmu-blue);
            font-weight: 700;
        }

        .status-badge {
            font-size: 10px;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 99px;
            text-transform: uppercase;
        }
    </style>
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
                    <a href="../public/index.php" class="hover:text-cmu-gold transition text-sm font-medium">Public Gallery</a>
                    <a href="../public/report_lost.php" class="hover:text-cmu-gold transition text-sm font-medium">Report Lost</a>
                    <a href="../public/report_found.php" class="hover:text-cmu-gold transition text-sm font-medium">Report Found</a>
                    <a href="my_reports.php" class="nav-link active text-cmu-gold py-7 text-sm font-medium border-b-4 border-cmu-gold">My Dashboard</a>
                </div>

                <div class="flex items-center space-x-4">
                    <div class="text-right hidden sm:block">
                        <p class="text-xs text-blue-200">Logged in as</p>
                        <p class="text-sm font-semibold">Abdul Montefalco</p>
                    </div>
                    <button class="w-10 h-10 bg-white/10 rounded-full flex items-center justify-center hover:bg-white/20">
                        <i class="fas fa-user-circle text-xl"></i>
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <main class="max-w-6xl mx-auto px-4 py-8">
        <!-- Dashboard Header -->
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-8">
            <div>
                <h1 class="text-3xl font-extrabold text-slate-800 tracking-tight">Personal Dashboard</h1>
                <p class="text-slate-500">Track your reports, view matches, and manage turnovers.</p>
            </div>
            <div class="flex gap-2">
                <a href="../report_lost.php" class="px-4 py-2 bg-white border border-slate-200 rounded-lg text-sm font-bold text-slate-700 hover:bg-slate-50 transition shadow-sm">
                    <i class="fas fa-plus mr-2 text-indigo-500"></i>New Lost Report
                </a>
                <a href="../report_found.php" class="px-4 py-2 bg-cmu-blue text-white rounded-lg text-sm font-bold hover:bg-slate-800 transition shadow-md">
                    <i class="fas fa-hand-holding-heart mr-2 text-cmu-gold"></i>I Found Something
                </a>
            </div>
        </div>

        <!-- Quick Stats / QR Quick Access -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-10">
            <!-- Summary Card -->
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 flex items-center gap-5">
                <div class="w-14 h-14 bg-blue-50 text-cmu-blue rounded-2xl flex items-center justify-center text-2xl">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div>
                    <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">Active Reports</p>
                    <h3 class="text-2xl font-black text-slate-800">03</h3>
                </div>
            </div>

            <!-- Match Alert Card -->
            <div class="bg-indigo-600 p-6 rounded-2xl shadow-lg shadow-indigo-100 flex items-center gap-5 text-white relative overflow-hidden">
                <div class="w-14 h-14 bg-white/20 text-white rounded-2xl flex items-center justify-center text-2xl">
                    <i class="fas fa-bolt-lightning"></i>
                </div>
                <div class="z-10">
                    <p class="text-xs font-bold text-indigo-200 uppercase tracking-widest">Potential Matches</p>
                    <h3 class="text-2xl font-black">02 Found</h3>
                </div>
                <i class="fas fa-magnifying-glass absolute -right-4 -bottom-4 text-white/10 text-8xl"></i>
            </div>

            <!-- QR Quick Access -->
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 flex items-center justify-between">
                <div class="flex items-center gap-5">
                    <div class="w-14 h-14 bg-amber-50 text-amber-600 rounded-2xl flex items-center justify-center text-2xl">
                        <i class="fas fa-qrcode"></i>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">Turnover QR</p>
                        <h3 class="text-sm font-bold text-slate-800">Pending Surrender</h3>
                    </div>
                </div>
                <button onclick="openQRModal()" class="text-cmu-blue hover:text-blue-800 font-bold text-sm underline">View Code</button>
            </div>
        </div>

        <!-- Main Content Tabs -->
        <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="flex border-b border-slate-100 bg-slate-50/50">
                <button onclick="switchTab('my-reports')" id="tab-my-reports" class="px-8 py-5 text-sm font-bold transition-all tab-active">
                    My Reports (3)
                </button>
                <button onclick="switchTab('potential-matches')" id="tab-potential-matches" class="px-8 py-5 text-sm font-bold text-slate-400 hover:text-slate-600 transition-all border-b-3 border-transparent">
                    Potential Matches <span class="ml-2 bg-red-500 text-white text-[10px] px-2 py-0.5 rounded-full">2</span>
                </button>
            </div>

            <div class="p-6">
                <!-- My Reports List -->
                <div id="content-my-reports" class="space-y-4">
                    <!-- Item 1: Found but Pending -->
                    <div class="group flex flex-col md:flex-row items-center gap-6 p-4 rounded-2xl border border-slate-100 hover:border-cmu-blue/30 hover:bg-slate-50 transition-all">
                        <div class="w-full md:w-32 h-32 rounded-xl bg-slate-200 overflow-hidden flex-shrink-0 relative">
                            <img src="https://images.unsplash.com/photo-1544947950-fa07a98d237f?auto=format&fit=crop&w=300&q=80" alt="Item" class="w-full h-full object-cover">
                            <span class="absolute top-2 left-2 status-badge bg-green-500 text-white">Found</span>
                        </div>
                        <div class="flex-grow space-y-1">
                            <div class="flex justify-between items-start">
                                <h4 class="text-lg font-bold text-slate-800">Calculus 1 Textbook</h4>
                                <span class="status-badge bg-amber-100 text-amber-700 border border-amber-200">Pending Turnover</span>
                            </div>
                            <p class="text-xs text-slate-500 flex items-center gap-2">
                                <i class="fas fa-calendar"></i> Found on Oct 23, 2023 
                                <span class="mx-2">•</span>
                                <i class="fas fa-map-marker-alt"></i> Innovation Building
                            </p>
                            <p class="text-sm text-slate-600 mt-2 line-clamp-1 italic">"Contains a bookmark on page 42..."</p>
                            
                            <!-- Progress Tracker -->
                            <div class="mt-4 flex items-center gap-2">
                                <div class="flex-grow h-1.5 bg-slate-200 rounded-full overflow-hidden flex">
                                    <div class="w-1/3 bg-cmu-gold h-full"></div>
                                    <div class="w-2/3 bg-slate-200 h-full"></div>
                                </div>
                                <span class="text-[10px] font-bold text-slate-400 uppercase">Step 1 of 3</span>
                            </div>
                        </div>
                        <div class="flex md:flex-col gap-2 w-full md:w-auto">
                            <button onclick="openQRModal()" class="flex-1 px-4 py-2 bg-cmu-blue text-white rounded-lg text-xs font-bold hover:bg-slate-800 transition">Get QR Code</button>
                            <button class="flex-1 px-4 py-2 border border-slate-200 text-slate-600 rounded-lg text-xs font-bold hover:bg-white transition">Edit</button>
                        </div>
                    </div>

                    <!-- Item 2: Lost Report -->
                    <div class="group flex flex-col md:flex-row items-center gap-6 p-4 rounded-2xl border border-slate-100 hover:border-cmu-blue/30 hover:bg-slate-50 transition-all">
                        <div class="w-full md:w-32 h-32 rounded-xl bg-slate-200 overflow-hidden flex-shrink-0 relative">
                            <img src="https://images.unsplash.com/photo-1631201553018-86851b43950a?auto=format&fit=crop&w=300&q=80" alt="Item" class="w-full h-full object-cover">
                            <span class="absolute top-2 left-2 status-badge bg-red-500 text-white">Lost</span>
                        </div>
                        <div class="flex-grow space-y-1">
                            <div class="flex justify-between items-start">
                                <h4 class="text-lg font-bold text-slate-800">Blue AquaFlask Water Bottle</h4>
                                <span class="status-badge bg-blue-100 text-blue-700 border border-blue-200">Active Search</span>
                            </div>
                            <p class="text-xs text-slate-500 flex items-center gap-2">
                                <i class="fas fa-calendar"></i> Lost on Oct 22, 2023 
                                <span class="mx-2">•</span>
                                <i class="fas fa-map-marker-alt"></i> University Canteen
                            </p>
                            <div class="inline-flex items-center gap-1.5 mt-2 bg-indigo-50 text-indigo-600 px-3 py-1 rounded-lg text-xs font-bold">
                                <i class="fas fa-magnifying-glass text-[10px]"></i> 2 Potential Matches Found
                            </div>
                        </div>
                        <div class="flex md:flex-col gap-2 w-full md:w-auto">
                            <button onclick="switchTab('potential-matches')" class="flex-1 px-4 py-2 bg-indigo-600 text-white rounded-lg text-xs font-bold hover:bg-indigo-700 transition">View Matches</button>
                            <button class="flex-1 px-4 py-2 border border-slate-200 text-slate-600 rounded-lg text-xs font-bold hover:bg-white transition">Delete</button>
                        </div>
                    </div>
                </div>

                <!-- Potential Matches (Hidden by default) -->
                <div id="content-potential-matches" class="hidden space-y-6">
                    <div class="bg-amber-50 border border-amber-100 p-4 rounded-xl flex gap-3 items-center">
                        <i class="fas fa-info-circle text-amber-500"></i>
                        <p class="text-xs text-amber-800 italic">Matching is based on category, location, and keywords. Visit OSA for final verification.</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Match Card -->
                        <div class="bg-white border-2 border-indigo-100 rounded-2xl p-4 flex flex-col gap-4 relative">
                            <span class="absolute -top-3 left-4 bg-indigo-600 text-white text-[10px] px-3 py-1 rounded-full font-bold">85% MATCH</span>
                            <div class="flex gap-4">
                                <img src="https://images.unsplash.com/photo-1627123424574-724758594e93?auto=format&fit=crop&w=300&q=80" class="w-20 h-20 rounded-xl object-cover">
                                <div>
                                    <h5 class="font-bold text-slate-800">Navy Blue Water Bottle</h5>
                                    <p class="text-xs text-slate-500 mb-2">Turned in: Oct 24 (Canteen)</p>
                                    <button class="text-indigo-600 font-bold text-xs hover:underline">Verify at OSA &rarr;</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Turnover QR Modal -->
    <div id="qrModal" class="fixed inset-0 z-[60] hidden flex items-center justify-center p-4 bg-slate-900/80 backdrop-blur-sm">
        <div class="bg-white rounded-3xl max-w-sm w-full p-8 shadow-2xl text-center">
            <div class="flex justify-between items-center mb-6">
                <h3 class="font-bold text-slate-800">Turnover QR Code</h3>
                <button onclick="closeQRModal()" class="text-slate-400 hover:text-slate-600"><i class="fas fa-times"></i></button>
            </div>
            
            <div class="bg-slate-50 p-6 rounded-2xl mb-6">
                <!-- Mock QR Code -->
                <div class="w-48 h-48 bg-white border-8 border-white shadow-sm mx-auto flex items-center justify-center p-2">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=FOUND-TRK-88219" alt="QR Code" class="w-full h-full grayscale">
                </div>
                <p class="mt-4 font-mono text-sm text-slate-500 tracking-widest">TRK-88219-AM</p>
            </div>

            <div class="space-y-4 text-left">
                <div class="flex gap-3 items-start">
                    <i class="fas fa-id-card text-cmu-blue mt-1"></i>
                    <p class="text-xs text-slate-600">Present this code to the <strong>OSA Admin</strong> upon surrendering the physical item.</p>
                </div>
                <div class="flex gap-3 items-start">
                    <i class="fas fa-clock text-amber-500 mt-1"></i>
                    <p class="text-xs text-slate-600 italic">This code expires in <strong>24 hours</strong>. Please turnover immediately.</p>
                </div>
            </div>

            <button onclick="window.print()" class="w-full mt-8 py-3 bg-slate-100 text-slate-700 rounded-xl font-bold text-sm hover:bg-slate-200 transition">
                <i class="fas fa-download mr-2"></i>Save as Image
            </button>
        </div>
    </div>

    <script>
        function switchTab(tab) {
            // Update Tab Styling
            const tabs = ['my-reports', 'potential-matches'];
            tabs.forEach(t => {
                const el = document.getElementById(`tab-${t}`);
                const content = document.getElementById(`content-${t}`);
                if (t === tab) {
                    el.classList.add('tab-active');
                    el.classList.remove('text-slate-400');
                    content.classList.remove('hidden');
                } else {
                    el.classList.remove('tab-active');
                    el.classList.add('text-slate-400');
                    content.classList.add('hidden');
                }
            });
        }

        function openQRModal() {
            document.getElementById('qrModal').classList.remove('hidden');
        }

        function closeQRModal() {
            document.getElementById('qrModal').classList.add('hidden');
        }
    </script>
</body>
</html>