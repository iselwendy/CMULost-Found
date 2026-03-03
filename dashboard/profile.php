<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | CMU Lost & Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Custom tab transition */
        .tab-active {
            border-bottom: 3px solid var(--cmu-blue);
            color: var(--cmu-blue);
        }
    </style>
    <link rel="stylesheet" href="../assets/styles/header.css"></link>
    <link rel="stylesheet" href="../assets/styles/root.css"></link>
</head>
<body class="bg-gray-100 min-h-screen">

    <!-- Mock PHP Logic (Equivalent to the Gallery logic provided) -->
    <?php
        $user = [
            'name' => 'Abdul Montefalco',
            'email' => 'abdul.montefalco@cityofmalabonuniversity.edu.ph',
            'id_number' => '202600274',
            'department' => 'College of Computer Studies',
            'profile_pic' => 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?auto=format&fit=facearea&facepad=2&w=256&h=256&q=80',
            'joined_date' => 'Jul 2026'
        ];

        $my_items = [
            [
                'id' => 101,
                'title' => 'Scientific Calculator',
                'category' => 'Electronics',
                'location' => 'Room 302, CAS Bldg',
                'date' => 'Feb 12, 2026',
                'status' => 'Returned',
                'type' => 'found'
            ],
            [
                'id' => 102,
                'title' => 'Keys with Blue Keychain',
                'category' => 'Valuables',
                'location' => 'Grandstand',
                'date' => 'Feb 18, 2026',
                'status' => 'In OSA Custody',
                'type' => 'found'
            ]
        ];
    ?>

    <!-- Navbar -->
    <?php require_once '../includes/header.php'; ?>

    <!-- Profile Header Section -->
    <div class="bg-white border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
            <div class="flex flex-col md:flex-row items-center md:items-start gap-8">
                <!-- Profile Image -->
                <div class="relative">
                    <div class="w-32 h-32 rounded-2xl overflow-hidden border-4 border-white shadow-xl">
                        <img src="<?php echo $user['profile_pic']; ?>" class="w-full h-full object-cover">
                    </div>
                    <button class="absolute -bottom-2 -right-2 bg-white p-2 rounded-lg shadow-md border border-gray-100 text-gray-600 hover:text-cmu-blue transition">
                        <i class="fas fa-camera"></i>
                    </button>
                </div>

                <!-- User Info -->
                <div class="flex-1 text-center md:text-left">
                    <div class="flex flex-col md:flex-row md:items-center gap-3 mb-2">
                        <h1 class="text-3xl font-bold text-gray-900"><?php echo $user['name']; ?></h1>
                        <span class="px-3 py-1 bg-blue-50 text-cmu-blue text-xs font-bold rounded-full border border-blue-100 uppercase tracking-wider">Student</span>
                    </div>
                    <p class="text-gray-500 flex items-center justify-center md:justify-start gap-2 mb-4">
                        <i class="far fa-envelope"></i> <?php echo $user['email']; ?>
                        <span class="text-gray-300">|</span>
                        <i class="far fa-id-badge"></i> <?php echo $user['id_number']; ?>
                    </p>
                    
                    <div class="flex flex-wrap justify-center md:justify-start gap-4">
                        <div class="bg-gray-50 px-4 py-2 rounded-xl border border-gray-100">
                            <p class="text-[10px] uppercase text-gray-400 font-bold leading-none mb-1">Total Reports</p>
                            <p class="text-lg font-bold text-gray-800">12</p>
                        </div>
                        <div class="bg-gray-50 px-4 py-2 rounded-xl border border-gray-100">
                            <p class="text-[10px] uppercase text-gray-400 font-bold leading-none mb-1">Successful Returns</p>
                            <p class="text-lg font-bold text-green-600">8</p>
                        </div>
                        <div class="bg-gray-50 px-4 py-2 rounded-xl border border-gray-100">
                            <p class="text-[10px] uppercase text-gray-400 font-bold leading-none mb-1">Member Since</p>
                            <p class="text-lg font-bold text-gray-800"><?php echo $user['joined_date']; ?></p>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex gap-3">
                    <button class="bg-white border border-gray-200 text-gray-700 px-5 py-2.5 rounded-xl font-bold text-sm hover:bg-gray-50 transition shadow-sm">
                        <a href="../dashboard/edit_profile.php">Edit Profile</a>
                    </button>
                    <button class="bg-cmu-blue text-white px-5 py-2.5 rounded-xl font-bold text-sm hover:shadow-lg transition shadow-sm">
                        <a href="../dashboard/settings.php">Account Settings</a>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- Left Sidebar: Additional Details -->
            <div class="space-y-6">
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
                    <h2 class="text-sm font-bold text-gray-900 uppercase tracking-widest mb-4">University Details</h2>
                    <div class="space-y-4">
                        <div>
                            <p class="text-xs text-gray-400 font-medium mb-1">Department</p>
                            <p class="text-gray-800 font-semibold"><?php echo $user['department']; ?></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-400 font-medium mb-1">Course & Year</p>
                            <p class="text-gray-800 font-semibold">BSIT 1A</p>
                        </div>
                        <div class="pt-4 border-t border-gray-50">
                            <button class="text-cmu-blue text-sm font-bold hover:underline">
                                <i class="fas fa-shield-alt mr-2"></i>Verify Account Status
                            </button>
                        </div>
                    </div>
                </div>

                <div class="bg-cmu-blue p-6 rounded-2xl shadow-lg text-white relative overflow-hidden">
                    <div class="relative z-10">
                        <h3 class="font-bold text-lg mb-2">Need Help?</h3>
                        <p class="text-blue-100 text-sm mb-4">Contact the Office of Student Affairs for immediate assistance with valuable items.</p>
                        <a href="#" class="inline-block bg-cmu-gold text-cmu-blue px-4 py-2 rounded-lg font-bold text-xs uppercase tracking-wider">Contact OSA</a>
                    </div>
                    <!-- Decorative Icon -->
                    <i class="fas fa-info-circle absolute -bottom-4 -right-4 text-white/10 text-8xl"></i>
                </div>
            </div>

            <!-- Right: Activity Tabs & Content -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Tab Navigation -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="flex border-b border-gray-100">
                        <button class="flex-1 py-4 text-sm font-bold tab-active">
                            My Reported Items (Found)
                        </button>
                        <button class="flex-1 py-4 text-sm font-bold text-gray-400 hover:text-gray-600 transition">
                            My Lost Reports
                        </button>
                    </div>

                    <!-- Items List -->
                    <div class="p-2">
                        <?php if (empty($my_items)): ?>
                            <div class="text-center py-12">
                                <i class="fas fa-folder-open text-gray-200 text-4xl mb-3"></i>
                                <p class="text-gray-500">You haven't reported any items yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="divide-y divide-gray-50">
                                <?php foreach ($my_items as $item): ?>
                                <div class="p-4 hover:bg-gray-50 rounded-xl transition group">
                                    <div class="flex items-center gap-4">
                                        <!-- Mini Image/Icon -->
                                        <div class="w-16 h-16 bg-gray-100 rounded-lg flex items-center justify-center text-gray-400 overflow-hidden shrink-0">
                                            <i class="fas fa-image text-xl"></i>
                                        </div>
                                        
                                        <div class="flex-1">
                                            <div class="flex justify-between items-start">
                                                <div>
                                                    <p class="text-[10px] font-bold text-cmu-blue uppercase mb-1"><?php echo $item['category']; ?></p>
                                                    <h4 class="font-bold text-gray-800 group-hover:text-cmu-blue transition"><?php echo $item['title']; ?></h4>
                                                </div>
                                                <span class="px-2 py-1 rounded text-[10px] font-bold uppercase <?php echo $item['status'] === 'Returned' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700'; ?>">
                                                    <?php echo $item['status']; ?>
                                                </span>
                                            </div>
                                            <div class="flex flex-wrap gap-4 mt-2">
                                                <span class="text-xs text-gray-500"><i class="fas fa-map-marker-alt mr-1"></i> <?php echo $item['location']; ?></span>
                                                <span class="text-xs text-gray-500"><i class="fas fa-calendar-alt mr-1"></i> <?php echo $item['date']; ?></span>
                                            </div>
                                        </div>

                                        <div class="flex flex-col gap-2">
                                            <button class="p-2 text-gray-400 hover:text-cmu-blue transition" title="Edit Report">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="p-2 text-gray-400 hover:text-red-500 transition" title="Delete Report">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Footer Action -->
                    <div class="bg-gray-50 p-4 text-center border-t border-gray-100">
                        <a href="../dashboard/gallery.php" class="text-xs font-bold text-gray-500 hover:text-cmu-blue uppercase tracking-widest">
                            View All <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <!-- Footer -->
    <?php require_once '../includes/footer.php'; ?>

    <!-- Scripts -->
    <script src="../assets/scripts/profile-dropdown.js"></script>
</body>
</html>