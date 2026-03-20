<?php
require_once __DIR__ . '/auth_functions.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: ../public/index.php");
    exit();
}

$authResult = handleLogin($pdo);
$message = $authResult['text'] ?? "";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CMU Lost & Found - Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="../assets/images/system-icon.png">
    <style>
        :root {
            --cmu-blue: #003366;
        }
        .bg-cmu-blue { background-color: var(--cmu-blue); }
        .text-cmu-blue { color: var(--cmu-blue); }
        .border-cmu-blue { border-color: var(--cmu-blue); }
        .auth-card { transition: all 0.3s ease; }

        .legal-tooltip {
            visibility: hidden;
            opacity: 0;
            transition: opacity 0.2s;
            bottom: 120%;
        }
        .legal-link:hover .legal-tooltip {
            visibility: visible;
            opacity: 1;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">

    <!-- Main Container -->
    <div class="max-w-4xl w-full bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col md:flex-row min-h-[600px]">
        
        <!-- Left Side: Information/Branding -->
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

                <h1 class="text-3xl font-bold mb-4">CMU Lost & Found</h1>
                <p class="text-blue-100 text-lg leading-relaxed">
                    Connecting lost items with their rightful owners at City of Malabon University. 
                    Login with your university credentials to get started.
                </p>
            </div>

            <div class="z-10 mt-8">
                <div class="flex items-center space-x-4 mb-6">
                    <div class="w-10 h-10 rounded-lg bg-white/10 flex items-center justify-center">
                        <i class="fas fa-shield-alt text-amber-400"></i>
                    </div>
                    <div>
                        <h4 class="font-semibold">Secure Verification</h4>
                        <p class="text-xs text-blue-200">Standard Secure Login</p>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="w-10 h-10 rounded-lg bg-white/10 flex items-center justify-center">
                        <i class="fas fa-bell text-amber-400"></i>
                    </div>
                    <div>
                        <h4 class="font-semibold">Real-time SMS</h4>
                        <p class="text-xs text-blue-200">Instant alerts for matches</p>
                    </div>
                </div>
            </div>

            <div class="absolute bottom-0 left-0 w-full h-32 bg-gradient-to-t from-black/20 to-transparent"></div>
        </div>

        <!-- Right Side: Login Form -->
        <div class="w-full md:w-1/2 p-8 md:p-12 flex flex-col justify-center">
            
            <?php if (!empty($message)): ?>
                <div class="mb-4 p-3 <?php echo $messageType === 'error' ? 'bg-red-50 border-red-500 text-red-700' : 'bg-blue-50 border-cmu-blue text-cmu-blue'; ?> border-l-4 text-sm rounded shadow-sm transition-all">
                    <i class="fas <?php echo $messageType === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'; ?> mr-2"></i> 
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div id="login-section" class="auth-card">
                <div class="mb-8">
                    <h2 class="text-2xl font-bold text-gray-800">Welcome Back</h2>
                    <p class="text-gray-500 mt-1">Please enter your university details to access the portal.</p>
                </div>

                <form action="auth.php" method="POST" class="space-y-5">
                    <input type="hidden" name="action" value="login">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">University Email</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                                <i class="fas fa-envelope"></i>
                            </span>
                            <input type="email" name="cmu_email" required placeholder="email@cityofmalabonuniversity.edu.ph" class="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none transition">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                                <i class="fas fa-lock"></i>
                            </span>
                            <input type="password" name="password" required placeholder="••••••••" class="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none transition">
                        </div>
                        <div class="text-right mt-4">
                            <a href="forgot_password.php" class="text-sm text-blue-600 hover:underline">Forgot password?</a>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-cmu-blue text-white font-bold py-3 rounded-xl hover:bg-slate-800 transition shadow-lg">
                        Log In
                    </button>
                </form>

                <p class="mt-8 text-center text-sm text-gray-500">
                    By logging in, you agree to our <br>
                    <a href="../public/legal.html" class="text-cmu-blue font-bold hover:underline">Terms & Privacy Policy</a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>