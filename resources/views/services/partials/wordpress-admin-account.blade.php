@php
    /** @var array<string, mixed> $wordpressAdminPanel */
    $wpAdmin = $wordpressAdminPanel;
    $newPassword = session('wordpress_admin_password');
    $newPasswordUser = session('wordpress_admin_username');
@endphp
<div class="rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-5 space-y-4" x-data="{ mode: 'generate', showPassword: false, editingEmail: false }">
    <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">WordPress admin</h3>
            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                @if ($wpAdmin['known'])
                    Administrator <span class="font-mono">{{ $wpAdmin['admin_username'] }}</span>@if ($wpAdmin['admin_email']), <span class="font-mono">{{ $wpAdmin['admin_email'] }}</span>@endif.
                @else
                    The site's administrator is looked up on the site itself when you reset the password or change the email.
                @endif
                @if ($wpAdmin['password_reset_at'])
                    Password last reset {{ \Carbon\Carbon::parse($wpAdmin['password_reset_at'])->diffForHumans() }}.
                @endif
            </p>
        </div>
    </div>

    @if ($newPassword)
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 dark:bg-emerald-950/40 p-4 space-y-2">
            <p class="text-sm font-medium text-emerald-900 dark:text-emerald-100">New password for {{ $newPasswordUser }}. It is shown only once; copy it now.</p>
            <div class="flex items-center gap-3 flex-wrap">
                <code class="px-3 py-1.5 rounded-lg bg-white dark:bg-slate-900 border border-emerald-200 text-sm font-mono" x-text="showPassword ? @js($newPassword) : '•'.repeat(20)"></code>
                <button type="button" class="text-xs font-medium text-emerald-800 dark:text-emerald-200 underline" @click="showPassword = !showPassword" x-text="showPassword ? 'Hide' : 'Show'"></button>
                <button type="button" class="text-xs font-medium text-emerald-800 dark:text-emerald-200 underline" @click="navigator.clipboard.writeText(@js($newPassword))">Copy</button>
            </div>
        </div>
    @endif

    @if (! $wpAdmin['container_running'])
        <p class="text-sm text-amber-700 dark:text-amber-300">Start the site to reset its admin password or change the admin email.</p>
    @else
        <form method="POST" action="{{ $wpAdminPasswordRoute }}" class="space-y-3">
            @csrf
            <p class="text-sm font-medium text-slate-900 dark:text-white">Reset the admin password</p>
            <div class="flex flex-wrap gap-4 text-sm">
                <label class="flex items-center gap-2"><input type="radio" name="mode" value="generate" x-model="mode"> Generate a strong password and show it once</label>
                <label class="flex items-center gap-2"><input type="radio" name="mode" value="custom" x-model="mode"> Choose my own</label>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" x-show="mode === 'custom'" x-cloak>
                <input type="password" name="password" autocomplete="new-password" minlength="{{ $wpAdmin['min_password_length'] }}" placeholder="New password ({{ $wpAdmin['min_password_length'] }}+ characters)" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm" :required="mode === 'custom'">
                <input type="password" name="password_confirmation" autocomplete="new-password" placeholder="Repeat the new password" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm" :required="mode === 'custom'">
            </div>
            <div class="flex items-center gap-3 flex-wrap">
                <button class="px-4 py-2 bg-slate-900 hover:bg-slate-800 dark:bg-white dark:hover:bg-slate-100 dark:text-slate-900 text-white rounded-lg text-sm font-medium" onclick="return confirm('Reset the WordPress admin password? Every signed-in session for that user is closed.')">Reset password</button>
                <span class="text-xs text-slate-500">Signs that administrator out everywhere. Other users are not affected.</span>
            </div>
        </form>

        <div class="border-t border-slate-200 dark:border-slate-700 pt-4">
            <button type="button" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:underline" @click="editingEmail = !editingEmail" x-text="editingEmail ? 'Cancel email change' : 'Change the admin email'"></button>
            <form method="POST" action="{{ $wpAdminEmailRoute }}" class="mt-3 flex flex-wrap items-end gap-3" x-show="editingEmail" x-cloak>
                @csrf
                <div>
                    <label class="block text-xs font-medium mb-1">New admin email</label>
                    <input type="email" name="email" required value="{{ old('email', $wpAdmin['admin_email'] ?? '') }}" class="w-72 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm">
                </div>
                <button class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm font-medium">Save email</button>
                <p class="basis-full text-xs text-slate-500">Password-recovery mail and WordPress notices go to this address.</p>
            </form>
        </div>
    @endif
</div>
