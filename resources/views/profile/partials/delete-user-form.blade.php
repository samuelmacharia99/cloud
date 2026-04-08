<section class="space-y-6" x-data="{ showDeleteModal: @json($errors->has('password')) }">
    <header>
        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">
            Delete Account
        </h2>
        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
            Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.
        </p>
    </header>

    <div class="p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-lg">
        <p class="text-sm text-red-800 dark:text-red-200 mb-4">
            <strong>Warning:</strong> This action cannot be undone. All your services, invoices, and account data will be permanently deleted.
        </p>
        <button
            type="button"
            @click="showDeleteModal = true"
            class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition"
        >
            Delete My Account
        </button>
    </div>

    <!-- Confirmation Modal -->
    <div
        x-show="showDeleteModal"
        class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center"
        @keydown.escape="showDeleteModal = false"
        style="display: none;"
    >
        <!-- Backdrop -->
        <div
            x-show="showDeleteModal"
            @click="showDeleteModal = false"
            class="fixed inset-0 bg-black/50 transition-opacity"
        ></div>

        <!-- Modal -->
        <div
            x-show="showDeleteModal"
            x-transition
            class="relative bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xl max-w-md w-full mx-4 z-10"
        >
            <form method="post" action="{{ route('profile.destroy') }}" class="p-6 space-y-4">
                @csrf
                @method('delete')

                <div class="flex items-start gap-3">
                    <div class="flex-shrink-0">
                        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4v2m0 4v2M6.228 6.228a9 9 0 1112.544 0M9 11a3 3 0 11-6 0 3 3 0 016 0zm6-3a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">
                            Delete Account?
                        </h3>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">
                            This will permanently delete your account and all associated data. This action cannot be undone.
                        </p>
                    </div>
                </div>

                <!-- Password Confirmation -->
                <div>
                    <label for="password" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
                        Enter your password to confirm
                    </label>
                    <input
                        id="password"
                        type="password"
                        name="password"
                        placeholder="Your password"
                        required
                        autofocus
                        class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:ring-2 focus:ring-red-500 focus:border-transparent transition"
                    />
                    @error('password', 'userDeletion')
                        <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Action Buttons -->
                <div class="flex gap-3 pt-4 border-t border-slate-200 dark:border-slate-700">
                    <button
                        type="button"
                        @click="showDeleteModal = false"
                        class="flex-1 px-4 py-2 text-slate-700 dark:text-slate-300 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 font-medium rounded-lg transition"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="flex-1 px-4 py-2 text-white bg-red-600 hover:bg-red-700 font-medium rounded-lg transition"
                    >
                        Delete Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</section>
