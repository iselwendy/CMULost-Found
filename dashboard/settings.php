<?php
require_once '../core/auth_functions.php';

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
    "phone_number"    => $freshUser['phone_number'],
    "recovery_email"  => $freshUser['recovery_email'],
    "created_at"      => date('M Y', strtotime($freshUser['created_at'])),
    "profile_picture" => !empty($freshUser['profile_picture'])
        ? '../' . htmlspecialchars($freshUser['profile_picture'])
        : 'https://ui-avatars.com/api/?name=' . urlencode($freshUser['full_name']) . '&background=FFCC00&color=003366',
];

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
                    // Grab the old path BEFORE overwriting it
                    $old_path = $freshUser['profile_picture'] ?? null;

                    $update = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE user_id = ?");
                    $update->execute([$db_path, $user_id]);

                    // Delete old file only after DB update succeeds
                    if ($old_path && strpos($old_path, 'uploads/profiles/') === 0) {
                        $old_file = dirname(__FILE__) . '/../' . $old_path;
                        if (file_exists($old_file)) {
                            unlink($old_file);
                        }
                    }

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings | CMU Lost & Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/styles/header.css"></link>
    <link rel="stylesheet" href="../assets/styles/root.css"></link>
    <style>
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

    <!-- Navbar -->
    <?php require_once '../includes/header.php'; ?>

    <?php if (isset($_GET['status']) && $_GET['status'] === 'updated'): ?>
        <div id="success-alert" class="mb-6 p-4 bg-green-100 border border-green-200 text-green-700 rounded-xl flex items-center gap-3 transition-opacity duration-500">
            <i class="fas fa-check-circle"></i>
            <p class="text-sm font-bold">Profile updated successfully!</p>
        </div>
    <?php endif; ?>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <div class="flex flex-col md:flex-row gap-8">
            
            <!-- Sidebar Navigation -->
            <aside class="w-full md:w-64 space-y-2">
                <h2 class="text-xs font-bold text-gray-400 uppercase tracking-widest px-4 mb-4">Settings</h2>
                <nav>
                    <a href="#profile" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-cmu-blue bg-white rounded-xl shadow-sm border border-gray-100 transition">
                        <i class="fas fa-user-circle text-lg"></i>
                        Profile Information
                    </a>
                    <a href="#security" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-gray-500 hover:text-cmu-blue hover:bg-white rounded-xl transition mt-1">
                        <i class="fas fa-shield-alt text-lg"></i>
                        Security & Password
                    </a>
                    <a href="#notifications" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-gray-500 hover:text-cmu-blue hover:bg-white rounded-xl transition mt-1">
                        <i class="fas fa-bell text-lg"></i>
                        Notifications
                    </a>
                    <div class="pt-4 mt-4 border-t border-gray-200">
                        <a href="../core/logout.php" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-red-500 hover:bg-red-50 rounded-xl transition">
                            <i class="fas fa-sign-out-alt text-lg"></i>
                            Sign Out
                        </a>
                    </div>
                </nav>
            </aside>

            <!-- Settings Content -->
            <div class="flex-1 space-y-6">
                
                <!-- Profile Section -->
                <section id="profile" class="bg-white rounded-2xl shadow-sm border border-gray-50 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <h3 class="text-lg font-bold text-gray-900">Profile Information</h3>
                        <p class="text-sm text-gray-500">Update your public profile and university details.</p>
                    </div>
                    <div class="p-8">
                        <form method="POST" enctype="multipart/form-data" id="avatarForm" class="space-y-6">
                            <!-- Avatar Upload -->
                            <div class="flex items-center gap-6 pb-6 border-b border-gray-50">
                                <div class="relative">
                                    <img id="profilePreview" src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile Picture" class="w-20 h-20 rounded-2xl object-cover border-2 border-gray-100">
                                    <label class="absolute -bottom-2 -right-2 bg-cmu-blue text-white p-1.5 rounded-lg cursor-pointer shadow-md hover:scale-110 transition">
                                        <i class="fas fa-camera text-xs"></i>
                                        <input type="file" id="profile_upload" name="profile_picture" class="hidden" accept="image/jpeg,image/png,image/gif,image/webp"
                           onchange="previewAndSubmit(this)">
                                    </label>
                                </div>
                                <div>
                                    <h4 class="text-sm font-bold text-gray-800">Profile Picture</h4>
                                    <p class="text-xs text-gray-400 mt-1">JPG, PNG or GIF. Max size 2MB.</p>
                                </div>
                            </div>

                            <!-- Basic Info -->
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                                <div class="md:col-span-4">
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Full Name</label>
                                    <input type="text" value="<?php echo htmlspecialchars($user['full_name']); ?>" name="full_name" required class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-cmu-blue outline-none transition text-sm font-medium">
                                </div>

                                <div class="space-y-2 md:col-span-2">
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">
                                        Department / Office
                                    </label>
                                    <div class="relative">
                                        <input type="text" value="<?php echo htmlspecialchars($user['department']); ?>" 
                                            disabled 
                                            class="w-full px-4 py-3 bg-gray-100 border border-gray-200 rounded-xl text-gray-500 text-sm font-medium appearance-none cursor-not-allowed">
                                        <div class="absolute inset-y-0 right-4 flex items-center pointer-events-none text-gray-400">
                                            <i class="fas fa-lock text-xs"></i>
                                        </div>
                                    </div>
                                    <p class="text-[10px] text-gray-400 mt-1 italic">
                                        * Contact OSA to update your official university department.
                                    </p>
                                </div>

                                <div class="space-y-2 md:col-span-1">
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">
                                        School Number
                                    </label>
                                    <div class="relative">
                                        <input type="text" value="<?php echo htmlspecialchars($user['school_number']); ?>" disabled 
                                            class="w-full px-4 py-3 bg-gray-100 border border-gray-200 rounded-xl text-gray-500 text-sm font-medium appearance-none cursor-not-allowed">
                                        <div class="absolute inset-y-0 right-4 flex items-center pointer-events-none text-gray-400">
                                            <i class="fas fa-lock text-xs"></i>
                                        </div>
                                    </div>
                                </div>

                                <div class="space-y-2 md:col-span-1">
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">
                                        Course & Year
                                    </label>
                                    <div class="relative">
                                        <input type="text" value="<?php echo htmlspecialchars($user['course_and_year']); ?>" disabled 
                                            class="w-full px-4 py-3 bg-gray-100 border border-gray-200 rounded-xl text-gray-500 text-sm font-medium appearance-none cursor-not-allowed">
                                        </select>
                                        <div class="absolute inset-y-0 right-4 flex items-center pointer-events-none text-gray-400">
                                            <i class="fas fa-lock text-xs"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Contact Info Section -->
                            <div class="pt-6 border-t border-gray-50">
                                <h4 class="text-sm font-bold text-gray-900 mb-4">Contact Details</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">CMU Email Address</label>
                                        <div class="relative">
                                            <i class="fas fa-university absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                            <input type="email" value="<?php echo htmlspecialchars($user['cmu_email'] ?? ''); ?>" class="w-full pl-11 pr-4 py-3 bg-gray-100 border border-gray-200 rounded-xl text-gray-500 outline-none text-sm font-medium cursor-not-allowed" readonly>
                                            <div class="absolute inset-y-0 right-4 flex items-center pointer-events-none text-gray-400">
                                                <i class="fas fa-lock text-xs"></i>
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Secondary Email</label>
                                        <div class="relative">
                                            <i class="fas fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                            <input type="email" name="recovery_email" placeholder="e.g. personal@gmail.com" required
                                            value="<?php echo htmlspecialchars($user['recovery_email'] ?? ''); ?>"
                                            class="w-full pl-11 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-cmu-blue outline-none transition text-sm font-medium">
                                        </div>
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Phone Number</label>
                                        <div class="relative">
                                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-sm font-bold text-gray-400">+63</span>
                                            <input type="tel" name="phone_number" placeholder="912 345 6789" required
                                            value="<?php echo htmlspecialchars(ltrim($user['phone_number'] ?? '', '0')); ?>"
                                            class="w-full pl-14 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-cmu-blue outline-none transition text-sm font-medium">
                                        </div>
                                        <p class="text-[10px] text-gray-400 mt-1">This will be used by OSA to contact you regarding matched items.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="pt-4 flex justify-end">
                                <button type="submit" class="bg-cmu-blue text-white px-8 py-3 rounded-xl font-bold text-sm shadow-sm hover:shadow-lg transition">
                                    Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </section>

                <!-- Security Section -->
                <section id="security" class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <h3 class="text-lg font-bold text-gray-900">Security & Password</h3>
                        <p class="text-sm text-gray-500">Manage your password and account protection.</p>
                    </div>
                    <div class="p-8 space-y-8">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="md:col-span-2">
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Current Password</label>
                                <input type="password" placeholder="••••••••" class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-cmu-blue outline-none transition">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">New Password</label>
                                <input type="password" class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-cmu-blue outline-none transition">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Confirm New Password</label>
                                <input type="password" class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-cmu-blue outline-none transition">
                            </div>
                        </div>

                        <div class="pt-6 border-t border-gray-50">
                            <h4 class="text-sm font-bold text-gray-800 mb-4">Account Protection</h4>
                            <div class="flex items-center justify-between p-4 bg-blue-50 rounded-xl border border-blue-100">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 bg-white rounded-lg flex items-center justify-center text-cmu-blue shadow-sm">
                                        <i class="fas fa-mobile-alt"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-gray-800">Two-Factor Authentication</p>
                                        <p class="text-xs text-gray-500">Secure your account with a code from your mobile.</p>
                                    </div>
                                </div>
                                <button class="px-4 py-2 bg-white text-cmu-blue text-xs font-bold rounded-lg border border-blue-200 hover:bg-blue-100 transition">
                                    Enable
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Notifications Section -->
                <section id="notifications" class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <h3 class="text-lg font-bold text-gray-900">Notifications</h3>
                        <p class="text-sm text-gray-500">Choose what updates you want to receive.</p>
                    </div>
                    <div class="p-8">
                        <div class="space-y-6">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-bold text-gray-800">Item Match Alerts</p>
                                    <p class="text-xs text-gray-500">Get notified when a found item matches your lost report.</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" checked class="sr-only peer">
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-cmu-blue"></div>
                                </label>
                            </div>
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-bold text-gray-800">Status Updates</p>
                                    <p class="text-xs text-gray-500">Get notified when your reported item status changes.</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" checked class="sr-only peer">
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-cmu-blue"></div>
                                </label>
                            </div>
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-bold text-gray-800">System Announcements</p>
                                    <p class="text-xs text-gray-500">Updates regarding campus-wide OSA procedures.</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" class="sr-only peer">
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-cmu-blue"></div>
                                </label>
                            </div>
                        </div>
                    </div>
                </section>

            </div>
        </div>
    </main>

    <!-- Footer -->
    <?php require_once '../includes/footer.php'; ?>

    <script src="../assets/scripts/profile-dropdown.js"></script>
    <script src="../assets/scripts/profile.js"></script>
    <script>
        // Auto-hide success alert after 3 seconds
        const successAlert = document.getElementById('success-alert');
        if (successAlert) {
            setTimeout(() => {
                successAlert.style.opacity = '0';
                setTimeout(() => successAlert.remove(), 500);
            }, 3000);
        }
    </script>
</body>
</html>