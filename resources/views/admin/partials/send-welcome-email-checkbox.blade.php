<div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 p-4">
    <label class="flex items-start gap-3 cursor-pointer">
        <input
            type="checkbox"
            id="send_welcome_email"
            name="send_welcome_email"
            value="1"
            class="mt-1 w-4 h-4 text-blue-600 rounded border-slate-300 dark:border-slate-600"
            @checked(old('send_welcome_email'))
        >
        <span>
            <span class="block text-sm font-medium text-slate-900 dark:text-white">Send welcome email</span>
            <span class="block text-xs text-slate-500 dark:text-slate-400 mt-1">
                Email login details to the address above using the platform SMTP settings after the account is created.
            </span>
        </span>
    </label>
    @error('send_welcome_email')
        <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror
</div>
