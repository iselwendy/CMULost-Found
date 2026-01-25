<?php
/**
 * CMU Lost & Found - Item Details Page
 * Displays full information about a specific lost or found item.
 */

// Mock data fetching based on ID (In a real app, this would be a DB query)
$item_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Mock database of items
$items_db = [
    1 => [
        'title' => 'Black Leather Wallet',
        'category' => 'Valuables',
        'location' => 'Main Library',
        'date' => 'Jan 24, 2026',
        'status' => 'In OSA Custody',
        'type' => 'found',
        'description' => 'A black bi-fold leather wallet found near the computer laboratory. No cash was found inside, but it contains several cards.',
        'image' => 'https://images.unsplash.com/photo-1627123424574-724758594e93?auto=format&fit=crop&w=800&q=80',
        'poster' => 'John Doe (Finder)'
    ],
    2 => [
        'title' => 'Calculus Textbook',
        'category' => 'Books',
        'location' => 'Innovation Bldg',
        'date' => 'Jan 26, 2026',
        'status' => 'Pending Turnover',
        'type' => 'found',
        'description' => '12th Edition Calculus textbook. It has some highlighting on the first few chapters. Found at Room 302.',
        'image' => 'https://images.unsplash.com/photo-1544947950-fa07a98d237f?auto=format&fit=crop&w=800&q=80',
        'poster' => 'Jane Smith (Finder)'
    ]
];

$item = isset($items_db[$item_id]) ? $items_db[$item_id] : null;

if (!$item) {
    // Redirect back to gallery if item not found
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $item['title']; ?> | Details</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --cmu-blue: #003366; --cmu-gold: #FFCC00; }
        .bg-cmu-blue { background-color: var(--cmu-blue); }
        .text-cmu-blue { color: var(--cmu-blue); }
        .bg-cmu-gold { background-color: var(--cmu-gold); }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">

    <!-- Navbar Snippet -->
    <nav class="bg-cmu-blue text-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16 items-center">
                <a href="index.php" class="flex items-center space-x-2">
                    <i class="fas fa-arrow-left"></i>
                    <span class="font-bold">Back to Gallery</span>
                </a>
                <span class="text-sm opacity-75 italic">Item ID: #<?php echo str_pad($item_id, 5, '0', STR_PAD_LEFT); ?></span>
            </div>
        </div>
    </nav>

    <main class="max-w-4xl mx-auto px-4 py-10">
        <div class="bg-white rounded-3xl shadow-xl overflow-hidden border border-gray-100">
            <div class="flex flex-col md:flex-row">
                <!-- Image Section -->
                <div class="md:w-1/2 h-96 md:h-auto bg-gray-200">
                    <img src="<?php echo $item['image']; ?>" class="w-full h-full object-cover" alt="Item Image">
                </div>

                <!-- Content Section -->
                <div class="md:w-1/2 p-8 flex flex-col">
                    <div class="mb-4">
                        <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?php echo $item['type'] === 'found' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                            <?php echo $item['type']; ?> Item
                        </span>
                        <h1 class="text-3xl font-extrabold text-gray-900 mt-2"><?php echo $item['title']; ?></h1>
                    </div>

                    <div class="grid grid-cols-1 gap-4 mb-8">
                        <div class="flex items-center text-gray-600">
                            <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center mr-3">
                                <i class="fas fa-tag text-cmu-blue"></i>
                            </div>
                            <div>
                                <p class="text-[10px] uppercase text-gray-400 font-bold">Category</p>
                                <p class="font-semibold"><?php echo $item['category']; ?></p>
                            </div>
                        </div>
                        <div class="flex items-center text-gray-600">
                            <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center mr-3">
                                <i class="fas fa-map-marker-alt text-cmu-blue"></i>
                            </div>
                            <div>
                                <p class="text-[10px] uppercase text-gray-400 font-bold">Location Found</p>
                                <p class="font-semibold"><?php echo $item['location']; ?></p>
                            </div>
                        </div>
                        <div class="flex items-center text-gray-600">
                            <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center mr-3">
                                <i class="fas fa-calendar-check text-cmu-blue"></i>
                            </div>
                            <div>
                                <p class="text-[10px] uppercase text-gray-400 font-bold">Date Reported</p>
                                <p class="font-semibold"><?php echo $item['date']; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-gray-50 rounded-2xl p-4 mb-8">
                        <h4 class="text-xs font-bold text-gray-400 uppercase mb-2">Item Description</h4>
                        <p class="text-gray-700 leading-relaxed italic">
                            "<?php echo $item['description']; ?>"
                        </p>
                    </div>

                    <!-- Action Section -->
                    <div class="mt-auto pt-6 border-t border-gray-100">
                        <div class="flex items-center justify-between mb-4">
                            <span class="text-xs font-medium text-gray-500 italic italic">Current Status:</span>
                            <span class="px-3 py-1 bg-cmu-blue text-white text-[10px] font-bold rounded-lg uppercase">
                                <?php echo $item['status']; ?>
                            </span>
                        </div>

                        <?php if ($item['type'] === 'found'): ?>
                            <button class="w-full bg-cmu-gold text-cmu-blue py-4 rounded-2xl font-black text-lg shadow-lg hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
                                THIS IS MINE! (CLAIM ITEM)
                            </button>
                            <p class="text-center text-[10px] text-gray-400 mt-3 px-4">
                                Note: Clicking "Claim" will notify the OSA Admin. You will be required to present valid ID and answer verification questions.
                            </p>
                        <?php else: ?>
                            <button class="w-full bg-cmu-blue text-white py-4 rounded-2xl font-black text-lg shadow-lg hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
                                I FOUND THIS ITEM
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="text-center py-10 text-gray-400 text-xs">
        &copy; 2026 City of Malabon University - Office of Student Affairs
    </footer>

</body>
</html>