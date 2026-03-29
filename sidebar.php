<!-- Mobile Sidebar Overlay -->
<div id="mobile-overlay" class="fixed inset-0 bg-slate-900/60 z-30 hidden opacity-0 transition-opacity duration-300 lg:hidden" aria-hidden="true"></div>

<!-- Sidebar -->
<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>

<aside id="sidebar" class="bg-slate-900 text-slate-300 w-64 flex-shrink-0 flex flex-col z-40 fixed lg:relative inset-y-0 left-0 transform -translate-x-full lg:translate-x-0 transition-all duration-300 ease-in-out shadow-2xl lg:shadow-none">
    
    <!-- Header -->
    <div class="h-16 flex items-center justify-between px-4 border-b border-slate-800 bg-slate-950/30">
        <span class="text-xs font-bold uppercase tracking-widest text-slate-500 pl-2">Finora X</span>
        <button id="close-sidebar" class="p-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg lg:hidden">
            ✕
        </button>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 overflow-y-auto py-4 px-3 flex flex-col gap-1.5">

        <!-- Dashboard -->
        <a href="dashboard.php"
           class="px-3 py-2.5 rounded-lg font-medium 
           <?= $current_page == 'dashboard.php' ? 'bg-blue-600 text-white' : 'hover:bg-slate-800' ?>">
            Dashboard
        </a>

        <!-- Advisor -->
        <a href="advisor.php"
           class="px-3 py-2.5 rounded-lg 
           <?= $current_page == 'advisor.php' ? 'bg-blue-600 text-white' : 'hover:bg-slate-800' ?>">
            Advisor
        </a>

        <!-- Planner -->
        <a href="planner.php"
           class="px-3 py-2.5 rounded-lg 
           <?= $current_page == 'planner.php' ? 'bg-blue-600 text-white' : 'hover:bg-slate-800' ?>">
            Planner
        </a>

        <!-- Transactions -->
        <a href="transactions.php"
           class="px-3 py-2.5 rounded-lg 
           <?= $current_page == 'transactions.php' ? 'bg-blue-600 text-white' : 'hover:bg-slate-800' ?>">
            Transactions
        </a>

    </nav>

    <!-- Logout -->
    <div class="p-4 border-t border-slate-800">
        <a href="logout.php"
           class="block px-3 py-2.5 rounded-lg text-red-400 
           <?= $current_page == 'logout.php' ? 'bg-red-500/20' : 'hover:bg-red-500/10' ?>">
            Logout
        </a>
    </div>

</aside>

<!-- Sidebar Specific Styles -->
<style>
    /* Custom scrollbar for sidebar */
    .custom-scrollbar::-webkit-scrollbar {
        width: 4px;
    }
    .custom-scrollbar::-webkit-scrollbar-track {
        background: transparent;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb {
        background-color: #334155;
        border-radius: 20px;
    }
</style>

<!-- Sidebar Specific JavaScript -->
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const sidebar = document.getElementById('sidebar');
        const mobileOverlay = document.getElementById('mobile-overlay');
        const closeBtn = document.getElementById('close-sidebar');

        // 1. Global function to toggle sidebar (so dashboard.php can trigger it)
        window.toggleSidebar = function() {
            sidebar.classList.toggle('-translate-x-full'); // Mobile slide
            sidebar.classList.toggle('lg:-ml-64');         // Desktop collapse

            // Handle Mobile Overlay Fade
            if (mobileOverlay.classList.contains('hidden')) {
                mobileOverlay.classList.remove('hidden');
                requestAnimationFrame(() => mobileOverlay.classList.remove('opacity-0'));
            } else {
                mobileOverlay.classList.add('opacity-0');
                setTimeout(() => mobileOverlay.classList.add('hidden'), 300); 
            }
        };

        // 2. Attach Close Events (Overlay and X button)
        closeBtn.addEventListener('click', window.toggleSidebar);
        mobileOverlay.addEventListener('click', window.toggleSidebar);

        // 3. Submenu Dropdown Logic
        const submenuToggles = document.querySelectorAll('[data-submenu-toggle]');
        submenuToggles.forEach(toggle => {
            toggle.addEventListener('click', () => {
                const targetId = toggle.getAttribute('aria-controls');
                const targetMenu = document.getElementById(targetId);
                const chevron = toggle.querySelector('[data-chevron]');
                const isExpanded = toggle.getAttribute('aria-expanded') === 'true';

                toggle.setAttribute('aria-expanded', !isExpanded);

                if (!isExpanded) {
                    targetMenu.classList.remove('grid-rows-[0fr]');
                    targetMenu.classList.add('grid-rows-[1fr]');
                    chevron.classList.add('rotate-180');
                } else {
                    targetMenu.classList.remove('grid-rows-[1fr]');
                    targetMenu.classList.add('grid-rows-[0fr]');
                    chevron.classList.remove('rotate-180');
                }
            });
        });
    });
</script>