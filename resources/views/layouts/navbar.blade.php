<header class="h-16 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between px-8">
    <div class="flex items-center gap-4 md:hidden">
        <button class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg" onclick="document.getElementById('sidebar').classList.toggle('hidden')">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>
    </div>

    <div class="flex-1"></div>

    <div class="flex items-center gap-4">
        @auth
            <div class="flex items-center gap-3 text-sm">
                <div class="text-right">
                    <p class="font-medium text-slate-900 dark:text-white">{{ auth()->user()->name }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        @if (auth()->user()->is_admin)
                            Administrator
                        @else
                            Customer
                        @endif
                    </p>
                </div>
                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white text-xs font-bold">
                    {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                </div>
            </div>
        @endauth
    </div>
</header>
