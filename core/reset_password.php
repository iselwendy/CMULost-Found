<?php
/**
 * CMU Lost & Found - Reset Password Page
 * Validates the reset token and allows the user to set a new password.
 */

session_start();
require_once __DIR__ . '/db_config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: ../public/index.php");
    exit();
}

$token       = trim($_GET['token'] ?? '');
$message     = "";
$messageType = "info";
$tokenValid  = false;
$userData    = null;

// ── 1. Validate the token ─────────────────────────────────────────────────
if (empty($token)) {
    $message     = "No reset token provided. Please request a new password reset.";
    $messageType = "error";
} else {
    try {
        $stmt = $pdo->prepare("
            SELECT pr.id AS reset_id, pr.user_id, pr.expires_at, pr.used,
                   u.full_name, u.cmu_email
            FROM   password_resets pr
            JOIN   users           u  ON u.user_id = pr.user_id
            WHERE  pr.token = ?
            LIMIT  1
        ");
        $stmt->execute([$token]);
        $resetRow = $stmt->fetch();

        if (!$resetRow) {
            $message     = "This reset link is invalid. Please request a new one.";
            $messageType = "error";
        } elseif ($resetRow['used']) {
            $message     = "This reset link has already been used. Please request a new one.";
            $messageType = "error";
        } elseif (strtotime($resetRow['expires_at']) < time()) {
            $message     = "This reset link has expired (valid for 24 hours). Please request a new one.";
            $messageType = "error";
        } else {
            $tokenValid = true;
            $userData   = $resetRow;
        }
    } catch (PDOException $e) {
        error_log('[reset_password] Token lookup error: ' . $e->getMessage());
        $message     = "A system error occurred. Please try again later.";
        $messageType = "error";
    }
}

// ── 2. Handle the new-password form submission ────────────────────────────
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid && $userData) {
    $newPassword  = $_POST['new_password']     ?? '';
    $confirmPw    = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 8) {
        $message     = "Password must be at least 8 characters.";
        $messageType = "error";
    } elseif ($newPassword !== $confirmPw) {
        $message     = "Passwords do not match.";
        $messageType = "error";
    } else {
        try {
            $pdo->beginTransaction();

            // Update the user's password
            $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?")
                ->execute([$hashed, $userData['user_id']]);

            // Mark the token as used
            $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?")
                ->execute([$userData['reset_id']]);

            $pdo->commit();

            $success     = true;
            $message     = "Your password has been reset successfully. You can now log in.";
            $messageType = "success";
            $tokenValid  = false; // hide the form

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[reset_password] Update error: ' . $e->getMessage());
            $message     = "A system error occurred. Please try again later.";
            $messageType = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password - CMU Lost & Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="../assets/images/system-icon.png">
    <style>
        :root { --cmu-blue: #003366; }
        .bg-cmu-blue { background-color: var(--cmu-blue); }
        .text-cmu-blue { color: var(--cmu-blue); }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">

    <div class="max-w-4xl w-full bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col md:flex-row min-h-[550px]">

        <!-- Left Side: Branding -->
        <div class="hidden md:flex md:w-1/2 bg-cmu-blue p-12 flex-col justify-between text-white relative">
            <div class="z-10">
                <div class="flex gap-4 mb-8">
                    <div class="w-16 h-16 bg-white rounded-full flex items-center justify-center shadow-lg p-1">
                        <img src="../assets/images/system-icon.png" alt="System Logo"
                             class="w-full h-full object-contain rounded-xl"
                             onerror="this.src='https://ui-avatars.com/api/?name=LF&background=fff&color=003366';">
                    </div>
                    <div class="w-16 h-16 bg-white rounded-full flex items-center justify-center shadow-lg p-2">
                        <img src="../assets/images/cmu-logo.png" alt="CMU Logo"
                             class="w-full h-full object-contain rounded-xl"
                             onerror="this.src='https://ui-avatars.com/api/?name=CMU&background=fff&color=003366';">
                    </div>
                </div>
                <h1 class="text-3xl font-bold mb-4">Set New Password</h1>
                <p class="text-blue-100 text-lg leading-relaxed">
                    Choose a strong password that you haven't used before.
                    Your account security is important to us.
                </p>
            </div>

            <div class="z-10 bg-white/10 p-4 rounded-xl border border-white/20">
                <p class="text-sm font-bold text-white mb-2">Password Tips</p>
                <ul class="text-sm text-blue-100 space-y-1 list-disc list-inside">
                    <li>At least 8 characters long</li>
                    <li>Mix uppercase and lowercase letters</li>
                    <li>Include numbers and symbols</li>
                    <li>Avoid using your name or email</li>
                </ul>
            </div>

            <div class="absolute bottom-0 left-0 w-full h-32 bg-gradient-to-t from-black/20 to-transparent"></div>
        </div>

        <!-- Right Side: Form -->
        <div class="w-full md:w-1/2 p-8 md:p-12 flex flex-col justify-center">

            <div class="mb-8">
                <h2 class="text-2xl font-bold text-gray-800">
                    <?php echo $success ? 'Password Updated!' : 'Create New Password'; ?>
                </h2>
                <p class="text-gray-500 mt-1">
                    <?php if ($userData && !$success): ?>
                        Setting a new password for <strong><?php echo htmlspecialchars($userData['full_name']); ?></strong>.
                    <?php else: ?>
                        CMU Lost &amp; Found account recovery.
                    <?php endif; ?>
                </p>
            </div>

            <!-- Message banner -->
            <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-xl text-sm flex items-start gap-3 <?php 
                echo $messageType === 'success'
                    ? 'bg-green-50 text-green-700 border border-green-200'
                    : ($messageType === 'error'
                        ? 'bg-red-50 text-red-700 border border-red-200'
                        : 'bg-blue-50 text-blue-700 border border-blue-200');
            ?>">
                <i class="fas <?php 
                    echo $messageType === 'success' ? 'fa-check-circle'
                        : ($messageType === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle');
                ?> mt-0.5 flex-shrink-0"></i>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
            <?php endif; ?>

            <!-- Password form (only when token is valid) -->
            <?php if ($tokenValid): ?>
            <form method="POST" action="reset_password.php?token=<?php echo urlencode($token); ?>"
                  class="space-y-5" id="resetForm">

                <!-- New Password -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input type="password" name="new_password" id="newPassword" required
                               placeholder="At least 8 characters"
                               oninput="updateStrength(this.value)"
                               class="w-full pl-10 pr-10 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none transition">
                        <button type="button" onclick="toggleVisibility('newPassword', 'eyeIcon1')"
                                class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                            <i id="eyeIcon1" class="fas fa-eye"></i>
                        </button>
                    </div>

                    <!-- Strength meter -->
                    <div id="strengthWrap" class="hidden mt-2">
                        <div class="flex gap-1 mb-1">
                            <div id="sb1" class="h-1 flex-1 rounded-full bg-gray-200 transition-all duration-300"></div>
                            <div id="sb2" class="h-1 flex-1 rounded-full bg-gray-200 transition-all duration-300"></div>
                            <div id="sb3" class="h-1 flex-1 rounded-full bg-gray-200 transition-all duration-300"></div>
                            <div id="sb4" class="h-1 flex-1 rounded-full bg-gray-200 transition-all duration-300"></div>
                        </div>
                        <p id="strengthLabel" class="text-[11px] font-bold uppercase"></p>
                    </div>
                </div>

                <!-- Confirm Password -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input type="password" name="confirm_password" id="confirmPassword" required
                               placeholder="Re-enter your new password"
                               oninput="checkMatch()"
                               class="w-full pl-10 pr-10 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none transition">
                        <button type="button" onclick="toggleVisibility('confirmPassword', 'eyeIcon2')"
                                class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                            <i id="eyeIcon2" class="fas fa-eye"></i>
                        </button>
                    </div>
                    <p id="matchMsg" class="text-[11px] mt-1 hidden"></p>
                </div>

                <button type="submit"
                        class="w-full bg-cmu-blue text-white font-bold py-3 rounded-xl hover:bg-slate-800 transition shadow-lg">
                    <i class="fas fa-key mr-2"></i>Update Password
                </button>
            </form>

            <?php elseif ($success): ?>
            <!-- Success state -->
            <div class="text-center bg-gray-50 p-6 rounded-2xl border border-dashed border-gray-200">
                <div class="w-16 h-16 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl">
                    <i class="fas fa-check"></i>
                </div>
                <p class="text-gray-600 mb-6">Your password has been updated. You can now sign in with your new credentials.</p>
                <a href="auth.php"
                   class="inline-block px-8 py-3 bg-cmu-blue text-white font-bold rounded-xl hover:bg-slate-800 transition">
                    Go to Login
                </a>
            </div>

            <?php else: ?>
            <!-- Invalid / expired token state -->
            <div class="text-center bg-gray-50 p-6 rounded-2xl border border-dashed border-gray-200">
                <div class="w-16 h-16 bg-red-100 text-red-500 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl">
                    <i class="fas fa-link-slash"></i>
                </div>
                <p class="text-gray-500 text-sm mb-6">
                    Request a new reset link from the recovery page.
                </p>
                <a href="forgot_password.php"
                   class="inline-block px-8 py-3 bg-cmu-blue text-white font-bold rounded-xl hover:bg-slate-800 transition">
                    Request New Link
                </a>
            </div>
            <?php endif; ?>

            <div class="mt-8 text-center">
                <a href="auth.php" class="text-sm text-gray-500 hover:text-cmu-blue transition font-medium">
                    <i class="fas fa-arrow-left mr-1"></i> Back to Sign In
                </a>
            </div>
        </div>
    </div>

    <script>
        function toggleVisibility(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon  = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        function updateStrength(val) {
            const wrap  = document.getElementById('strengthWrap');
            const label = document.getElementById('strengthLabel');
            if (!val) { wrap.classList.add('hidden'); return; }
            wrap.classList.remove('hidden');

            let score = 0;
            if (val.length >= 8)           score++;
            if (/[A-Z]/.test(val))         score++;
            if (/[0-9]/.test(val))         score++;
            if (/[^A-Za-z0-9]/.test(val))  score++;

            const colors  = ['bg-red-400',    'bg-orange-400', 'bg-yellow-400', 'bg-green-500'];
            const labels  = ['Weak',           'Fair',          'Good',          'Strong'];
            const tColors = ['text-red-500',   'text-orange-500','text-yellow-500','text-green-600'];

            for (let i = 1; i <= 4; i++) {
                const bar = document.getElementById('sb' + i);
                bar.className = 'h-1 flex-1 rounded-full transition-all duration-300 '
                    + (i <= score ? colors[score - 1] : 'bg-gray-200');
            }
            label.textContent = labels[score - 1] || '';
            label.className   = 'text-[11px] font-bold uppercase ' + (tColors[score - 1] || '');
        }

        function checkMatch() {
            const pw1 = document.getElementById('newPassword').value;
            const pw2 = document.getElementById('confirmPassword').value;
            const msg = document.getElementById('matchMsg');
            if (!pw2) { msg.classList.add('hidden'); return; }

            if (pw1 === pw2) {
                msg.textContent  = '✓ Passwords match';
                msg.className    = 'text-[11px] mt-1 text-green-600 font-semibold';
            } else {
                msg.textContent  = '✗ Passwords do not match';
                msg.className    = 'text-[11px] mt-1 text-red-500 font-semibold';
            }
            msg.classList.remove('hidden');
        }
    </script>
</body>
</html>