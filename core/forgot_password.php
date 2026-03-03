<?php
/**
 * CMU Lost & Found - Forgot Password Page
 * Handles password reset requests via University or Recovery Email.
 */

session_start();
require_once __DIR__ . '/db_config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: ../public/index.php");
    exit();
}

$message = "";
$messageType = "info"; // info, success, error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailInput = trim($_POST['email'] ?? '');

    if (!empty($emailInput)) {
        try {
            // Check if user exists by primary university email OR recovery email
            $stmt = $pdo->prepare("SELECT id, email, first_name FROM users WHERE email = ? OR recovery_email = ? LIMIT 1");
            $stmt->execute([$emailInput, $emailInput]);
            $user = $stmt->fetch();

            if ($user) {
                /* LOGIC: Generate token and send email.
                   In this simulation, we notify the user that a link is sent.
                */
                $message = "A reset link has been sent to the email address associated with your account. Please check your inbox.";
                $messageType = "success";
            } else {
                $message = "We couldn't find an account associated with that email address.";
                $messageType = "error";
            }
        } catch (PDOException $e) {
            $message = "A system error occurred. Please try again later.";
            $messageType = "error";
        }
    } else {
        $message = "Please enter your university or recovery email.";
        $messageType = "error";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - CMU Lost & Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="../assets/images/system-icon.png">
    <style>
        :root { --cmu-blue: #003366; }
        .bg-cmu-blue { background-color: var(--cmu-blue); }
        .text-cmu-blue { color: var(--cmu-blue); }
        .auth-card { transition: all 0.3s ease; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">

    <div class="max-w-4xl w-full bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col md:flex-row min-h-[550px]">
        
        <!-- Left Side: Instructional Branding -->
        <div class="hidden md:flex md:w-1/2 bg-cmu-blue p-12 flex-col justify-between text-white relative">
            <div class="z-10">
                <!-- Dual Logo Section -->
                <div class="flex gap-4 mb-8">
                    <div class="w-16 h-16 bg-white rounded-full flex items-center justify-center shadow-lg p-1">
                        <img 
                            src="../assets/images/system-icon.png" 
                            alt="System Logo" 
                            class="w-full h-full object-contain"
                            onerror="this.src='https://ui-avatars.com/api/?name=LF&background=fff&color=003366';"
                        >
                    </div>
                    <div class="w-16 h-16 bg-white rounded-full flex items-center justify-center shadow-lg p-2">
                        <img 
                            src="../assets/images/cmu-logo.png" 
                            alt="CMU Logo" 
                            class="w-full h-full object-contain rounded-xl"
                            onerror="this.src='https://ui-avatars.com/api/?name=CMU&background=fff&color=003366';"
                        >
                    </div>
                </div>

                <h1 class="text-3xl font-bold mb-4">Recovery Portal</h1>
                <p class="text-blue-100 text-lg leading-relaxed">
                    Forgot your password? Enter your university email or your registered recovery email to reset your credentials.
                </p>
            </div>

            <div class="z-10 bg-white/10 p-4 rounded-xl border border-white/20">
                <p class="text-sm flex items-start gap-3">
                    <i class="fas fa-info-circle mt-1 text-amber-400"></i>
                    <span>For security reasons, reset links expire after 24 hours. Please check your "Spam" or "Junk" folder if you don't see the email.</span>
                </p>
                <p class="text-sm flex items-start gap-3 mt-4">
                    <i class="fas fa-shield-alt mt-1 text-amber-400"></i>
                    <span>Identity verification is required. If you no longer have access to either email, please visit the Office of Student Affairs (OSA).</span>
                </p>
            </div>

            
            
            <div class="absolute bottom-0 left-0 w-full h-32 bg-gradient-to-t from-black/20 to-transparent"></div>
        </div>

        <!-- Right Side: Request Form -->
        <div class="w-full md:w-1/2 p-8 md:p-12 flex flex-col justify-center">
            
            <div class="auth-card">
                <div class="mb-8">
                    <h2 class="text-2xl font-bold text-gray-800">Account Recovery</h2>
                    <p class="text-gray-500 mt-1">Search for your account using your email.</p>
                </div>

                <?php if ($message): ?>
                    <div class="mb-6 p-4 rounded-xl text-sm flex items-start gap-3 <?php 
                        echo $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 
                            ($messageType === 'error' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-blue-50 text-blue-700 border border-blue-200'); 
                    ?>">
                        <i class="fas <?php 
                            echo $messageType === 'success' ? 'fa-check-circle' : 
                                ($messageType === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'); 
                        ?> mt-0.5"></i>
                        <span><?php echo $message; ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($messageType !== 'success'): ?>
                    <form action="forgot_password.php" method="POST" class="space-y-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">University or Recovery Email</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                                    <i class="fas fa-user-shield"></i>
                                </span>
                                <input type="email" name="email" required placeholder="email@cityofmalabonuniversity.edu.ph" 
                                    class="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none transition">
                            </div>
                            <p class="mt-2 text-xs text-gray-400 italic">Example: @gmail.com or @cityofmalabonuniversity.edu.ph</p>
                        </div>

                        <button type="submit" class="w-full bg-cmu-blue text-white font-bold py-3 rounded-xl hover:bg-slate-800 transition shadow-lg">
                            Send Reset Link
                        </button>
                    </form>
                <?php else: ?>
                    <div class="text-center bg-gray-50 p-6 rounded-2xl border border-dashed border-gray-200">
                        <i class="fas fa-paper-plane text-4xl text-cmu-blue mb-4"></i>
                        <p class="text-gray-600 mb-6">If the email exists in our system, you will receive reset instructions shortly.</p>
                        <a href="auth.php" class="inline-block px-8 py-3 bg-cmu-blue text-white font-bold rounded-xl hover:bg-slate-800 transition">
                            Back to Login
                        </a>
                    </div>
                <?php endif; ?>

                <div class="mt-10 text-center">
                    <a href="auth.php" class="text-sm text-gray-500 hover:text-cmu-blue transition font-medium">
                        <i class="fas fa-arrow-left mr-1"></i> Back to Sign In
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>