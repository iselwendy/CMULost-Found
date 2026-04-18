<?php
/**
 * CMU Lost & Found - Public Gallery Page
 * Fetches data from lost_reports and found_reports tables using PDO.
 */

require_once '../core/db_config.php'; 

$view_mode = isset($_GET['view']) && $_GET['view'] === 'lost' ? 'lost' : 'found'; 
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$selected_category = isset($_GET['category']) ? $_GET['category'] : 'All Categories';
$selected_location_id = isset($_GET['location']) && $_GET['location'] !== 'all' ? (int)$_GET['location'] : 0;
$selected_time = isset($_GET['time']) ? $_GET['time'] : 'Anytime';

function fetchSystemSettings(PDO $pdo): array {
    try {
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

try {
    $db = getDB();

    $settings = fetchSystemSettings($db);
    $gallery_enabled = isset($settings['gallery_open']) ? (int)$settings['gallery_open'] : 1;

    // Load locations for the dropdown
    try {
        $loc_stmt = $db->query("SELECT location_id, location_name FROM locations ORDER BY location_name ASC");
        $locations = $loc_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $locations = [];
    }

    // Only run the heavy query if the gallery is actually open
    if ($gallery_enabled == 1) {
        $base_sql = "
            SELECT 
                'found' as type, 
                f.found_id as id,
                f.title as item_name,
                c.name as category,
                loc.location_name as location,
                loc.location_id as location_id,
                f.private_description as description,
                f.status as status,
                f.date_found as created_at,
                f.date_found as raw_date,
                img.image_path
            FROM found_reports f
            LEFT JOIN categories c ON f.category_id = c.category_id
            LEFT JOIN locations loc ON f.location_id = loc.location_id
            LEFT JOIN (
                SELECT report_id, image_path
                FROM item_images i1
                WHERE report_type = 'found'
                AND image_id = (
                    SELECT MIN(i2.image_id)
                    FROM item_images i2
                    WHERE i2.report_type = 'found'
                        AND i2.report_id = i1.report_id
                )
            ) img ON img.report_id = f.found_id
            WHERE f.status IN ('in custody', 'matched', 'surrendered')
            
            UNION ALL
            
            SELECT 
                'lost' as type, 
                l.lost_id as id, 
                l.title as item_name,
                c.name as category,
                loc.location_name as location,
                loc.location_id as location_id,
                l.private_description as description,
                l.status as status,
                l.date_lost as created_at,
                l.date_lost as raw_date,
                img.image_path
            FROM lost_reports l
            LEFT JOIN categories c ON l.category_id = c.category_id
            LEFT JOIN locations loc ON l.location_id = loc.location_id
            LEFT JOIN (
                SELECT report_id, image_path
                FROM item_images i1
                WHERE report_type = 'lost'
                AND image_id = (
                    SELECT MIN(i2.image_id)
                    FROM item_images i2
                    WHERE i2.report_type = 'lost'
                        AND i2.report_id = i1.report_id
                )
            ) img ON img.report_id = l.lost_id
            WHERE l.status IN ('open', 'matched')
        ";

        // Build outer WHERE conditions
        $where_clauses = ['type = :view_mode'];
        $params = [':view_mode' => $view_mode];

        if (!empty($search_query)) {
            $where_clauses[] = "(item_name LIKE :search1 OR description LIKE :search2)";
            $params[':search1'] = '%' . $search_query . '%';
            $params[':search2'] = '%' . $search_query . '%';
        }

        if ($selected_category !== 'All Categories') {
            $where_clauses[] = "category = :category";
            $params[':category'] = $selected_category;
        }

        // Filter by location_id (now stored in the subquery result)
        if ($selected_location_id > 0) {
            $where_clauses[] = "location_id = :location_id";
            $params[':location_id'] = $selected_location_id;
        }

        $sql = "SELECT * FROM ($base_sql) as combined_gallery";
        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }

        if ($selected_time === 'Today') {
            $sql .= " AND DATE(created_at) = CURDATE()";
        } elseif ($selected_time === 'Last 7 Days') {
            $sql .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        }

        $sql .= " ORDER BY created_at DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();
    } else {
        $items = [];
    }
} catch (PDOException $e) {
    $items = [];
    $locations = [];
    $error_msg = "Error fetching items: " . $e->getMessage();
}

// Build the base query string for toggle links (preserves all current filters)
function buildToggleHref(string $view, string $search, string $category, int $location_id, string $time): string {
    $params = ['view' => $view];
    if (!empty($search))              $params['search']   = $search;
    if ($category !== 'All Categories') $params['category'] = $category;
    if ($location_id > 0)             $params['location'] = $location_id;
    if ($time !== 'Anytime')          $params['time']     = $time;
    return 'index.php?' . http_build_query($params);
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
    <link rel="stylesheet" href="../assets/styles/header.css">
    <link rel="stylesheet" href="../assets/styles/root.css">
    <style>
        #itemModal { transition: opacity 0.2s ease; }
        #modalCard { transition: transform 0.25s ease, opacity 0.25s ease; }
        body.modal-open { overflow: hidden; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php require_once '../includes/header.php'; ?>

    <!-- Filter Bar -->
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

            <form action="index.php" method="GET" class="mt-6 space-y-4">
                <input type="hidden" name="view" value="<?php echo htmlspecialchars($view_mode); ?>">
                <div class="flex flex-col lg:flex-row gap-3">
                    <div class="flex-1 relative">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" 
                               placeholder="Search by item name or description..." 
                               class="w-full pl-11 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-cmu-blue outline-none transition">
                    </div>
                    <div class="flex flex-wrap md:flex-nowrap gap-3">
                        <select name="category" class="filter-select w-full md:w-48 pl-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-sm font-medium text-gray-700 outline-none focus:ring-2 focus:ring-cmu-blue transition cursor-pointer">
                            <option value="All Categories" <?php echo $selected_category === 'All Categories' ? 'selected' : ''; ?>>All Categories</option>
                            <option value="Electronics" <?php echo $selected_category === 'Electronics' ? 'selected' : ''; ?>>Electronics</option>
                            <option value="Valuables" <?php echo $selected_category === 'Valuables' ? 'selected' : ''; ?>>Valuables</option>
                            <option value="Documents" <?php echo $selected_category === 'Documents' ? 'selected' : ''; ?>>Documents</option>
                            <option value="Books" <?php echo $selected_category === 'Books' ? 'selected' : ''; ?>>Books</option>
                            <option value="Clothing" <?php echo $selected_category === 'Clothing' ? 'selected' : ''; ?>>Clothing</option>
                            <option value="Personal" <?php echo $selected_category === 'Personal' ? 'selected' : ''; ?>>Personal</option>
                            <option value="Other" <?php echo $selected_category === 'Other' ? 'selected' : ''; ?>>Other</option>
                        </select>

                        <select name="location" class="filter-select w-full md:w-48 pl-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-sm font-medium text-gray-700 outline-none focus:ring-2 focus:ring-cmu-blue transition cursor-pointer">
                            <option value="all" <?php echo $selected_location_id === 0 ? 'selected' : ''; ?>>All Locations</option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?php echo (int)$loc['location_id']; ?>"
                                    <?php echo $selected_location_id === (int)$loc['location_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($loc['location_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="time" class="filter-select w-full md:w-48 pl-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-sm font-medium text-gray-700 outline-none focus:ring-2 focus:ring-cmu-blue transition cursor-pointer">
                            <option value="Anytime" <?php echo $selected_time === 'Anytime' ? 'selected' : ''; ?>>Anytime</option>
                            <option value="Today" <?php echo $selected_time === 'Today' ? 'selected' : ''; ?>>Today</option>
                            <option value="Last 7 Days" <?php echo $selected_time === 'Last 7 Days' ? 'selected' : ''; ?>>Last 7 Days</option>
                        </select>

                        <button type="submit" class="bg-cmu-blue text-white px-6 rounded-xl font-bold hover:bg-opacity-90 transition">
                            Filter
                        </button>
                    </div>
                </div>

                <!-- Found / Lost Toggle — preserves all active filters -->
                <div class="flex bg-gray-100 p-1 rounded-xl w-fit">
                    <a href="<?php echo htmlspecialchars(buildToggleHref('found', $search_query, $selected_category, $selected_location_id, $selected_time)); ?>"
                       class="px-6 py-2 rounded-lg text-sm font-bold transition <?php echo $view_mode === 'found' ? 'bg-white text-cmu-blue shadow-sm' : 'text-gray-500'; ?>">
                        Found Items
                    </a>
                    <a href="<?php echo htmlspecialchars(buildToggleHref('lost', $search_query, $selected_category, $selected_location_id, $selected_time)); ?>"
                       class="px-6 py-2 rounded-lg text-sm font-bold transition <?php echo $view_mode === 'lost' ? 'bg-white text-cmu-blue shadow-sm' : 'text-gray-500'; ?>">
                        Lost Reports
                    </a>
                </div>
            </form>
        </div>
    </div>

    <?php if ($gallery_enabled == 0): ?>
        <main class="max-w-7xl mx-auto px-4 py-20">
            <div class="max-w-2xl mx-auto text-center bg-white p-12 rounded-3xl shadow-sm border border-gray-100">
                <div class="w-24 h-24 bg-amber-50 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-tools text-amber-500 text-4xl"></i>
                </div>
                <h1 class="text-3xl font-black text-gray-900 mb-4">Gallery Maintenance</h1>
                <p class="text-gray-500 text-lg leading-relaxed mb-8">
                    The CMU Lost & Found gallery is temporarily offline for maintenance. 
                    Our staff is currently organizing recent reports to ensure accurate matching.
                </p>
            </div>
        </main>
    <?php else: ?>
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
            <?php if (empty($items)): ?>
                <div class="text-center py-20 bg-white rounded-3xl border border-gray-100 shadow-sm">
                    <div class="w-20 h-20 bg-blue-50 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-box-open text-blue-200 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">No items found</h3>
                    <p class="text-gray-500 mt-2">Try adjusting your filters or check back later.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                    <?php foreach ($items as $item): 
                        $image_path = !empty($item['image_path']) 
                            ? '../' . htmlspecialchars($item['image_path']) 
                            : 'https://placehold.co/600x400/e2e8f0/64748b?text=No+Photo';
                        $date_label     = $item['type'] === 'found' ? 'Date Found' : 'Date Lost';
                        $formatted_date = date('M d, Y', strtotime($item['created_at']));
                        $status_display = str_replace('_', ' ', $item['status']);
                        $raw_date       = !empty($item['raw_date'])
                            ? date('Y-m-d', strtotime($item['raw_date']))
                            : '';
                    ?>
                        <div class="group bg-white rounded-2xl overflow-hidden shadow-sm hover:shadow-xl transition-all duration-300 border border-gray-100 flex flex-col">
                            <div class="relative h-52 overflow-hidden bg-gray-100">
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
                                    <div class="flex items-center gap-2">
                                        <i class="fas fa-map-marker-alt w-4 text-gray-300"></i>
                                        <span class="truncate"><?php echo htmlspecialchars($item['location'] ?? '—'); ?></span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <i class="fas fa-calendar-alt w-4 text-gray-300"></i>
                                        <span><?php echo $formatted_date; ?></span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <i class="fas fa-info-circle w-4 text-blue-300"></i>
                                        <span class="text-blue-600 font-semibold capitalize"><?php echo htmlspecialchars($status_display); ?></span>
                                    </div>
                                </div>

                                <button
                                    onclick="openModal(<?php echo htmlspecialchars(json_encode([
                                        'id'         => $item['id'],
                                        'type'       => $item['type'],
                                        'item_name'  => $item['item_name'],
                                        'category'   => $item['category'],
                                        'location'   => $item['location'],
                                        'date_label' => $date_label,
                                        'date'       => $formatted_date,
                                        'raw_date'   => $raw_date,
                                        'status'     => $status_display,
                                        'image_path' => $image_path,
                                    ]), ENT_QUOTES); ?>)"
                                    class="mt-auto w-full py-2.5 rounded-xl bg-gray-50 text-gray-700 font-bold hover:bg-cmu-blue hover:text-white transition-all border border-gray-100">
                                    View Full Details
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    <?php endif; ?>

    <!-- ===================== ITEM DETAIL MODAL ===================== -->
    <div id="itemModal"
         class="fixed inset-0 z-[70] flex items-center justify-center p-4 bg-slate-900/70 backdrop-blur-sm opacity-0 pointer-events-none">
        <div id="modalCard"
             class="bg-white rounded-3xl w-full max-w-2xl shadow-2xl overflow-hidden scale-95 opacity-0 flex flex-col md:flex-row max-h-[90vh]">

            <!-- Image Side -->
            <div class="md:w-2/5 h-64 md:h-auto bg-gray-100 flex-shrink-0 relative overflow-hidden">
                <img id="modalImage" src="" alt="Item Photo" class="w-full h-full object-cover">
                <span id="modalTypeBadge"
                      class="absolute top-4 left-4 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest bg-white/90 backdrop-blur shadow-sm">
                </span>
            </div>

            <!-- Info Side -->
            <div class="flex-1 flex flex-col overflow-y-auto">
                <div class="flex items-start justify-between p-6 pb-4 border-b border-gray-100">
                    <div>
                        <p id="modalCategory" class="text-[10px] font-black text-cmu-blue uppercase tracking-widest mb-1"></p>
                        <h2 id="modalTitle" class="text-xl font-black text-gray-900 leading-tight"></h2>
                    </div>
                    <button onclick="closeModal()"
                            class="ml-4 flex-shrink-0 w-9 h-9 flex items-center justify-center rounded-full bg-gray-100 text-gray-500 hover:bg-red-100 hover:text-red-500 transition">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="p-6 space-y-4 flex-1">
                    <div class="grid grid-cols-1 gap-3">
                        <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-xl">
                            <div class="w-9 h-9 bg-white rounded-lg flex items-center justify-center shadow-sm border border-gray-100 flex-shrink-0">
                                <i class="fas fa-map-marker-alt text-cmu-blue text-sm"></i>
                            </div>
                            <div>
                                <p class="text-[10px] font-black text-gray-400 uppercase tracking-wider">Location</p>
                                <p id="modalLocation" class="text-sm font-semibold text-gray-800"></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-xl">
                            <div class="w-9 h-9 bg-white rounded-lg flex items-center justify-center shadow-sm border border-gray-100 flex-shrink-0">
                                <i class="fas fa-calendar-alt text-cmu-blue text-sm"></i>
                            </div>
                            <div>
                                <p id="modalDateLabel" class="text-[10px] font-black text-gray-400 uppercase tracking-wider"></p>
                                <p id="modalDate" class="text-sm font-semibold text-gray-800"></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-xl">
                            <div class="w-9 h-9 bg-white rounded-lg flex items-center justify-center shadow-sm border border-gray-100 flex-shrink-0">
                                <i class="fas fa-info-circle text-cmu-blue text-sm"></i>
                            </div>
                            <div>
                                <p class="text-[10px] font-black text-gray-400 uppercase tracking-wider">Status</p>
                                <p id="modalStatus" class="text-sm font-semibold text-blue-600 capitalize"></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-amber-50 border border-amber-100 rounded-xl p-3 flex gap-2">
                        <i class="fas fa-user-shield text-amber-500 mt-0.5 text-xs flex-shrink-0"></i>
                        <p class="text-[11px] text-amber-700 leading-relaxed">
                            Identifying details are kept private and only visible to OSA staff for verification purposes.
                        </p>
                    </div>
                </div>

                <!-- Action Button -->
                <div class="p-6 pt-0">
                    <div id="modalActionFound" class="hidden">
                        <a id="modalClaimLink" href="report_lost.php"
                           class="block w-full text-center py-3.5 bg-cmu-gold text-cmu-blue font-black rounded-xl hover:shadow-lg transition text-sm uppercase tracking-wide">
                            <i class="fas fa-hand-holding-heart mr-2"></i> This Is Mine — Claim Item
                        </a>
                        <p class="text-center text-[10px] text-gray-400 mt-2">
                            You will need to visit OSA and present valid ID for verification.
                        </p>
                    </div>
                    <div id="modalActionLost" class="hidden">
                        <a id="modalFoundLink" href="report_found.php"
                           class="block w-full text-center py-3.5 bg-cmu-blue text-white font-black rounded-xl hover:bg-slate-800 transition text-sm uppercase tracking-wide">
                            <i class="fas fa-search mr-2"></i> I Found This Item
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php require_once '../includes/footer.php'; ?>

    <script src="../assets/scripts/profile-dropdown.js"></script>
    <script src="../assets/scripts/item_card.js"></script>
    <script>
        // Auto-open modal if redirected from a duplicate-check link
        (function () {
            const params   = new URLSearchParams(window.location.search);
            const openId   = params.get('open_id');
            const openType = params.get('open_type');
            if (!openId || !openType) return;

            const buttons = document.querySelectorAll('[onclick]');
            for (const btn of buttons) {
                const attr = btn.getAttribute('onclick');
                if (attr && attr.includes(`"id":${openId}`) && attr.includes(`"type":"${openType}"`)) {
                    btn.click();
                    btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    break;
                }
            }
        })();
    </script>
</body>
</html>