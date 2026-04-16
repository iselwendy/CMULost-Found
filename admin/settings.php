<?php
/**
 * CMU Lost & Found — Admin Settings
 * admin/settings.php
 */

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../core/auth.php");
    exit();
}

require_once '../core/db_config.php';

$admin_id   = (int) $_SESSION['user_id'];
$admin_name = htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['user_name'] ?? 'Admin');

// ── Bootstrap settings table (idempotent) ─────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            setting_key   VARCHAR(100) PRIMARY KEY,
            setting_value TEXT         NOT NULL,
            updated_by    INT          DEFAULT NULL,
            updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Bootstrap admin_action_log table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_action_log (
            log_id       INT           PRIMARY KEY AUTO_INCREMENT,
            admin_id     INT           NOT NULL,
            action_type  VARCHAR(100)  NOT NULL,
            target_type  VARCHAR(50)   DEFAULT NULL,
            target_id    INT           DEFAULT NULL,
            description  TEXT          DEFAULT NULL,
            created_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin (admin_id),
            INDEX idx_type (action_type),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException) { /* already exists */ }

// ── Default settings ─────────────────────────────────────────────────────
$defaults = [
    'gallery_open'              => '1',
    'auto_notify_threshold'     => '80',
    'review_queue_threshold'    => '30',
    'duplicate_check_enabled'   => '1',
    'weight_category'           => '30',
    'weight_location'           => '25',
    'weight_keywords'           => '30',
    'weight_date'               => '15',
    'email_match_template'      => "Hi {name},\n\nOur matching engine has found a potential match for your lost {item}. It was found near {location}.\n\nPlease visit the Student Affairs Office (SAO) with a valid school ID to verify and claim your item.\n\nItems are held for a maximum of 60 calendar days.\n\nThank you,\nCMU Student Affairs Office",
];

// Insert defaults if not present
foreach ($defaults as $key => $value) {
    $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)")
        ->execute([$key, $value]);
}

// ── Load current settings ─────────────────────────────────────────────────
$settings = [];
$rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll();
foreach ($rows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$settings = array_merge($defaults, $settings);

// ── Handle POST saves ─────────────────────────────────────────────────────
$save_success = null;
$save_error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        try {
            $keys = [
                'gallery_open', 'auto_notify_threshold', 'review_queue_threshold',
                'duplicate_check_enabled', 'weight_category', 'weight_location',
                'weight_keywords', 'weight_date', 'email_match_template',
            ];
            $stmt = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value, updated_by)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)
            ");
            foreach ($keys as $key) {
                if (isset($_POST[$key])) {
                    $stmt->execute([$key, $_POST[$key], $admin_id]);
                }
            }

            // Log action
            $pdo->prepare("INSERT INTO admin_action_log (admin_id, action_type, description) VALUES (?, 'settings_updated', 'Admin updated system settings')")
                ->execute([$admin_id]);

            $save_success = 'Settings saved successfully.';
            // Reload settings
            $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll();
            foreach ($rows as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            $save_error = 'Failed to save settings: ' . $e->getMessage();
        }
    }

    elseif ($action === 'change_password') {
        $current  = $_POST['current_password']  ?? '';
        $new_pw   = $_POST['new_password']       ?? '';
        $confirm  = $_POST['confirm_password']   ?? '';

        if (strlen($new_pw) < 8) {
            $save_error = 'New password must be at least 8 characters.';
        } elseif ($new_pw !== $confirm) {
            $save_error = 'New passwords do not match.';
        } else {
            $stmt = $pdo->prepare("SELECT password FROM users WHERE user_id = ? LIMIT 1");
            $stmt->execute([$admin_id]);
            $hash = $stmt->fetchColumn();

            if (!password_verify($current, $hash)) {
                $save_error = 'Current password is incorrect.';
            } else {
                $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?")
                    ->execute([password_hash($new_pw, PASSWORD_BCRYPT), $admin_id]);
                $pdo->prepare("INSERT INTO admin_action_log (admin_id, action_type, description) VALUES (?, 'password_changed', 'Admin changed their own password')")
                    ->execute([$admin_id]);
                $save_success = 'Password changed successfully.';
            }
        }
    }

    elseif ($action === 'toggle_admin') {
        $target_id = (int)($_POST['target_id'] ?? 0);
        $new_role  = $_POST['new_role'] ?? '';

        if ($target_id && $target_id !== $admin_id && in_array($new_role, ['admin', 'student'])) {
            $pdo->prepare("UPDATE users SET role = ? WHERE user_id = ?")
                ->execute([$new_role, $target_id]);
            $desc = $new_role === 'student'
                ? "Revoked admin access from user #$target_id"
                : "Restored admin access to user #$target_id";
            $pdo->prepare("INSERT INTO admin_action_log (admin_id, action_type, target_type, target_id, description) VALUES (?, 'admin_toggled', 'user', ?, ?)")
                ->execute([$admin_id, $target_id, $desc]);
            $save_success = "Admin account updated.";
        }
    }

    elseif ($action === 'save_location') {
        $loc_name = trim($_POST['location_name'] ?? '');
        if ($loc_name) {
            try {
                $pdo->prepare("INSERT INTO locations (location_name) VALUES (?)")
                    ->execute([$loc_name]);
                $pdo->prepare("INSERT INTO admin_action_log (admin_id, action_type, description) VALUES (?, 'location_added', ?)")
                    ->execute([$admin_id, "Added location: $loc_name"]);
                $save_success = "Location \"$loc_name\" added.";
            } catch (Throwable $e) {
                $save_error = 'Could not add location: ' . $e->getMessage();
            }
        }
    }

    elseif ($action === 'rename_location') {
        $loc_id   = (int)($_POST['loc_id']   ?? 0);
        $loc_name = trim($_POST['location_name'] ?? '');
        if ($loc_id && $loc_name) {
            $pdo->prepare("UPDATE locations SET location_name = ? WHERE location_id = ?")
                ->execute([$loc_name, $loc_id]);
            $pdo->prepare("INSERT INTO admin_action_log (admin_id, action_type, description) VALUES (?, 'location_renamed', ?)")
                ->execute([$admin_id, "Renamed location #$loc_id to: $loc_name"]);
            $save_success = "Location renamed.";
        }
    }

    elseif ($action === 'trigger_aging') {
        // Just log for now — the actual scan happens in archive.php
        $pdo->prepare("INSERT INTO admin_action_log (admin_id, action_type, description) VALUES (?, 'aging_scan_triggered', 'Manual aging report scan triggered')")
            ->execute([$admin_id]);
        $save_success = 'Aging scan triggered. Check the Records Archive for results.';
    }

    // Redirect to avoid re-POST
    if ($save_success) {
        $_SESSION['settings_msg'] = ['type' => 'success', 'text' => $save_success];
    } elseif ($save_error) {
        $_SESSION['settings_msg'] = ['type' => 'error', 'text' => $save_error];
    }
    header("Location: settings.php#" . ($_POST['scroll_target'] ?? ''));
    exit();
}

// Pick up flash messages
if (isset($_SESSION['settings_msg'])) {
    $flash = $_SESSION['settings_msg'];
    unset($_SESSION['settings_msg']);
}

// ── Load data ─────────────────────────────────────────────────────────────
$all_admins = $pdo->query("
    SELECT user_id, full_name, cmu_email, department, created_at
    FROM users WHERE role = 'admin'
    ORDER BY created_at ASC
")->fetchAll();

$locations = $pdo->query("SELECT * FROM locations ORDER BY location_id ASC")->fetchAll();

$action_log = $pdo->query("
    SELECT l.*, u.full_name AS admin_name
    FROM admin_action_log l
    LEFT JOIN users u ON u.user_id = l.admin_id
    ORDER BY l.created_at DESC
    LIMIT 50
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Settings | CMU Lost & Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/styles/root.css">
    <link rel="stylesheet" href="../assets/styles/admin_dashboard.css">
    <style>
        .settings-section {
            scroll-margin-top: 80px;
        }
        .toggle-switch input:checked ~ .dot {
            transform: translateX(20px);
        }
        .toggle-switch input:checked ~ .track {
            background-color: #003366;
        }
        .weight-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 18px; height: 18px;
            background: #003366;
            border-radius: 50%;
            cursor: pointer;
            border: 2px solid white;
            box-shadow: 0 1px 4px rgba(0,0,0,.2);
        }
        .weight-slider::-moz-range-thumb {
            width: 18px; height: 18px;
            background: #003366;
            border-radius: 50%;
            cursor: pointer;
            border: 2px solid white;
        }
        .weight-slider {
            -webkit-appearance: none;
            appearance: none;
            height: 6px;
            background: #e2e8f0;
            border-radius: 99px;
            outline: none;
            width: 100%;
        }
        .section-anchor { display: block; position: relative; top: -80px; visibility: hidden; }
        .nav-dot { width: 6px; height: 6px; border-radius: 50%; background: #cbd5e1; transition: all .2s; flex-shrink: 0; }
        .nav-dot.active { background: #003366; transform: scale(1.4); }
        .log-action-badge {
            font-size: 9px; font-weight: 900; text-transform: uppercase;
            padding: 2px 8px; border-radius: 99px; letter-spacing: .04em;
        }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex">

<!-- ── Sidebar ─────────────────────────────────────────────────────────── -->
<aside class="w-64 bg-cmu-blue text-white flex-shrink-0 hidden lg:flex flex-col shadow-xl sticky top-0 h-screen">
    <div class="p-6 flex items-center gap-3 border-b border-white/10">
        <img src="../assets/images/system-icon.png" alt="Logo" class="w-10 h-10 bg-white rounded-lg p-1"
             onerror="this.src='https://ui-avatars.com/api/?name=OSA&background=fff&color=003366';">
        <div>
            <h1 class="font-bold text-sm leading-tight">OSA Admin</h1>
            <p class="text-[10px] text-blue-200 uppercase tracking-widest">Management Portal</p>
        </div>
    </div>
    <nav class="flex-grow p-4 space-y-2">
        <a href="dashboard.php"       class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition"><i class="fas fa-th-large w-5"></i><span class="text-sm font-medium">Dashboard Overview</span></a>
        <a href="inventory.php"       class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition"><i class="fas fa-boxes w-5"></i><span class="text-sm font-medium">Physical Inventory</span></a>
        <a href="qr_scanner.php"      class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition"><i class="fas fa-qrcode w-5"></i><span class="text-sm font-medium">QR Intake Scanner</span></a>
        <a href="matching_portal.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition"><i class="fas fa-sync w-5"></i><span class="text-sm font-medium">Matching Portal</span></a>
        <a href="claim_verify.php"    class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition"><i class="fas fa-user-check w-5"></i><span class="text-sm font-medium">Claim Verification</span></a>
        <div class="pt-4 mt-4 border-t border-white/10">
            <a href="archive.php"       class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition"><i class="fas fa-archive w-5 text-blue-300"></i><span class="text-sm font-medium text-blue-100">Records Archive</span></a>
            <a href="record_merger.php" class="sidebar-link flex items-center gap-3 p-3 rounded-xl transition mt-1"><i class="fas fa-code-merge w-5 text-blue-300"></i><span class="text-sm font-medium text-blue-100">Record Merger</span></a>
        </div>
    </nav>
    <div class="p-4 border-t border-white/10">
        <div class="bg-white/5 rounded-2xl p-4">
            <p class="text-[10px] text-blue-300 uppercase font-bold mb-2">Logged in as</p>
            <p class="text-sm font-bold truncate"><?php echo $admin_name; ?></p>
            <a href="../core/logout.php" class="text-xs text-cmu-blue font-bold mt-2 py-2 px-4 inline-block rounded-md bg-cmu-gold hover:rounded-full hover:text-cmu-gold hover:bg-white">Logout Session</a>
        </div>
    </div>
</aside>

<!-- ── Main ─────────────────────────────────────────────────────────────── -->
<main class="flex-grow flex flex-col min-w-0 h-screen overflow-y-auto">

    <!-- ── Header ────────────────────────────────────────────────────────── -->
    <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between sticky top-0 z-10 gap-4">
        <div class="flex items-center gap-4">
            <!-- Back arrow -->
            <a href="dashboard.php"
               class="flex items-center gap-2 text-slate-500 hover:text-cmu-blue transition group"
               title="Back to Dashboard">
                <div class="w-8 h-8 flex items-center justify-center rounded-xl bg-slate-100 group-hover:bg-blue-50 transition">
                    <i class="fas fa-arrow-left text-sm"></i>
                </div>
                <span class="text-xs font-bold uppercase tracking-wider hidden md:block">Dashboard</span>
            </a>
            <div class="w-px h-6 bg-slate-200"></div>
            <div>
                <h2 class="text-xl font-black text-slate-800 tracking-tight uppercase">System Settings</h2>
                <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest">Configure platform behavior</p>
            </div>
        </div>
        <div class="hidden md:flex flex-col text-right">
            <span class="text-xs font-bold text-slate-400"><?php echo date('l, F j, Y'); ?></span>
            <span class="text-[10px] text-green-500 font-black uppercase"><i class="fas fa-circle text-[6px] mr-1"></i> System Online</span>
        </div>
    </header>

    <!-- ── Flash banner ───────────────────────────────────────────────────── -->
    <?php if (isset($flash)): ?>
    <div id="flashBanner" class="px-8 py-3 flex items-center gap-3 text-sm font-semibold
        <?php echo $flash['type'] === 'success' ? 'bg-green-50 border-b border-green-100 text-green-800' : 'bg-red-50 border-b border-red-100 text-red-700'; ?>">
        <i class="fas <?php echo $flash['type'] === 'success' ? 'fa-check-circle text-green-500' : 'fa-exclamation-circle text-red-500'; ?>"></i>
        <?php echo htmlspecialchars($flash['text']); ?>
        <button onclick="this.parentElement.remove()" class="ml-auto opacity-50 hover:opacity-100 text-xs">✕</button>
    </div>
    <?php endif; ?>

    <div class="flex gap-0">

        <!-- ── Sticky left nav ──────────────────────────────────────────── -->
        <nav id="settingsNav" class="hidden xl:flex flex-col gap-1 w-52 flex-shrink-0 sticky top-[73px] h-fit p-4 ml-4 mt-6">
            <?php
            $navItems = [
                ['id' => 'gallery',    'icon' => 'fa-globe',       'label' => 'Public Gallery'],
                ['id' => 'matching',   'icon' => 'fa-sliders',     'label' => 'Matching Engine'],
                ['id' => 'email',      'icon' => 'fa-envelope',    'label' => 'Email Template'],
                ['id' => 'password',   'icon' => 'fa-lock',        'label' => 'Change Password'],
                ['id' => 'admins',     'icon' => 'fa-users-cog',   'label' => 'Admin Accounts'],
                ['id' => 'locations',  'icon' => 'fa-map-pin',     'label' => 'Campus Locations'],
                ['id' => 'aging',      'icon' => 'fa-clock',       'label' => 'Aging Scan'],
                ['id' => 'log',        'icon' => 'fa-history',     'label' => 'Action Log'],
            ];
            foreach ($navItems as $nav):
            ?>
            <a href="#<?php echo $nav['id']; ?>"
               class="flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-bold text-slate-500 hover:text-cmu-blue hover:bg-blue-50 transition group"
               onclick="setActiveNav(this)">
                <div class="nav-dot" id="dot-<?php echo $nav['id']; ?>"></div>
                <i class="fas <?php echo $nav['icon']; ?> w-4 text-slate-300 group-hover:text-cmu-blue transition text-[11px]"></i>
                <?php echo $nav['label']; ?>
            </a>
            <?php endforeach; ?>
        </nav>

        <!-- ── Settings content ─────────────────────────────────────────── -->
        <div class="flex-1 p-6 lg:p-8 space-y-8 max-w-4xl">

            <!-- ════════════════════════════════════════════════════════════
                 1. PUBLIC GALLERY TOGGLE
                 ════════════════════════════════════════════════════════════ -->
            <a class="section-anchor" id="gallery"></a>
            <section class="settings-section bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-7 py-5 border-b border-slate-100 flex items-center gap-3">
                    <div class="w-9 h-9 bg-blue-50 text-cmu-blue rounded-xl flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-globe text-sm"></i>
                    </div>
                    <div>
                        <p class="font-black text-slate-800 text-sm uppercase tracking-tight">Public Gallery</p>
                        <p class="text-[10px] text-slate-400">Control public visibility during maintenance</p>
                    </div>
                </div>
                <form method="POST" class="px-7 py-6">
                    <input type="hidden" name="action" value="save_settings">
                    <input type="hidden" name="scroll_target" value="gallery">
                    <!-- Carry all other settings through -->
                    <?php foreach ($settings as $k => $v): if ($k === 'gallery_open' || $k === 'duplicate_check_enabled') continue; ?>
                    <input type="hidden" name="<?php echo $k; ?>" value="<?php echo htmlspecialchars($v); ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="duplicate_check_enabled" value="<?php echo $settings['duplicate_check_enabled']; ?>">

                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-bold text-slate-800">Gallery Open to Public</p>
                            <p class="text-xs text-slate-500 mt-1">When OFF, students see a maintenance notice and cannot browse or report items.</p>
                        </div>
                        <label class="relative cursor-pointer select-none">
                            <input type="hidden" name="gallery_open" value="0">
                            <input type="checkbox" name="gallery_open" value="1"
                                   <?php echo $settings['gallery_open'] === '1' ? 'checked' : ''; ?>
                                   class="sr-only peer"
                                   onchange="this.form.submit()">
                            <div class="w-12 h-6 bg-slate-200 rounded-full peer peer-checked:bg-cmu-blue transition-all duration-300
                                        peer-checked:after:translate-x-6
                                        after:content-[''] after:absolute after:top-0.5 after:left-0.5
                                        after:bg-white after:rounded-full after:h-5 after:w-5
                                        after:shadow-sm after:transition-all after:duration-300"></div>
                        </label>
                    </div>

                    <div class="mt-5 p-4 rounded-2xl border <?php echo $settings['gallery_open'] === '1' ? 'bg-green-50 border-green-100' : 'bg-red-50 border-red-100'; ?> flex items-center gap-3">
                        <i class="fas <?php echo $settings['gallery_open'] === '1' ? 'fa-check-circle text-green-500' : 'fa-wrench text-red-500'; ?>"></i>
                        <p class="text-xs font-semibold <?php echo $settings['gallery_open'] === '1' ? 'text-green-700' : 'text-red-700'; ?>">
                            <?php echo $settings['gallery_open'] === '1' ? 'Gallery is currently OPEN — students can browse and report items.' : 'Gallery is currently CLOSED — maintenance mode is active.'; ?>
                        </p>
                    </div>
                </form>
            </section>

            <!-- ════════════════════════════════════════════════════════════
                 2. MATCHING ENGINE SETTINGS
                 ════════════════════════════════════════════════════════════ -->
            <a class="section-anchor" id="matching"></a>
            <section class="settings-section bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-7 py-5 border-b border-slate-100 flex items-center gap-3">
                    <div class="w-9 h-9 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-sliders text-sm"></i>
                    </div>
                    <div>
                        <p class="font-black text-slate-800 text-sm uppercase tracking-tight">Matching Engine</p>
                        <p class="text-[10px] text-slate-400">Scoring weights, notification thresholds &amp; duplicate checks</p>
                    </div>
                </div>
                <form method="POST" class="px-7 py-6 space-y-7">
                    <input type="hidden" name="action" value="save_settings">
                    <input type="hidden" name="scroll_target" value="matching">
                    <input type="hidden" name="gallery_open" value="<?php echo $settings['gallery_open']; ?>">
                    <!-- email template carried hidden -->
                    <input type="hidden" name="email_match_template" value="<?php echo htmlspecialchars($settings['email_match_template']); ?>">

                    <!-- Thresholds row -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">
                                Auto-Notify Threshold
                                <span class="ml-1 text-indigo-500" id="auto-thresh-display"><?php echo $settings['auto_notify_threshold']; ?>%</span>
                            </label>
                            <input type="range" name="auto_notify_threshold" min="50" max="99" step="1"
                                   value="<?php echo (int)$settings['auto_notify_threshold']; ?>"
                                   class="weight-slider"
                                   oninput="document.getElementById('auto-thresh-display').textContent=this.value+'%'">
                            <p class="text-[10px] text-slate-400 mt-1">Matches at or above this score trigger an automatic Gmail notification to the owner.</p>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">
                                Review Queue Threshold
                                <span class="ml-1 text-indigo-500" id="review-thresh-display"><?php echo $settings['review_queue_threshold']; ?>%</span>
                            </label>
                            <input type="range" name="review_queue_threshold" min="10" max="79" step="1"
                                   value="<?php echo (int)$settings['review_queue_threshold']; ?>"
                                   class="weight-slider"
                                   oninput="document.getElementById('review-thresh-display').textContent=this.value+'%'">
                            <p class="text-[10px] text-slate-400 mt-1">Only matches at or above this score appear in the admin review queue.</p>
                        </div>
                    </div>

                    <!-- Scoring weights -->
                    <div>
                        <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Scoring Weights</p>
                        <p class="text-xs text-slate-400 mb-4">Adjust how much each signal contributes to the confidence score. Total must equal 100.</p>
                        <div id="weightsTotal" class="mb-3 text-xs font-black text-slate-500 uppercase tracking-widest">
                            Total: <span id="wTotal" class="text-green-600">100</span> / 100
                        </div>

                        <?php
                        $weights = [
                            ['key' => 'weight_category', 'label' => 'Category Match',    'color' => 'bg-blue-500',   'icon' => 'fa-tag'],
                            ['key' => 'weight_location',  'label' => 'Location Match',    'color' => 'bg-indigo-500', 'icon' => 'fa-map-pin'],
                            ['key' => 'weight_keywords',  'label' => 'Keyword Overlap',   'color' => 'bg-purple-500', 'icon' => 'fa-tags'],
                            ['key' => 'weight_date',      'label' => 'Date Proximity',    'color' => 'bg-pink-500',   'icon' => 'fa-calendar'],
                        ];
                        ?>
                        <div class="space-y-4">
                            <?php foreach ($weights as $w): ?>
                            <div class="flex items-center gap-4">
                                <div class="flex items-center gap-2 w-36 flex-shrink-0">
                                    <i class="fas <?php echo $w['icon']; ?> text-[10px] text-slate-400 w-4"></i>
                                    <span class="text-xs font-bold text-slate-700"><?php echo $w['label']; ?></span>
                                </div>
                                <input type="range" name="<?php echo $w['key']; ?>"
                                       min="0" max="60" step="1"
                                       value="<?php echo (int)$settings[$w['key']]; ?>"
                                       class="weight-slider flex-1"
                                       id="slider-<?php echo $w['key']; ?>"
                                       oninput="updateWeights()">
                                <div class="w-12 h-7 rounded-lg bg-slate-100 flex items-center justify-center">
                                    <span id="val-<?php echo $w['key']; ?>" class="text-xs font-black text-slate-700">
                                        <?php echo (int)$settings[$w['key']]; ?>
                                    </span>
                                </div>
                                <!-- Visual bar -->
                                <div class="w-20 h-2 bg-slate-100 rounded-full overflow-hidden flex-shrink-0">
                                    <div id="bar-<?php echo $w['key']; ?>"
                                         class="h-full <?php echo $w['color']; ?> rounded-full transition-all duration-200"
                                         style="width: <?php echo min(100, (int)$settings[$w['key']] * 100/60); ?>%"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Duplicate check toggle -->
                    <div class="pt-5 border-t border-slate-100 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-bold text-slate-800">Real-Time Duplicate Checking</p>
                            <p class="text-xs text-slate-500 mt-0.5">Show a warning when a user types a title matching an existing report.</p>
                        </div>
                        <label class="relative cursor-pointer select-none">
                            <input type="hidden" name="duplicate_check_enabled" value="0">
                            <input type="checkbox" name="duplicate_check_enabled" value="1"
                                   <?php echo $settings['duplicate_check_enabled'] === '1' ? 'checked' : ''; ?>
                                   class="sr-only peer">
                            <div class="w-12 h-6 bg-slate-200 rounded-full peer peer-checked:bg-cmu-blue transition-all duration-300
                                        peer-checked:after:translate-x-6
                                        after:content-[''] after:absolute after:top-0.5 after:left-0.5
                                        after:bg-white after:rounded-full after:h-5 after:w-5
                                        after:shadow-sm after:transition-all after:duration-300"></div>
                        </label>
                    </div>

                    <div class="pt-5 flex justify-end">
                        <button type="submit"
                                class="px-8 py-3 bg-cmu-blue text-white rounded-xl font-black text-sm hover:bg-slate-800 transition shadow-sm flex items-center gap-2">
                            <i class="fas fa-save"></i> Save Matching Settings
                        </button>
                    </div>
                </form>
            </section>

            <!-- ════════════════════════════════════════════════════════════
                 3. EMAIL TEMPLATE
                 ════════════════════════════════════════════════════════════ -->
            <a class="section-anchor" id="email"></a>
            <section class="settings-section bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-7 py-5 border-b border-slate-100 flex items-center gap-3">
                    <div class="w-9 h-9 bg-amber-50 text-amber-600 rounded-xl flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-envelope text-sm"></i>
                    </div>
                    <div>
                        <p class="font-black text-slate-800 text-sm uppercase tracking-tight">Match Email Template</p>
                        <p class="text-[10px] text-slate-400">Customize the message sent to item owners when a match is found</p>
                    </div>
                </div>
                <form method="POST" class="px-7 py-6 space-y-5">
                    <input type="hidden" name="action" value="save_settings">
                    <input type="hidden" name="scroll_target" value="email">
                    <input type="hidden" name="gallery_open" value="<?php echo $settings['gallery_open']; ?>">
                    <input type="hidden" name="auto_notify_threshold" value="<?php echo $settings['auto_notify_threshold']; ?>">
                    <input type="hidden" name="review_queue_threshold" value="<?php echo $settings['review_queue_threshold']; ?>">
                    <input type="hidden" name="duplicate_check_enabled" value="<?php echo $settings['duplicate_check_enabled']; ?>">
                    <input type="hidden" name="weight_category" value="<?php echo $settings['weight_category']; ?>">
                    <input type="hidden" name="weight_location" value="<?php echo $settings['weight_location']; ?>">
                    <input type="hidden" name="weight_keywords" value="<?php echo $settings['weight_keywords']; ?>">
                    <input type="hidden" name="weight_date" value="<?php echo $settings['weight_date']; ?>">

                    <div class="bg-amber-50 border border-amber-100 rounded-2xl p-4 flex gap-3 text-xs text-amber-800">
                        <i class="fas fa-info-circle text-amber-500 mt-0.5 flex-shrink-0"></i>
                        <p>Available placeholders: <code class="bg-white px-1 rounded font-mono">{name}</code> — recipient's name, <code class="bg-white px-1 rounded font-mono">{item}</code> — their lost item, <code class="bg-white px-1 rounded font-mono">{location}</code> — where the found item was reported.</p>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Message Body</label>
                        <textarea name="email_match_template" rows="10"
                                  class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-mono leading-relaxed outline-none focus:ring-2 focus:ring-cmu-blue transition resize-none"
                        ><?php echo htmlspecialchars($settings['email_match_template']); ?></textarea>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="px-8 py-3 bg-cmu-blue text-white rounded-xl font-black text-sm hover:bg-slate-800 transition shadow-sm flex items-center gap-2">
                            <i class="fas fa-save"></i> Save Template
                        </button>
                    </div>
                </form>
            </section>

            <!-- ════════════════════════════════════════════════════════════
                 4. CHANGE PASSWORD
                 ════════════════════════════════════════════════════════════ -->
            <a class="section-anchor" id="password"></a>
            <section class="settings-section bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-7 py-5 border-b border-slate-100 flex items-center gap-3">
                    <div class="w-9 h-9 bg-green-50 text-green-600 rounded-xl flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-lock text-sm"></i>
                    </div>
                    <div>
                        <p class="font-black text-slate-800 text-sm uppercase tracking-tight">Change Password</p>
                        <p class="text-[10px] text-slate-400">Update your admin account password</p>
                    </div>
                </div>
                <form method="POST" class="px-7 py-6 space-y-5">
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="scroll_target" value="password">

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Current Password</label>
                            <div class="relative">
                                <input type="password" name="current_password" required
                                       class="w-full pl-4 pr-10 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-cmu-blue transition"
                                       placeholder="••••••••">
                                <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 hover:text-slate-500" onclick="togglePwd(this)">
                                    <i class="fas fa-eye text-xs"></i>
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">New Password</label>
                            <div class="relative">
                                <input type="password" name="new_password" id="new_pw" required
                                       class="w-full pl-4 pr-10 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-cmu-blue transition"
                                       placeholder="Min. 8 characters"
                                       oninput="updatePwStrength(this.value)">
                                <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 hover:text-slate-500" onclick="togglePwd(this)">
                                    <i class="fas fa-eye text-xs"></i>
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Confirm New Password</label>
                            <div class="relative">
                                <input type="password" name="confirm_password" required
                                       class="w-full pl-4 pr-10 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-cmu-blue transition"
                                       placeholder="Re-enter">
                                <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 hover:text-slate-500" onclick="togglePwd(this)">
                                    <i class="fas fa-eye text-xs"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Strength bar -->
                    <div id="pw-strength-wrap" class="hidden">
                        <div class="flex gap-1 mb-1">
                            <?php for ($i=1; $i<=4; $i++): ?>
                            <div id="ps<?php echo $i; ?>" class="h-1 flex-1 rounded-full bg-slate-200 transition-all duration-300"></div>
                            <?php endfor; ?>
                        </div>
                        <p id="pw-strength-label" class="text-[10px] font-black uppercase"></p>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="px-8 py-3 bg-green-600 text-white rounded-xl font-black text-sm hover:bg-green-700 transition shadow-sm flex items-center gap-2">
                            <i class="fas fa-key"></i> Update Password
                        </button>
                    </div>
                </form>
            </section>

            <!-- ════════════════════════════════════════════════════════════
                 5. ADMIN ACCOUNTS
                 ════════════════════════════════════════════════════════════ -->
            <a class="section-anchor" id="admins"></a>
            <section class="settings-section bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-7 py-5 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 bg-purple-50 text-purple-600 rounded-xl flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-users-cog text-sm"></i>
                        </div>
                        <div>
                            <p class="font-black text-slate-800 text-sm uppercase tracking-tight">Admin Accounts</p>
                            <p class="text-[10px] text-slate-400"><?php echo count($all_admins); ?> admin(s) registered</p>
                        </div>
                    </div>
                    <button onclick="openAddAdminModal()"
                            class="flex items-center gap-2 px-4 py-2 bg-cmu-blue text-white text-xs font-black rounded-xl hover:bg-slate-800 transition shadow-sm">
                        <i class="fas fa-user-plus"></i> Add Admin
                    </button>
                </div>
                <div class="divide-y divide-slate-50">
                    <?php foreach ($all_admins as $adm):
                        $is_self   = (int)$adm['user_id'] === $admin_id;
                        $is_admin  = strtolower($adm['role'] ?? '') === 'admin';
                    ?>
                    <div class="px-7 py-4 flex items-center gap-4">
                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($adm['full_name']); ?>&background=003366&color=fff&bold=true&size=36"
                             class="w-9 h-9 rounded-full flex-shrink-0" alt="">
                        <div class="flex-grow min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="text-sm font-bold text-slate-800 truncate"><?php echo htmlspecialchars($adm['full_name']); ?></p>
                                <?php if ($is_self): ?>
                                <span class="text-[9px] font-black bg-cmu-blue text-white px-2 py-0.5 rounded-full">YOU</span>
                                <?php endif; ?>
                                <span class="text-[9px] font-bold px-2 py-0.5 rounded-full <?php echo $is_admin ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-600'; ?>">
                                    <?php echo $is_admin ? 'ACTIVE' : 'REVOKED'; ?>
                                </span>
                            </div>
                            <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($adm['cmu_email']); ?> · <?php echo htmlspecialchars($adm['department'] ?? '—'); ?></p>
                        </div>
                        <?php if (!$is_self): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="toggle_admin">
                            <input type="hidden" name="scroll_target" value="admins">
                            <input type="hidden" name="target_id" value="<?php echo (int)$adm['user_id']; ?>">
                            <input type="hidden" name="new_role" value="<?php echo $is_admin ? 'student' : 'admin'; ?>">
                            <button type="submit"
                                    class="text-[10px] font-black px-3 py-1.5 rounded-lg border transition
                                    <?php echo $is_admin
                                        ? 'border-red-200 text-red-600 hover:bg-red-50'
                                        : 'border-green-200 text-green-600 hover:bg-green-50'; ?>">
                                <?php echo $is_admin ? 'Revoke Access' : 'Restore Access'; ?>
                            </button>
                        </form>
                        <?php else: ?>
                        <span class="text-[10px] text-slate-300 italic">Current session</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- ════════════════════════════════════════════════════════════
                 6. CAMPUS LOCATIONS
                 ════════════════════════════════════════════════════════════ -->
            <a class="section-anchor" id="locations"></a>
            <section class="settings-section bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-7 py-5 border-b border-slate-100 flex items-center gap-3">
                    <div class="w-9 h-9 bg-teal-50 text-teal-600 rounded-xl flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-map-pin text-sm"></i>
                    </div>
                    <div>
                        <p class="font-black text-slate-800 text-sm uppercase tracking-tight">Campus Locations</p>
                        <p class="text-[10px] text-slate-400">Manage the location dropdown used in all report forms</p>
                    </div>
                </div>

                <!-- Add new location -->
                <form method="POST" class="px-7 py-5 border-b border-slate-100 flex gap-3">
                    <input type="hidden" name="action" value="save_location">
                    <input type="hidden" name="scroll_target" value="locations">
                    <input type="text" name="location_name" required placeholder="New location name, e.g. Gymnasium"
                           class="flex-1 px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-cmu-blue transition">
                    <button type="submit" class="px-5 py-2.5 bg-teal-600 text-white rounded-xl text-xs font-black hover:bg-teal-700 transition flex items-center gap-2">
                        <i class="fas fa-plus"></i> Add Location
                    </button>
                </form>

                <!-- List existing -->
                <div class="divide-y divide-slate-50">
                    <?php foreach ($locations as $loc): ?>
                    <form method="POST" class="px-7 py-3 flex items-center gap-3">
                        <input type="hidden" name="action" value="rename_location">
                        <input type="hidden" name="scroll_target" value="locations">
                        <input type="hidden" name="loc_id" value="<?php echo (int)$loc['location_id']; ?>">
                        <span class="text-[10px] font-black text-slate-300 w-8 flex-shrink-0 text-right">#<?php echo $loc['location_id']; ?></span>
                        <input type="text" name="location_name"
                               value="<?php echo htmlspecialchars($loc['location_name']); ?>"
                               class="flex-1 px-3 py-2 text-sm font-medium text-slate-700 border border-transparent rounded-xl bg-transparent hover:bg-slate-50 hover:border-slate-200 focus:bg-white focus:border-cmu-blue outline-none transition">
                        <button type="submit" class="text-[10px] font-black text-slate-400 hover:text-cmu-blue px-3 py-1.5 rounded-lg hover:bg-blue-50 transition flex items-center gap-1">
                            <i class="fas fa-check text-[9px]"></i> Rename
                        </button>
                    </form>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- ════════════════════════════════════════════════════════════
                 7. AGING SCAN
                 ════════════════════════════════════════════════════════════ -->
            <a class="section-anchor" id="aging"></a>
            <section class="settings-section bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-7 py-5 border-b border-slate-100 flex items-center gap-3">
                    <div class="w-9 h-9 bg-orange-50 text-orange-500 rounded-xl flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-clock text-sm"></i>
                    </div>
                    <div>
                        <p class="font-black text-slate-800 text-sm uppercase tracking-tight">Aging Report Scan</p>
                        <p class="text-[10px] text-slate-400">Manually trigger a scan of items approaching the 60-day disposal threshold</p>
                    </div>
                </div>
                <div class="px-7 py-6">
                    <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
                        <div class="text-sm text-slate-600 leading-relaxed max-w-lg">
                            <p>The aging scan flags items held for <strong>30+ days</strong> (Warning), <strong>45+ days</strong> (Critical), and <strong>60+ days</strong> (Expired). Results appear in the <a href="archive.php?tab=aging" class="text-cmu-blue font-bold hover:underline">Records Archive → Aging Items</a> tab.</p>
                            <p class="text-xs text-slate-400 mt-2">This scan runs automatically every 24 hours. Trigger it manually after bulk item updates.</p>
                        </div>
                        <form method="POST" class="flex-shrink-0">
                            <input type="hidden" name="action" value="trigger_aging">
                            <input type="hidden" name="scroll_target" value="aging">
                            <button type="submit"
                                    class="flex items-center gap-2 px-6 py-3 bg-orange-500 text-white rounded-xl font-black text-sm hover:bg-orange-600 transition shadow-sm">
                                <i class="fas fa-rotate"></i>
                                Run Aging Scan Now
                            </button>
                        </form>
                    </div>

                    <!-- Quick aging stats -->
                    <?php
                    try {
                        $aging_stats = $pdo->query("
                            SELECT
                                SUM(DATEDIFF(CURDATE(), date_found) >= 60) AS expired,
                                SUM(DATEDIFF(CURDATE(), date_found) >= 45 AND DATEDIFF(CURDATE(), date_found) < 60) AS critical,
                                SUM(DATEDIFF(CURDATE(), date_found) >= 30 AND DATEDIFF(CURDATE(), date_found) < 45) AS warning
                            FROM found_reports
                            WHERE status NOT IN ('claimed','disposed','returned')
                        ")->fetch();
                    } catch (Throwable) { $aging_stats = null; }
                    ?>
                    <?php if ($aging_stats): ?>
                    <div class="mt-5 grid grid-cols-3 gap-3">
                        <div class="p-3 bg-red-50 border border-red-100 rounded-2xl text-center">
                            <p class="text-2xl font-black text-red-600"><?php echo (int)($aging_stats['expired'] ?? 0); ?></p>
                            <p class="text-[10px] font-bold text-red-400 uppercase mt-0.5">Expired (60+ days)</p>
                        </div>
                        <div class="p-3 bg-orange-50 border border-orange-100 rounded-2xl text-center">
                            <p class="text-2xl font-black text-orange-600"><?php echo (int)($aging_stats['critical'] ?? 0); ?></p>
                            <p class="text-[10px] font-bold text-orange-400 uppercase mt-0.5">Critical (45–59 days)</p>
                        </div>
                        <div class="p-3 bg-amber-50 border border-amber-100 rounded-2xl text-center">
                            <p class="text-2xl font-black text-amber-600"><?php echo (int)($aging_stats['warning'] ?? 0); ?></p>
                            <p class="text-[10px] font-bold text-amber-400 uppercase mt-0.5">Warning (30–44 days)</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ════════════════════════════════════════════════════════════
                 8. ACTION LOG
                 ════════════════════════════════════════════════════════════ -->
            <a class="section-anchor" id="log"></a>
            <section class="settings-section bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-7 py-5 border-b border-slate-100 flex items-center gap-3">
                    <div class="w-9 h-9 bg-slate-100 text-slate-500 rounded-xl flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-history text-sm"></i>
                    </div>
                    <div>
                        <p class="font-black text-slate-800 text-sm uppercase tracking-tight">Admin Action Log</p>
                        <p class="text-[10px] text-slate-400">Last 50 recorded admin actions across all accounts</p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs min-w-[600px]">
                        <thead class="bg-slate-50 border-b border-slate-100">
                            <tr>
                                <th class="px-7 py-3 font-black text-slate-400 uppercase tracking-widest text-[10px]">Timestamp</th>
                                <th class="px-7 py-3 font-black text-slate-400 uppercase tracking-widest text-[10px]">Admin</th>
                                <th class="px-7 py-3 font-black text-slate-400 uppercase tracking-widest text-[10px]">Action</th>
                                <th class="px-7 py-3 font-black text-slate-400 uppercase tracking-widest text-[10px]">Description</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                        <?php if (empty($action_log)): ?>
                        <tr>
                            <td colspan="4" class="px-7 py-10 text-center text-slate-400 text-sm font-bold">
                                No actions recorded yet. Activity will appear here as admins use the portal.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($action_log as $log):
                            $badge_map = [
                                'settings_updated'   => 'bg-blue-100 text-blue-700',
                                'password_changed'   => 'bg-green-100 text-green-700',
                                'admin_toggled'      => 'bg-purple-100 text-purple-700',
                                'location_added'     => 'bg-teal-100 text-teal-700',
                                'location_renamed'   => 'bg-teal-100 text-teal-700',
                                'aging_scan_triggered' => 'bg-orange-100 text-orange-700',
                            ];
                            $badge_cls = $badge_map[$log['action_type']] ?? 'bg-slate-100 text-slate-500';
                        ?>
                        <tr class="hover:bg-slate-50 transition">
                            <td class="px-7 py-3 text-slate-400 whitespace-nowrap">
                                <?php echo date('M d, Y g:i A', strtotime($log['created_at'])); ?>
                            </td>
                            <td class="px-7 py-3 font-bold text-slate-700">
                                <?php echo htmlspecialchars($log['admin_name'] ?? 'System'); ?>
                            </td>
                            <td class="px-7 py-3">
                                <span class="log-action-badge <?php echo $badge_cls; ?>">
                                    <?php echo htmlspecialchars(str_replace('_', ' ', $log['action_type'])); ?>
                                </span>
                            </td>
                            <td class="px-7 py-3 text-slate-500">
                                <?php echo htmlspecialchars($log['description'] ?? '—'); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        </div><!-- end settings content -->
    </div><!-- end flex row -->
</main>

<!-- ── Add Admin Modal (reuse from dashboard) ───────────────────────────── -->
<?php include 'add_admin_modal.html'; ?>

<script>
// ── Weight sliders total ───────────────────────────────────────────────────
function updateWeights() {
    const keys = ['weight_category','weight_location','weight_keywords','weight_date'];
    let total = 0;
    keys.forEach(k => {
        const s = document.getElementById('slider-' + k);
        if (!s) return;
        const v = parseInt(s.value);
        total += v;
        document.getElementById('val-' + k).textContent = v;
        const bar = document.getElementById('bar-' + k);
        if (bar) bar.style.width = Math.min(100, v * 100 / 60) + '%';
    });
    const el = document.getElementById('wTotal');
    el.textContent = total;
    el.className = total === 100 ? 'text-green-600' : (total > 100 ? 'text-red-500' : 'text-amber-500');
}
updateWeights();

// ── Password visibility toggle ─────────────────────────────────────────────
function togglePwd(btn) {
    const input = btn.previousElementSibling || btn.closest('.relative').querySelector('input');
    const icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

// ── Password strength ──────────────────────────────────────────────────────
function updatePwStrength(val) {
    const wrap = document.getElementById('pw-strength-wrap');
    if (!val) { wrap.classList.add('hidden'); return; }
    wrap.classList.remove('hidden');

    let s = 0;
    if (val.length >= 8) s++;
    if (/[A-Z]/.test(val)) s++;
    if (/[0-9]/.test(val)) s++;
    if (/[^A-Za-z0-9]/.test(val)) s++;

    const colors  = ['bg-red-400','bg-orange-400','bg-yellow-400','bg-green-500'];
    const labels  = ['Weak','Fair','Good','Strong'];
    const tColors = ['text-red-500','text-orange-500','text-yellow-500','text-green-600'];

    for (let i = 1; i <= 4; i++) {
        const bar = document.getElementById('ps' + i);
        bar.className = 'h-1 flex-1 rounded-full transition-all duration-300 ' + (i <= s ? colors[s-1] : 'bg-slate-200');
    }
    const lbl = document.getElementById('pw-strength-label');
    lbl.textContent  = labels[s - 1] || '';
    lbl.className    = 'text-[10px] font-black uppercase ' + (tColors[s - 1] || '');
}

// ── Side nav active state on scroll ───────────────────────────────────────
const sections = ['gallery','matching','email','password','admins','locations','aging','log'];

function setActiveNav(clickedEl) {
    // Visual-only update on click; scroll listener handles the rest
}

function updateNavOnScroll() {
    let current = sections[0];
    sections.forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        const rect = el.getBoundingClientRect();
        if (rect.top <= 120) current = id;
    });
    sections.forEach(id => {
        const dot = document.getElementById('dot-' + id);
        if (dot) dot.classList.toggle('active', id === current);
    });
}

window.addEventListener('scroll', updateNavOnScroll, { passive: true });
updateNavOnScroll();

// ── Auto-dismiss flash banner after 4s ────────────────────────────────────
const flash = document.getElementById('flashBanner');
if (flash) setTimeout(() => { flash.style.opacity = '0'; flash.style.transition = 'opacity .4s'; setTimeout(() => flash.remove(), 400); }, 4000);
</script>

</body>
</html>