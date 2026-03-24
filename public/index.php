<?php
/**
 * CMU Lost & Found - Public Gallery Page
 * Fetches data from lost_reports and found_reports tables using PDO.
 */

// 1. Include Database Configuration
require_once '../core/db_config.php'; 

// 2. Initialize Filters from URL Parameters
$view_mode = isset($_GET['view']) && $_GET['view'] === 'lost' ? 'lost' : 'found'; 
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$selected_category = isset($_GET['category']) ? $_GET['category'] : 'All Categories';
$selected_location = isset($_GET['location']) ? $_GET['location'] : 'All Locations';
$selected_time = isset($_GET['time']) ? $_GET['time'] : 'Anytime';

try {
    $db = getDB();
    
    /**
     * 3. Construct the SQL Query
     * Since we have two separate tables, we use UNION ALL to combine them.
     * We also JOIN with item_images to get the first image for each report.
     */
    
    // We select specific columns and add a hardcoded 'type' to identify the source
    $base_sql = "
        SELECT 
            'found' as type, 
            f.found_id as id,
            f.title as item_name,
            c.name as category,
            loc.location_name as location,
            f.private_description as description,
            f.status as status,
            f.date_found as created_at,
            img.image_path
        FROM found_reports f
        LEFT JOIN categories c ON f.category_id = c.category_id
        LEFT JOIN locations loc ON f.location_id = loc.location_id
        LEFT JOIN item_images img ON f.found_id = img.report_id AND img.report_type = 'found'
        
        UNION ALL
        
        SELECT 
            'lost' as type, 
            l.lost_id as id, 
            l.title as item_name,
            c.name as category,
            loc.location_name as location,
            l.private_description as description,
            l.status as status,
            l.date_lost as created_at,
            img.image_path
        FROM lost_reports l
        LEFT JOIN categories c ON l.category_id = c.category_id
        LEFT JOIN locations loc ON l.location_id = loc.location_id
        LEFT JOIN item_images img ON l.lost_id = img.report_id AND img.report_type = 'lost'
    ";

    // Wrap the UNION in an outer query to apply filters easily
    $sql = "SELECT * FROM ($base_sql) as combined_gallery WHERE type = :view_mode";
    $params = [':view_mode' => $view_mode];

    // Apply Search Filter
    if (!empty($search_query)) {
        $sql .= " AND (item_name LIKE :search1 OR description LIKE :search2)";
        $params[':search1'] = '%' . $search_query . '%';
        $params[':search2'] = '%' . $search_query . '%';
    }

    // Apply Category Filter
    if ($selected_category !== 'All Categories') {
        $sql .= " AND category = :category";
        $params[':category'] = $selected_category;
    }

    // Apply Location Filter
    if ($selected_location !== 'All Locations') {
        $sql .= " AND location = :location";
        $params[':location'] = $selected_location;
    }

    // Apply Time Filter
    if ($selected_time === 'Today') {
        $sql .= " AND DATE(created_at) = CURDATE()";
    } elseif ($selected_time === 'Last 7 Days') {
        $sql .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    }

    $sql .= " ORDER BY created_at DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

} catch (PDOException $e) {
    $items = [];
    $error_msg = "Error fetching items: " . $e->getMessage();
}
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
    <link rel="stylesheet" href="../assets/styles/header.css"></link>
    <link rel="stylesheet" href="../assets/styles/root.css"></link>
</head>
<body class="bg-gray-100 min-h-screen">

    <!-- Navbar -->
    <?php require_once '../includes/header.php'; ?>

    <!-- Header Section -->
    <div class="bg-white border-b border-gray-200 top-0 z-10 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
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
            <form action="index.php" method="GET" class="mt-6 space-y-4">
                <input type="hidden" name="view" value="<?php echo htmlspecialchars($view_mode); ?>">

                <div class="flex flex-col lg:flex-row gap-3">
                    <!-- Search Input -->
                    <div class="flex-1 relative">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" 
                               placeholder="Search by item name or description..." 
                               class="w-full pl-11 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-cmu-blue outline-none transition">
                    </div>

                    <!-- Smart Filter Dropdowns -->
                    <div class="flex flex-wrap md:flex-nowrap gap-3">
                        <div class="relative w-full md:w-48">
                            <select name="category" class="filter-select w-full pl-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-sm font-medium text-gray-700 outline-none focus:ring-2 focus:ring-cmu-blue transition cursor-pointer">
                                <option <?php echo $selected_category == 'All Categories' ? 'selected' : ''; ?>>All Categories</option>
                                <option <?php echo $selected_category == 'Electronics' ? 'selected' : ''; ?>>Electronics</option>
                                <option <?php echo $selected_category == 'Valuables' ? 'selected' : ''; ?>>Valuables</option>
                                <option <?php echo $selected_category == 'Documents' ? 'selected' : ''; ?>>Documents</option>
                                <option <?php echo $selected_category == 'Books' ? 'selected' : ''; ?>>Books</option>
                                <option <?php echo $selected_category == 'Clothing' ? 'selected' : ''; ?>>Clothing</option>
                                <option <?php echo $selected_category == 'Personal' ? 'selected' : ''; ?>>Personal</option>
                                <option <?php echo $selected_category == 'Other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <div class="relative w-full md:w-48">
                            <select name="location" class="filter-select w-full pl-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-sm font-medium text-gray-700 outline-none focus:ring-2 focus:ring-cmu-blue transition cursor-pointer">
                                <option <?php echo $selected_location == 'All Locations' ? 'selected' : ''; ?>>All Locations</option>
                                <option <?php echo $selected_location == 'Main Library' ? 'selected' : ''; ?>>Main Library</option>
                                <option <?php echo $selected_location == 'Innovation Bldg' ? 'selected' : ''; ?>>Innovation Bldg</option>
                                <option <?php echo $selected_location == 'ERC Bldg' ? 'selected' : ''; ?>>ERC Bldg</option>
                                <option <?php echo $selected_location == 'University Canteen' ? 'selected' : ''; ?>>University Canteen</option>
                            </select>
                        </div>

                        <div class="relative w-full md:w-48">
                            <select name="time" class="filter-select w-full pl-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-sm font-medium text-gray-700 outline-none focus:ring-2 focus:ring-cmu-blue transition cursor-pointer">
                                <option <?php echo $selected_time == 'Anytime' ? 'selected' : ''; ?>>Anytime</option>
                                <option <?php echo $selected_time == 'Today' ? 'selected' : ''; ?>>Today</option>
                                <option <?php echo $selected_time == 'Last 7 Days' ? 'selected' : ''; ?>>Last 7 Days</option>
                            </select>
                        </div>

                        <!-- Manual Search Button if user doesn't press Enter -->
                        <button type="submit" class="bg-cmu-blue text-white px-6  rounded-xl font-bold hover:bg-opacity-90 transition">
                            Filter
                        </button>
                    </div>
                </div>

                <!-- Toggle -->
                <div class="flex bg-gray-100 p-1 rounded-xl w-fit">
                    <a href="?view=found&search=<?php echo urlencode($search_query); ?>&category=<?php echo urlencode($selected_category); ?>&location=<?php echo urlencode($selected_location); ?>&time=<?php echo urlencode($selected_time); ?>" 
                    class="px-6 py-2 rounded-lg text-sm font-bold transition <?php echo $view_mode === 'found' ? 'bg-white text-cmu-blue shadow-sm' : 'text-gray-500'; ?>">
                        Found Items
                    </a>
                    <a href="?view=lost&search=<?php echo urlencode($search_query); ?>&category=<?php echo urlencode($selected_category); ?>&location=<?php echo urlencode($selected_location); ?>&time=<?php echo urlencode($selected_time); ?>" 
                    class="px-6 py-2 rounded-lg text-sm font-bold transition <?php echo $view_mode === 'lost' ? 'bg-white text-cmu-blue shadow-sm' : 'text-gray-500'; ?>">
                        Lost Reports
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Main Gallery Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        
        <?php if (empty($items)): ?>
            <div class="text-center py-20 bg-white rounded-3xl border border-gray-100 shadow-sm">
                <div class="w-20 h-20 bg-blue-50 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-box-open text-blue-200 text-3xl"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-900">No items reported yet</h3>
                <p class="text-gray-500 mt-2">Try adjusting your filters or check back later.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <?php foreach ($items as $item): ?>
                    <div class="group bg-white rounded-2xl overflow-hidden shadow-sm hover:shadow-xl transition-all duration-300 border border-gray-100 flex flex-col">
                        <div class="relative h-52 overflow-hidden bg-gray-100">
                            <?php 
                                // Using the image_path from the JOIN
                                $image_path = !empty($item['image_path']) ? '../' . htmlspecialchars($item['image_path']) : 'https://placehold.co/600x400/e2e8f0/64748b?text=No+Photo';
                            ?>
                            <img src="<?php echo $image_path; ?>" alt="Item" class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500">
                            <div class="absolute top-3 left-3">
                                <span class="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest bg-white/90 backdrop-blur shadow-sm <?php echo $item['type'] === 'found' ? 'text-green-600' : 'text-red-600'; ?>">
                                    <?php echo htmlspecialchars($item['type']); ?>
                                </span>
                            </div>
                        </div>

                        <div class="p-5 flex-1 flex flex-col">
                            <span class="text-[10px] font-bold text-cmu-blue uppercase tracking-tighter mb-1"><?php echo htmlspecialchars($item['category']); ?></span>
                            <h3 class="font-bold text-gray-800 text-lg mb-3 line-clamp-1"><?php echo htmlspecialchars($item['item_name']); ?></h3>
                            
                            <div class="space-y-2 text-sm text-gray-500 mb-6">
                                <div class="flex items-center">
                                    <i class="fas fa-map-marker-alt w-5 text-gray-300"></i>
                                    <span class="truncate"><?php echo htmlspecialchars($item['location']); ?></span>
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-calendar-alt w-5 text-gray-300"></i>
                                    <span><?php echo date('M d, Y', strtotime($item['created_at'])); ?></span>
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-info-circle w-5 text-blue-300"></i>
                                    <span class="text-blue-600 font-semibold capitalize">
                                        <?php echo str_replace('_', ' ', htmlspecialchars($item['status'])); ?>
                                    </span>
                                </div>
                            </div>

                            <a href="item_details.php?id=<?php echo $item['id']; ?>&type=<?php echo $item['type']; ?>" 
                               class="mt-auto block text-center py-2.5 rounded-xl bg-gray-50 text-gray-700 font-bold hover:bg-cmu-blue hover:text-white transition-all border border-gray-100">
                                View Full Details
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </main>

    <!-- Footer -->
    <?php require_once '../includes/footer.php'; ?>

    <!-- Scripts -->
    <script src="../assets/scripts/profile-dropdown.js"></script>
</body>
</html>