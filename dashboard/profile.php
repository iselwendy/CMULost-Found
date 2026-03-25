<?php
require_once '../core/auth_functions.php';

// ── Guard: must be logged in ──────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header("Location: ../core/auth.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// ── Fetch fresh user data ─────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$freshUser = $stmt->fetch();

if (!$freshUser) {
    session_destroy();
    header("Location: ../core/auth.php");
    exit();
}

$user = [
    "user_id"         => $freshUser['user_id'],
    "full_name"       => $freshUser['full_name'],
    "cmu_email"       => $freshUser['cmu_email'],
    "school_number"   => $freshUser['school_number'],
    "department"      => $freshUser['department'],
    "course_and_year" => $freshUser['course_and_year'],
    "role"            => $freshUser['role'],
    "phone_number"    => $freshUser['phone_number'] ?? '',
    "created_at"      => date('M Y', strtotime($freshUser['created_at'])),
    "profile_picture" => !empty($freshUser['profile_picture'])
                            ? '../' . htmlspecialchars($freshUser['profile_picture'])
                            : 'https://ui-avatars.com/api/?name=' . urlencode($freshUser['full_name']) . '&background=FFCC00&color=003366',
];

// ── Aggregate stats ───────────────────────────────────────────
try {
    // Count found reports submitted by this user
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM found_reports WHERE reported_by = ?");
    $stmt->execute([$user_id]);
    $total_found = (int) $stmt->fetchColumn();

    // Count lost reports submitted by this user
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM lost_reports WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $total_lost = (int) $stmt->fetchColumn();

    $total_reports = $total_found + $total_lost;

    // Count successful returns: found items with status 'returned' OR lost items with status 'returned'
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM found_reports WHERE reported_by = ? AND status = 'returned'");
    $stmt->execute([$user_id]);
    $returned_found = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM lost_reports WHERE user_id = ? AND status = 'returned'");
    $stmt->execute([$user_id]);
    $returned_lost = (int) $stmt->fetchColumn();

    $successful_returns = $returned_found + $returned_lost;

} catch (PDOException $e) {
    $total_reports    = 0;
    $successful_returns = 0;
    $total_found      = 0;
    $total_lost       = 0;
}

// ── Fetch found items reported by this user ───────────────────
try {
    $stmt = $pdo->prepare("
        SELECT
            f.found_id      AS id,
            f.title,
            c.name          AS category,
            loc.location_name AS location,
            f.status,
            f.date_found    AS date,
            img.image_path
        FROM found_reports f
        LEFT JOIN categories c   ON f.category_id = c.category_id
        LEFT JOIN locations loc  ON f.location_id  = loc.location_id
        LEFT JOIN item_images img ON f.found_id = img.report_id AND img.report_type = 'found'
        WHERE f.reported_by = ?
        ORDER BY f.date_found DESC
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    $found_items = $stmt->fetchAll();
} catch (PDOException $e) {
    $found_items = [];
}

// ── Fetch lost reports by this user ──────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT
            l.lost_id       AS id,
            l.title,
            c.name          AS category,
            loc.location_name AS location,
            l.status,
            l.date_lost     AS date,
            img.image_path
        FROM lost_reports l
        LEFT JOIN categories c   ON l.category_id = c.category_id
        LEFT JOIN locations loc  ON l.location_id  = loc.location_id
        LEFT JOIN item_images img ON l.lost_id = img.report_id AND img.report_type = 'lost'
        WHERE l.user_id = ?
        ORDER BY l.date_lost DESC
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    $lost_items = $stmt->fetchAll();
} catch (PDOException $e) {
    $lost_items = [];
}

// ── Handle profile picture upload ────────────────────────────
$upload_message = '';
$upload_error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_picture'])) {
    $file = $_FILES['profile_picture'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowed_types)) {
            $upload_error = 'Invalid file type. Please upload a JPG, PNG, GIF, or WebP image.';
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $upload_error = 'File is too large. Maximum size is 2MB.';
        } else {
            $upload_dir = dirname(__FILE__) . '/../uploads/profiles/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $ext          = pathinfo($file['name'], PATHINFO_EXTENSION);
            $new_filename = 'profile_' . $user_id . '_' . time() . '.' . $ext;
            $target_path  = $upload_dir . $new_filename;

            if (move_uploaded_file($file['tmp_name'], $target_path)) {
                $db_path = 'uploads/profiles/' . $new_filename;

                try {
                    $update = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE user_id = ?");
                    $update->execute([$db_path, $user_id]);
                    $_SESSION['profile_picture'] = '../' . $db_path;
                    $user['profile_picture']     = '../' . $db_path;
                    $upload_message = 'Profile picture updated successfully!';
                } catch (PDOException $e) {
                    $upload_error = 'Failed to save profile picture. Please try again.';
                }
            } else {
                $upload_error = 'Failed to upload file. Please try again.';
            }
        }
    } elseif ($file['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload_error = 'Upload error. Please try again.';
    }
}

// ── Helper: status badge classes ─────────────────────────────
function statusBadgeClass(string $status): string {
    return match (strtolower($status)) {
        'returned'          => 'bg-green-100 text-green-700',
        'in custody'        => 'bg-blue-100 text-blue-700',
        'pending turnover'  => 'bg-amber-100 text-amber-700',
        'open'              => 'bg-indigo-100 text-indigo-700',
        'matched'           => 'bg-purple-100 text-purple-700',
        default             => 'bg-slate-100 text-slate-500',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | CMU Lost & Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="../assets/images/system-icon.png">
    <link rel="stylesheet" href="../assets/styles/header.css">
    <link rel="stylesheet" href="../assets/styles/root.css">
    <style>
        .tab-btn.active {
            border-bottom: 3px solid var(--cmu-blue);
            color: var(--cmu-blue);
            font-weight: 700;
        }
        .tab-btn {
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
        }

        /* Profile picture hover overlay */
        .avatar-wrapper:hover .avatar-overlay { opacity: 1; }
        .avatar-overlay { transition: opacity 0.2s; }

        /* Shimmer loading for stats */
        @keyframes shimmer {
            0%   { background-position: -200% 0; }
            100% { background-position:  200% 0; }
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php require_once '../includes/header.php'; ?>

    <!-- ── Flash messages ─────────────────────────────────── -->
    <?php if ($upload_message): ?>
        <div id="flashMsg" class="fixed top-20 left-1/2 -translate-x-1/2 z-50 flex items-center gap-3 bg-green-600 text-white px-6 py-3 rounded-2xl shadow-xl text-sm font-bold">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($upload_message); ?>
        </div>
    <?php elseif ($upload_error): ?>
        <div id="flashMsg" class="fixed top-20 left-1/2 -translate-x-1/2 z-50 flex items-center gap-3 bg-red-600 text-white px-6 py-3 rounded-2xl shadow-xl text-sm font-bold">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($upload_error); ?>
        </div>
    <?php endif; ?>

    <!-- ── Profile Header ─────────────────────────────────── -->
    <div class="bg-white border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
            <div class="flex flex-col md:flex-row items-center md:items-start gap-8">

                <!-- Avatar with upload -->
                <form method="POST" enctype="multipart/form-data" id="avatarForm">
                    <div class="relative avatar-wrapper cursor-pointer group" onclick="document.getElementById('profile_upload').click()">
                        <div class="w-32 h-32 rounded-2xl overflow-hidden border-4 border-white shadow-xl bg-gray-100">
                            <img id="profilePreview"
                                src="<?php echo $user['profile_picture']; ?>"
                                alt="Profile picture"
                                class="w-full h-full object-cover">
                        </div>
                        <!-- Hover overlay -->
                        <div class="avatar-overlay opacity-0 absolute inset-0 bg-black/50 rounded-2xl flex flex-col items-center justify-center text-white gap-1">
                            <i class="fas fa-camera text-lg"></i>
                            <span class="text-[10px] font-bold uppercase tracking-wide">Change</span>
                        </div>
                        <!-- Camera badge -->
                        <div class="absolute -bottom-2 -right-2 bg-cmu-blue text-white p-2 rounded-xl shadow-md border-2 border-white pointer-events-none">
                            <i class="fas fa-camera text-xs"></i>
                        </div>
                    </div>
                    <input type="file" id="profile_upload" name="profile_picture"
                           class="hidden" accept="image/jpeg,image/png,image/gif,image/webp"
                           onchange="previewAndSubmit(this)">
                </form>

                <!-- User info & stats -->
                <div class="flex-1 text-center md:text-left">
                    <div class="flex flex-col md:flex-row md:items-center gap-3 mb-2">
                        <h1 class="text-3xl font-bold text-gray-900"><?php echo htmlspecialchars($user['full_name']); ?></h1>
                        <span class="self-start px-3 py-1 bg-blue-50 text-cmu-blue text-xs font-bold rounded-full border border-blue-100 uppercase tracking-wider">
                            <?php echo htmlspecialchars($user['role']); ?>
                        </span>
                    </div>

                    <p class="text-gray-500 flex flex-wrap items-center justify-center md:justify-start gap-x-3 gap-y-1 mb-5 text-sm">
                        <span><i class="far fa-envelope mr-1"></i><?php echo htmlspecialchars($user['cmu_email']); ?></span>
                        <span class="text-gray-300 hidden md:inline">|</span>
                        <span><i class="far fa-id-badge mr-1"></i><?php echo htmlspecialchars($user['school_number']); ?></span>
                        <?php if ($user['phone_number']): ?>
                            <span class="text-gray-300 hidden md:inline">|</span>
                            <span><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($user['phone_number']); ?></span>
                        <?php endif; ?>
                    </p>

                    <!-- Stats row -->
                    <div class="flex flex-wrap justify-center md:justify-start gap-4">
                        <div class="bg-gray-50 px-5 py-3 rounded-xl border border-gray-100 text-center">
                            <p class="text-[10px] uppercase text-gray-400 font-bold leading-none mb-1">Total Reports</p>
                            <p class="text-2xl font-black text-gray-800"><?php echo $total_reports; ?></p>
                        </div>
                        <div class="bg-gray-50 px-5 py-3 rounded-xl border border-gray-100 text-center">
                            <p class="text-[10px] uppercase text-gray-400 font-bold leading-none mb-1">Found Reports</p>
                            <p class="text-2xl font-black text-blue-600"><?php echo $total_found; ?></p>
                        </div>
                        <div class="bg-gray-200 px-5 py-3 rounded-xl border border-gray-100 text-center">
                            <p class="text-[10px] uppercase text-gray-400 font-bold leading-none mb-1">Successful Returns</p>
                            <p class="text-2xl font-black text-green-600"><?php echo $successful_returns; ?></p>
                        </div>
                        <div class="bg-gray-50 px-5 py-3 rounded-xl border border-gray-100 text-center">
                            <p class="text-[10px] uppercase text-gray-400 font-bold leading-none mb-1">Member Since</p>
                            <p class="text-lg font-black text-gray-800"><?php echo $user['created_at']; ?></p>
                        </div>
                    </div>
                </div>

                <!-- Action buttons -->
                <div class="flex flex-wrap gap-3 flex-shrink-0">
                    <a href="../dashboard/settings.php"
                       class="inline-flex items-center gap-2 bg-cmu-blue text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-slate-800 hover:shadow-lg active:scale-95 transition-all shadow-sm">
                        <i class="fas fa-cog"></i> Account Settings
                    </a>
                    <a href="../dashboard/my_reports.php"
                       class="inline-flex items-center gap-2 bg-white border border-gray-200 text-gray-700 px-6 py-3 rounded-xl font-bold text-sm hover:bg-gray-50 transition-all shadow-sm">
                        <i class="fas fa-clipboard-list text-indigo-500"></i> My Reports
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Main grid ──────────────────────────────────────── -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            <!-- ── Left sidebar ──────────────────────────── -->
            <div class="space-y-6">

                <!-- University details card -->
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
                    <h2 class="text-xs font-black text-gray-400 uppercase tracking-widest mb-5">University Details</h2>
                    <div class="space-y-4">
                        <div>
                            <p class="text-[10px] text-gray-400 font-bold uppercase mb-1">Department</p>
                            <p class="text-gray-800 font-semibold text-sm">
                                <?php echo $user['department'] ? htmlspecialchars($user['department']) : '<span class="text-gray-400 italic">Not set</span>'; ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-[10px] text-gray-400 font-bold uppercase mb-1">Course & Year</p>
                            <p class="text-gray-800 font-semibold text-sm">
                                <?php echo $user['course_and_year'] ? htmlspecialchars($user['course_and_year']) : '<span class="text-gray-400 italic">Not set</span>'; ?>
                            </p>
                        </div>
                        <div class="pt-4 border-t border-gray-50">
                            <a href="../dashboard/settings.php" class="text-cmu-blue text-sm font-bold hover:underline flex items-center gap-2">
                                <i class="fas fa-pencil-alt text-xs"></i> Edit Profile Details
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Activity summary -->
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
                    <h2 class="text-xs font-black text-gray-400 uppercase tracking-widest mb-5">Activity Summary</h2>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-600 flex items-center gap-2">
                                <i class="fas fa-hand-holding-heart text-green-400 w-4"></i> Items Found & Reported
                            </span>
                            <span class="font-black text-gray-800"><?php echo $total_found; ?></span>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-600 flex items-center gap-2">
                                <i class="fas fa-search text-red-400 w-4"></i> Lost Items Reported
                            </span>
                            <span class="font-black text-gray-800"><?php echo $total_lost; ?></span>
                        </div>
                        <div class="flex items-center justify-between text-sm border-t border-gray-150 pt-3 mt-3">
                            <span class="text-gray-600 flex items-center gap-2">
                                <i class="fas fa-check-circle text-green-500 w-4"></i> Successful Returns
                            </span>
                            <span class="font-black text-green-600"><?php echo $successful_returns; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Help card -->
                <div class="bg-cmu-blue p-6 rounded-2xl shadow-lg text-white relative overflow-hidden">
                    <div class="relative z-10">
                        <h3 class="font-bold text-lg mb-2">Need Help?</h3>
                        <p class="text-blue-100 text-sm mb-4">Contact the Student Affairs Office for immediate assistance with valuable items.</p>
                        <a href="#" class="inline-block bg-cmu-gold text-cmu-blue px-4 py-2 rounded-lg font-bold text-xs uppercase tracking-wider hover:opacity-90 transition">
                            Contact SAO
                        </a>
                    </div>
                    <i class="fas fa-headset absolute -bottom-4 -right-4 text-white/10 text-8xl"></i>
                </div>
            </div>

            <!-- ── Right: Tabbed content ──────────────────── -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">

                    <!-- Tab nav -->
                    <div class="flex border-b border-gray-100 bg-gray-50/50">
                        <button id="tab-found" onclick="switchTab('found')"
                                class="tab-btn active flex-1 py-4 px-6 text-sm font-bold text-gray-700 hover:text-cmu-blue transition flex items-center justify-center gap-2">
                            <i class="fas fa-hand-holding-heart text-green-500"></i>
                            Found Reports
                            <span class="ml-1 bg-green-100 text-green-700 text-[10px] px-2 py-0.5 rounded-full font-black">
                                <?php echo count($found_items); ?>
                            </span>
                        </button>
                        <button id="tab-lost" onclick="switchTab('lost')"
                                class="tab-btn flex-1 py-4 px-6 text-sm font-bold text-gray-400 hover:text-cmu-blue transition flex items-center justify-center gap-2">
                            <i class="fas fa-search text-red-400"></i>
                            Lost Reports
                            <span class="ml-1 bg-red-100 text-red-600 text-[10px] px-2 py-0.5 rounded-full font-black">
                                <?php echo count($lost_items); ?>
                            </span>
                        </button>
                    </div>

                    <!-- ── Found items panel ──────────────── -->
                    <div id="panel-found" class="p-4">
                        <?php if (empty($found_items)): ?>
                            <div class="text-center py-16">
                                <div class="w-16 h-16 bg-green-50 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-hand-holding-heart text-green-200 text-2xl"></i>
                                </div>
                                <h3 class="font-bold text-gray-700 mb-1">No Found Reports Yet</h3>
                                <p class="text-sm text-gray-400 mb-4">Found something on campus? Let us know!</p>
                                <a href="../public/report_found.php"
                                   class="inline-flex items-center gap-2 bg-cmu-blue text-white px-5 py-2.5 rounded-xl text-sm font-bold hover:bg-slate-800 transition">
                                    <i class="fas fa-plus"></i> Report Found Item
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="divide-y divide-gray-50">
                                <?php foreach ($found_items as $item):
                                    $img_src = !empty($item['image_path'])
                                        ? '../' . htmlspecialchars($item['image_path'])
                                        : null;
                                    $status_display = ucwords(str_replace('_', ' ', $item['status'] ?? 'unknown'));
                                    $badge_class    = statusBadgeClass($item['status'] ?? '');
                                    $date_formatted = date('M d, Y', strtotime($item['date']));
                                ?>
                                <div class="p-4 hover:bg-gray-50 rounded-xl transition group">
                                    <div class="flex items-center gap-4">
                                        <!-- Thumbnail -->
                                        <div class="w-16 h-16 bg-gray-100 rounded-lg flex items-center justify-center text-gray-300 overflow-hidden shrink-0 border border-gray-100">
                                            <?php if ($img_src): ?>
                                                <img src="<?php echo $img_src; ?>" alt="Item" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <i class="fas fa-image text-xl"></i>
                                            <?php endif; ?>
                                        </div>

                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-start justify-between gap-2 mb-1">
                                                <div class="min-w-0">
                                                    <p class="text-[10px] font-bold text-cmu-blue uppercase tracking-tight">
                                                        <?php echo htmlspecialchars($item['category'] ?? 'Uncategorized'); ?>
                                                    </p>
                                                    <h4 class="font-bold text-gray-800 group-hover:text-cmu-blue transition truncate">
                                                        <?php echo htmlspecialchars($item['title']); ?>
                                                    </h4>
                                                </div>
                                                <span class="shrink-0 px-2 py-0.5 rounded text-[10px] font-bold uppercase <?php echo $badge_class; ?>">
                                                    <?php echo $status_display; ?>
                                                </span>
                                            </div>
                                            <div class="flex flex-wrap gap-3 mt-1">
                                                <span class="text-xs text-gray-400">
                                                    <i class="fas fa-map-marker-alt mr-1"></i><?php echo htmlspecialchars($item['location'] ?? 'Unknown'); ?>
                                                </span>
                                                <span class="text-xs text-gray-400">
                                                    <i class="fas fa-calendar-alt mr-1"></i><?php echo $date_formatted; ?>
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Actions -->
                                        <div class="flex gap-1 shrink-0">
                                            <?php if (strtolower($item['status'] ?? '') === 'pending turnover'): ?>
                                                <button title="Get QR Code"
                                                        class="p-2 text-cmu-blue hover:bg-blue-50 rounded-lg transition text-sm"
                                                        onclick="window.location.href='../dashboard/my_reports.php'">
                                                    <i class="fas fa-qrcode"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button title="Delete report"
                                                    class="p-2 text-gray-300 hover:text-red-500 hover:bg-red-50 rounded-lg transition text-sm"
                                                    onclick="confirmDelete('found', <?php echo (int)$item['id']; ?>)">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="mt-4 text-center">
                                <a href="../dashboard/my_reports.php"
                                   class="text-xs font-bold text-gray-400 hover:text-cmu-blue uppercase tracking-widest">
                                    View All in Dashboard <i class="fas fa-arrow-right ml-1"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ── Lost items panel ───────────────── -->
                    <div id="panel-lost" class="p-4 hidden">
                        <?php if (empty($lost_items)): ?>
                            <div class="text-center py-16">
                                <div class="w-16 h-16 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-search text-red-200 text-2xl"></i>
                                </div>
                                <h3 class="font-bold text-gray-700 mb-1">No Lost Reports Yet</h3>
                                <p class="text-sm text-gray-400 mb-4">Lost something? File a report so we can help find it.</p>
                                <a href="../public/report_lost.php"
                                   class="inline-flex items-center gap-2 bg-indigo-600 text-white px-5 py-2.5 rounded-xl text-sm font-bold hover:bg-indigo-700 transition">
                                    <i class="fas fa-plus"></i> Report Lost Item
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="divide-y divide-gray-50">
                                <?php foreach ($lost_items as $item):
                                    $img_src = !empty($item['image_path'])
                                        ? '../' . htmlspecialchars($item['image_path'])
                                        : null;
                                    $status_display = ucwords(str_replace('_', ' ', $item['status'] ?? 'unknown'));
                                    $badge_class    = statusBadgeClass($item['status'] ?? '');
                                    $date_formatted = date('M d, Y', strtotime($item['date']));
                                ?>
                                <div class="p-4 hover:bg-gray-50 rounded-xl transition group">
                                    <div class="flex items-center gap-4">
                                        <div class="w-16 h-16 bg-gray-100 rounded-lg flex items-center justify-center text-gray-300 overflow-hidden shrink-0 border border-gray-100">
                                            <?php if ($img_src): ?>
                                                <img src="<?php echo $img_src; ?>" alt="Item" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <i class="fas fa-image text-xl"></i>
                                            <?php endif; ?>
                                        </div>

                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-start justify-between gap-2 mb-1">
                                                <div class="min-w-0">
                                                    <p class="text-[10px] font-bold text-indigo-500 uppercase tracking-tight">
                                                        <?php echo htmlspecialchars($item['category'] ?? 'Uncategorized'); ?>
                                                    </p>
                                                    <h4 class="font-bold text-gray-800 group-hover:text-cmu-blue transition truncate">
                                                        <?php echo htmlspecialchars($item['title']); ?>
                                                    </h4>
                                                </div>
                                                <span class="shrink-0 px-2 py-0.5 rounded text-[10px] font-bold uppercase <?php echo $badge_class; ?>">
                                                    <?php echo $status_display; ?>
                                                </span>
                                            </div>
                                            <div class="flex flex-wrap gap-3 mt-1">
                                                <span class="text-xs text-gray-400">
                                                    <i class="fas fa-map-marker-alt mr-1"></i><?php echo htmlspecialchars($item['location'] ?? 'Unknown'); ?>
                                                </span>
                                                <span class="text-xs text-gray-400">
                                                    <i class="fas fa-calendar-alt mr-1"></i><?php echo $date_formatted; ?>
                                                </span>
                                            </div>
                                        </div>

                                        <div class="flex gap-1 shrink-0">
                                            <?php if (strtolower($item['status'] ?? '') === 'open' || strtolower($item['status'] ?? '') === 'matched'): ?>
                                                <a href="../dashboard/my_reports.php?tab=matches"
                                                   title="View Matches"
                                                   class="p-2 text-indigo-400 hover:bg-indigo-50 rounded-lg transition text-sm">
                                                    <i class="fas fa-bolt"></i>
                                                </a>
                                            <?php endif; ?>
                                            <button title="Delete report"
                                                    class="p-2 text-gray-300 hover:text-red-500 hover:bg-red-50 rounded-lg transition text-sm"
                                                    onclick="confirmDelete('lost', <?php echo (int)$item['id']; ?>)">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="mt-4 text-center">
                                <a href="../dashboard/my_reports.php"
                                   class="text-xs font-bold text-gray-400 hover:text-cmu-blue uppercase tracking-widest">
                                    View All in Dashboard <i class="fas fa-arrow-right ml-1"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        </div>
    </main>

    <!-- ── Delete confirmation modal ─────────────────────── -->
    <div id="deleteModal" class="fixed inset-0 z-50 hidden bg-slate-900/70 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-sm w-full p-8 shadow-2xl text-center">
            <div class="w-14 h-14 bg-red-100 text-red-500 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-trash-alt text-xl"></i>
            </div>
            <h3 class="text-lg font-bold text-gray-800 mb-2">Delete Report?</h3>
            <p class="text-sm text-gray-500 mb-6">This action cannot be undone. The report and its data will be permanently removed.</p>
            <div class="flex gap-3">
                <button onclick="closeDeleteModal()"
                        class="flex-1 py-3 bg-gray-100 text-gray-600 rounded-xl font-bold text-sm hover:bg-gray-200 transition">
                    Cancel
                </button>
                <a id="deleteConfirmBtn" href="#"
                   class="flex-1 py-3 bg-red-500 text-white rounded-xl font-bold text-sm hover:bg-red-600 transition">
                    Delete
                </a>
            </div>
        </div>
    </div>

    <?php require_once '../includes/footer.php'; ?>

    <script src="../assets/scripts/profile-dropdown.js"></script>
    <script src="../assets/scripts/profile.js"></script>
</body>
</html>