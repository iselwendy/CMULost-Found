<?php
/**
 * CMU Lost & Found - Forgot Password Page
 * Handles password reset requests for university members.
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
    $email = trim($_POST['email'] ?? '');

    if (!empty($email)) {
        try {
            // Check if user exists
            $stmt = $pdo->prepare("SELECT id, first_name FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                /* LOGIC: In a production environment, you would generate a unique token, 
                   save it to a `password_resets` table, and send an email using PHPMailer.
                   For this implementation, we simulate the success message.
                */
                $message = "A reset link has been sent to your university email (" . htmlspecialchars($email) . "). Please check your inbox.";
                $messageType = "success";
            } else {
                // For security reasons, sometimes it's better to show the same message, 
                // but here we notify if the email isn't in the system.
                $message = "We couldn't find an account associated with that university email.";
                $messageType = "error";
            }
        } catch (PDOException $e) {
            $message = "A system error occurred. Please try again later.";
            $messageType = "error";
        }
    } else {
        $message = "Please enter your university email address.";
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
                <div class="flex gap-4 mb-8">
                    <div class="w-12 h-12 bg-white rounded-full flex items-center justify-center p-1">
                        <img src="../assets/images/system-icon.png" alt="Logo" class="w-full h-full object-contain" onerror="this.src='https://ui-avatars.com/api/?name=LF&background=fff&color=003366';">
                    </div>
                </div>

                <h1 class="text-3xl font-bold mb-4">Recovery Portal</h1>
                <p class="text-blue-100 text-lg leading-relaxed">
                    Forgot your password? No worries. Enter your registered CMU email address and we'll send you instructions to reset it.
                </p>
            </div>

            <div class="z-10 bg-white/10 p-4 rounded-xl border border-white/20">
                <p class="text-sm flex items-start gap-3">
                    <i class="fas fa-info-circle mt-1 text-amber-400"></i>
                    <span>For security reasons, reset links expire after 24 hours. Please check your "Spam" or "Junk" folder if you don't see the email.</span>
                </p>
            </div>
            
            <div class="absolute bottom-0 left-0 w-full h-32 bg-gradient-to-t from-black/20 to-transparent"></div>
        </div>

        <!-- Right Side: Request Form -->
        <div class="w-full md:w-1/2 p-8 md:p-12 flex flex-col justify-center">
            
            <div class="auth-card">
                <div class="mb-8">
                    <h2 class="text-2xl font-bold text-gray-800">Reset Password</h2>
                    <p class="text-gray-500 mt-1">Provide your university email to continue.</p>
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
                            <label class="block text-sm font-medium text-gray-700 mb-1">University Email Address</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                                    <i class="fas fa-envelope"></i>
                                </span>
                                <input type="email" name="email" required placeholder="example@cmu.edu.ph" 
                                    class="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none transition">
                            </div>
                        </div>

                        <button type="submit" class="w-full bg-cmu-blue text-white font-bold py-3 rounded-xl hover:bg-slate-800 transition shadow-lg">
                            Send Reset Link
                        </button>
                    </form>
                <?php else: ?>
                    <div class="text-center">
                        <a href="auth.php" class="inline-block px-6 py-2 bg-gray-100 text-gray-700 font-semibold rounded-lg hover:bg-gray-200 transition">
                            Return to Login
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