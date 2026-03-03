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
</head>
<body class="bg-gray-100 min-h-screen">

    <!-- Navbar -->
    <?php require_once '../includes/header.php'; ?>

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
                <section id="profile" class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-50">
                        <h3 class="text-lg font-bold text-gray-900">Profile Information</h3>
                        <p class="text-sm text-gray-500">Update your public profile and university details.</p>
                    </div>
                    <div class="p-8">
                        <form action="update_profile.php" method="POST" class="space-y-6">
                            <!-- Avatar Upload -->
                            <div class="flex items-center gap-6 pb-6 border-b border-gray-50">
                                <div class="relative">
                                    <img src="https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="Avatar" class="w-20 h-20 rounded-2xl object-cover border-2 border-gray-100">
                                    <label class="absolute -bottom-2 -right-2 bg-cmu-blue text-white p-1.5 rounded-lg cursor-pointer shadow-md hover:scale-110 transition">
                                        <i class="fas fa-camera text-xs"></i>
                                        <input type="file" class="hidden">
                                    </label>
                                </div>
                                <div>
                                    <h4 class="text-sm font-bold text-gray-800">Profile Picture</h4>
                                    <p class="text-xs text-gray-400 mt-1">JPG, PNG or GIF. Max size 2MB.</p>
                                </div>
                            </div>

                            <!-- Basic Info -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="md:col-span-2">
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Full Name</label>
                                    <input type="text" value="Abdul Montefalco" class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-cmu-blue outline-none transition text-sm font-medium">
                                </div>
                                
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Department</label>
                                    <select class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-cmu-blue outline-none transition text-sm font-medium">
                                        <option selected>College of Computer Studies</option>
                                        <option>College of Arts and Sciences</option>
                                        <option>College of Agriculture</option>
                                        <option>College of Nursing</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Course & Year</label>
                                    <input type="text" value="BSIT 1A" class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-cmu-blue outline-none transition text-sm font-medium">
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
                                            <input type="email" value="abdul.montefalco@cityofmalabonuniversity.edu.ph" class="w-full pl-11 pr-4 py-3 bg-gray-100 border border-gray-200 rounded-xl text-gray-500 outline-none text-sm font-medium cursor-not-allowed" readonly>
                                        </div>
                                        <p class="text-[10px] text-gray-400 mt-1 italic">University email cannot be changed.</p>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Secondary Email</label>
                                        <div class="relative">
                                            <i class="fas fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                            <input type="email" placeholder="e.g. personal@gmail.com" class="w-full pl-11 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-cmu-blue outline-none transition text-sm font-medium">
                                        </div>
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Phone Number</label>
                                        <div class="relative">
                                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-sm font-bold text-gray-400">+63</span>
                                            <input type="tel" placeholder="912 345 6789" class="w-full pl-14 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-cmu-blue outline-none transition text-sm font-medium">
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
                    <div class="p-6 border-b border-gray-50">
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
                    <div class="p-6 border-b border-gray-50">
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
</body>
</html>