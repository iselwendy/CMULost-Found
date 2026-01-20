<?php
/**
 * CMU Lost & Found - Public Gallery Page
 * The primary hub for browsing lost and found items.
 */

// Mock state for the toggle and search
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'found'; // 'found' or 'lost'
$search_query = isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '';
$selected_category = isset($_GET['category']) ? $_GET['category'] : 'All Categories';
$selected_location = isset($_GET['location']) ? $_GET['location'] : 'All Locations';

// Mock data for the gallery items
$items = [
    [
        'id' => 1,
        'title' => 'Black Leather Wallet',
        'category' => 'Valuables',
        'location' => 'Main Library',
        'date' => 'Jan 24, 2026',
        'status' => 'In OSA Custody',
        'type' => 'found',
        'image' => 'https://images.unsplash.com/photo-1627123424574-724758594e93?auto=format&fit=crop&w=300&q=80'
    ],
    [
        'id' => 2,
        'title' => 'Calculus Textbook',
        'category' => 'Books',
        'location' => 'Innovation Bldg',
        'date' => 'Jan 26, 2026',
        'status' => 'Pending Turnover',
        'type' => 'found',
        'image' => 'https://images.unsplash.com/photo-1544947950-fa07a98d237f?auto=format&fit=crop&w=300&q=80'
    ],
    [
        'id' => 3,
        'title' => 'Blue Water Bottle',
        'category' => 'Personal Items',
        'location' => 'University Canteen',
        'date' => 'Jan 21, 2026',
        'status' => 'Lost Report',
        'type' => 'lost',
        'image' => 'https://images.unsplash.com/photo-1631201553014-776760c89381?auto=format&fit=crop&w=300&q=80'
    ],
    [
        'id' => 4,
        'title' => 'Silver Earbuds',
        'category' => 'Electronics',
        'location' => 'Quadrangle',
        'date' => 'Jan 25, 2026',
        'status' => 'In OSA Custody',
        'type' => 'found',
        'image' => 'https://images.unsplash.com/photo-1590658268037-6bf12165a8df?auto=format&fit=crop&w=300&q=80'
    ]
];

// Simple filter logic for demonstration
$filtered_items = array_filter($items, function($item) use ($view_mode) {
    return $item['type'] === $view_mode;
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gallery | CMU Lost & Found</title>
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
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <!-- Navbar -->
    <nav class="bg-cmu-blue text-white shadow-md sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-20">
                <div class="flex items-center space-x-3">
                    <img src="../assets/images/system-icon.png" alt="Logo" class="h-12 w-12" onerror="this.src='https://ui-avatars.com/api/?name=LF&background=FFCC00&color=003366'">
                    <span class="font-bold text-xl tracking-tight hidden sm:block">CMU Lost & Found</span>
                </div>
                
                <div class="hidden md:flex items-center space-x-8">
                    <a href="index.php" class="nav-link active py-7 text-sm font-medium">Public Gallery</a>
                    <a href="report_lost.php" class="hover:text-cmu-gold transition text-sm font-medium">Report Lost</a>
                    <a href="report_found.php" class="hover:text-cmu-gold transition text-sm font-medium">Report Found</a>
                    <a href="../dashboard/my_reports.php" class="hover:text-cmu-gold transition text-sm font-medium">My Dashboard</a>
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

    <!-- Header Section -->
    <div class="bg-white border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Items Gallery</h1>
                    <p class="text-gray-500 text-sm mt-1">Browse and find belongings misplaced within the campus.</p>
                </div>
                
                <div class="flex flex-wrap gap-3">
                    <a href="report_found.php" class="bg-cmu-gold text-cmu-blue px-6 py-2.5 rounded-lg font-bold shadow-sm hover:shadow-md transition flex items-center">
                        <i class="fas fa-plus-circle mr-2"></i> Found an Item?
                    </a>
                </div>
            </div>

            <!-- Enhanced Search and Smart Filter Bar -->
            <div class="mt-8 space-y-4">
                <div class="flex flex-col lg:flex-row gap-4">
                    <!-- Search Input -->
                    <div class="flex-1 relative">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="text" placeholder="Search by item name..." class="w-full pl-11 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-cmu-blue outline-none transition">
                    </div>

                    <!-- Smart Filter Dropdowns -->
                    <div class="flex flex-wrap md:flex-nowrap gap-3">
                        <div class="relative w-full md:w-48">
                            <select class="filter-select w-full pl-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-sm font-medium text-gray-700 outline-none focus:ring-2 focus:ring-cmu-blue transition cursor-pointer">
                                <option>All Categories</option>
                                <option>Electronics</option>
                                <option>Valuables</option>
                                <option>Books</option>
                                <option>Personal Items</option>
                                <option>Documents</option>
                            </select>
                        </div>

                        <div class="relative w-full md:w-48">
                            <select class="filter-select w-full pl-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-sm font-medium text-gray-700 outline-none focus:ring-2 focus:ring-cmu-blue transition cursor-pointer">
                                <option>All Locations</option>
                                <option>Main Library</option>
                                <option>Innovation Bldg</option>
                                <option>University Canteen</option>
                                <option>Quadrangle</option>
                                <option>Admin Bldg</option>
                            </select>
                        </div>

                        <div class="relative w-full md:w-48">
                            <select class="filter-select w-full pl-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-sm font-medium text-gray-700 outline-none focus:ring-2 focus:ring-cmu-blue transition cursor-pointer">
                                <option>Anytime</option>
                                <option>Today</option>
                                <option>Last 7 Days</option>
                                <option>This Month</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Toggle for Found/Lost -->
                <div class="flex bg-gray-100 p-1 rounded-xl w-fit">
                    <a href="?view=found" class="px-6 py-2 rounded-lg text-sm font-bold transition <?php echo $view_mode === 'found' ? 'bg-white text-cmu-blue shadow-sm' : 'text-gray-500 hover:text-gray-700'; ?>">
                        Found Items
                    </a>
                    <a href="?view=lost" class="px-6 py-2 rounded-lg text-sm font-bold transition <?php echo $view_mode === 'lost' ? 'bg-white text-cmu-blue shadow-sm' : 'text-gray-500 hover:text-gray-700'; ?>">
                        Lost Reports
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Gallery Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        
        <?php if (empty($filtered_items)): ?>
            <div class="text-center py-20 bg-white rounded-2xl border-2 border-dashed border-gray-200">
                <div class="w-20 h-20 bg-gray-50 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-box-open text-gray-300 text-3xl"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900">No items found</h3>
                <p class="text-gray-500 max-w-xs mx-auto mt-2">Try adjusting your filters or search terms to find what you're looking for.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-8">
                <?php foreach ($filtered_items as $item): ?>
                    <div class="group bg-white rounded-2xl overflow-hidden shadow-sm hover:shadow-xl transition-all duration-300 flex flex-col border border-gray-100">
                        <!-- Image Container -->
                        <div class="relative h-56 w-full overflow-hidden bg-gray-200">
                            <img src="<?php echo $item['image']; ?>" alt="Item Image" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                            <div class="absolute top-3 left-3">
                                <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?php echo $item['type'] === 'found' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                                    <?php echo $item['type']; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Content -->
                        <div class="p-5 flex-1 flex flex-col">
                            <div class="mb-4">
                                <p class="text-cmu-blue text-xs font-bold uppercase tracking-tight mb-1"><?php echo $item['category']; ?></p>
                                <h3 class="text-lg font-bold text-gray-800 line-clamp-1"><?php echo $item['title']; ?></h3>
                            </div>

                            <div class="space-y-2 mb-6">
                                <div class="flex items-center text-sm text-gray-500">
                                    <i class="fas fa-map-marker-alt w-5 text-gray-400"></i>
                                    <span><?php echo $item['location']; ?></span>
                                </div>
                                <div class="flex items-center text-sm text-gray-500">
                                    <i class="fas fa-calendar-alt w-5 text-gray-400"></i>
                                    <span><?php echo $item['date']; ?></span>
                                </div>
                                <div class="flex items-center text-sm">
                                    <i class="fas fa-info-circle w-5 text-blue-400"></i>
                                    <span class="font-medium text-blue-600"><?php echo $item['status']; ?></span>
                                </div>
                            </div>

                            <button class="mt-auto w-full py-2.5 rounded-xl border-2 border-gray-100 text-gray-700 font-bold text-sm hover:bg-cmu-blue hover:text-white hover:border-cmu-blue transition">
                                View Details
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </main>

    <!-- Footer -->
    <?php require_once '../includes/footer.php'; ?>

</body>
</html>