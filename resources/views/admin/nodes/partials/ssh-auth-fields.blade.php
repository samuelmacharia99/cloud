@php
    /** @var \App\Models\Node|null $node */
    $node = $node ?? null;
    $required = $required ?? false;
    $currentMethod = old('ssh_auth_method', $node?->sshAuthMethod() ?? \App\Services\SSH\NodeSshCredentials::METHOD_PASSWORD);
    $hasPassword = filled($node?->ssh_password);
    $hasKey = (bool) $node?->hasSshPrivateKey();
    $fingerprint = $hasKey ? \App\Services\SSH\NodeSshCredentials::fingerprint($node) : null;
    $inputClass = 'w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm';
@endphp
<div class="lg:col-span-2" x-data="{ method: @js($currentMethod) }">
    <p class="block text-sm font-medium text-slate-900 dark:text-white mb-2">SSH Authentication</p>
    <div class="flex flex-wrap gap-4 mb-4">
        <label class="inline-flex items-center gap-2 text-sm text-slate-800 dark:text-slate-200 cursor-pointer">
            <input type="radio" name="ssh_auth_method" value="password" x-model="method" class="w-4 h-4 text-blue-600">
            Password
        </label>
        <label class="inline-flex items-center gap-2 text-sm text-slate-800 dark:text-slate-200 cursor-pointer">
            <input type="radio" name="ssh_auth_method" value="key" x-model="method" class="w-4 h-4 text-blue-600">
            SSH private key
        </label>
    </div>
    @error('ssh_auth_method')
        <p class="mb-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror

    <div x-show="method === 'password'" x-cloak>
        <label for="ssh_password" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">SSH Password</label>
        <input type="password" id="ssh_password" name="ssh_password" autocomplete="new-password"
               placeholder="{{ $hasPassword ? 'Leave blank to keep the stored password' : 'SSH password for the user above' }}"
               class="{{ $inputClass }} @error('ssh_password') border-red-500 @enderror">
        @error('ssh_password')
            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
        @enderror
        @if ($node)
            <p class="mt-1 text-xs {{ $hasPassword ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300' }}">
                {{ $hasPassword ? 'A password is on file.' : 'No password is on file yet.' }}
            </p>
        @endif
    </div>

    <div x-show="method === 'key'" x-cloak class="space-y-4">
        <div>
            <label for="ssh_private_key" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">SSH Private Key</label>
            <textarea id="ssh_private_key" name="ssh_private_key" rows="7" spellcheck="false"
                      placeholder="{{ $hasKey ? 'Leave blank to keep the stored key' : '-----BEGIN OPENSSH PRIVATE KEY-----' }}"
                      class="{{ $inputClass }} font-mono resize-y @error('ssh_private_key') border-red-500 @enderror">{{ old('ssh_private_key') }}</textarea>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                OpenSSH, PEM or PuTTY format. The matching public key must be in the SSH user's authorized_keys on the node. Stored encrypted; never shown again.
            </p>
            @error('ssh_private_key')
                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
            @if ($node)
                <p class="mt-1 text-xs {{ $hasKey ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300' }}">
                    @if ($hasKey)
                        A key is on file{{ $fingerprint ? ' · '.$fingerprint : '' }}.
                    @else
                        No key is on file yet.
                    @endif
                </p>
            @endif
        </div>
        <div>
            <label for="ssh_key_passphrase" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Key Passphrase <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(only if the key is encrypted)</span></label>
            <input type="password" id="ssh_key_passphrase" name="ssh_key_passphrase" autocomplete="new-password" placeholder="Leave blank for an unencrypted key"
                   class="{{ $inputClass }} @error('ssh_key_passphrase') border-red-500 @enderror">
            @error('ssh_key_passphrase')
                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>
    </div>

    @if ($node && ! $hasPassword && ! $hasKey)
        <p class="mt-3 text-sm text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 rounded px-3 py-2">
            ⚠ No SSH secret is stored. Health monitoring, provisioning and mail copies cannot log in until a password or key is saved.
        </p>
    @endif
</div>
