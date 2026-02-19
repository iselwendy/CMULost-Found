<div class="flex items-center space-x-4 relative">
   <div class="text-right hidden sm:block">
      <p class="text-xs text-blue-200">Logged in as</p>
      <p class="text-sm font-semibold">Abdul Montefalco</p>
   </div>
   <div class="relative">
      <button id="userMenuBtn" class="w-10 h-10 bg-white/10 rounded-full flex items-center justify-center hover:bg-white/20 transition focus:outline-none">
      <i class="fas fa-user-circle text-xl"></i>
      </button>
      <!-- Dropdown Menu -->
      <div id="userDropdown" class="absolute right-0 mt-3 w-56 bg-white rounded-xl shadow-xl border border-gray-100 py-2 z-50">
         <div class="px-4 py-3 border-b border-gray-50 md:hidden">
            <p class="text-xs text-gray-400">Logged in as</p>
            <p class="text-sm font-bold text-gray-800">Abdul Montefalco</p>
         </div>
         <a href="../dashboard/profile.php" class="flex items-center px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 transition">
         <i class="fas fa-user-edit w-5 text-gray-400"></i>
         <span>Edit Profile</span>
         </a>
         <a href="../dashboard/my_reports.php" class="flex items-center px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 transition">
         <i class="fas fa-clipboard-list w-5 text-gray-400"></i>
         <span>My Reports</span>
         </a>
         <a href="../dashboard/settings.php" class="flex items-center px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 transition">
         <i class="fas fa-cog w-5 text-gray-400"></i>
         <span>Account Settings</span>
         </a>
         <div class="border-t border-gray-50 mt-2">
            <a href="../core/logout.php" class="flex items-center px-4 py-2.5 text-sm text-red-600 font-bold hover:bg-red-50 transition">
            <i class="fas fa-sign-out-alt w-5"></i>
            <span>Sign Out</span>
            </a>
         </div>
      </div>
   </div>
</div>