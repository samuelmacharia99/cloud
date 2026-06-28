{{-- Registration form v2026-06-28: first name required, last name optional --}}
@php
    $passwordMinLength = config('security.password.min_length', 8);
@endphp

<div
    x-data="{
        showPassword: false,
        showConfirmPassword: false,
        generatingPassword: false,
        async generatePassword() {
            this.generatingPassword = true;
            try {
                const res = await fetch('{{ route('register.generate-password') }}?length=16', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                });
                if (!res.ok) throw new Error('Failed');
                const data = await res.json();
                if (!data.password) throw new Error('Empty');
                document.getElementById('register-password').value = data.password;
                document.getElementById('register-password-confirmation').value = data.password;
                this.showPassword = true;
                this.showConfirmPassword = true;
            } catch {
                alert('Could not generate a password. Please try again or enter one manually.');
            } finally {
                this.generatingPassword = false;
            }
        }
    }"
    x-init="@if ($errors->any()) $nextTick(() => document.querySelector('.auth-field-error, .auth-input-error')?.scrollIntoView({ behavior: 'smooth', block: 'center' })) @endif"
    class="space-y-7"
>
    <div class="space-y-3 mb-2">
        <h1 class="text-4xl font-bold tracking-tight">Create your account</h1>
        <p class="text-base text-slate-600 dark:text-slate-400 font-medium">Enter your details below. We will email you a verification code.</p>
    </div>

    @if ($errors->any())
        <div class="auth-field-error rounded-lg p-4 text-sm" role="alert">
            <p class="font-semibold mb-2">We couldn't create your account yet:</p>
            <ul class="list-disc list-inside space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form
        id="register-form"
        method="POST"
        action="{{ route('register') }}"
        class="space-y-5"
        data-form-version="2026-06-28"
        novalidate
    >
        @csrf

        <input type="hidden" name="registration_token" value="{{ $registrationToken ?? session('registrationToken') ?? old('registration_token', '') }}">

        <div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
            <label for="register-honeypot">Leave blank</label>
            <input
                type="text"
                id="register-honeypot"
                name="{{ config('registration.honeypot_field', 'contact_website') }}"
                value=""
                tabindex="-1"
                autocomplete="off"
            >
        </div>

        <fieldset class="space-y-5 border-0 p-0 m-0">
            <legend class="sr-only">Account details</legend>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label for="register-first-name" class="block text-sm font-semibold text-slate-900 dark:text-white">
                        First name <span class="text-red-600 dark:text-red-400" aria-hidden="true">*</span>
                    </label>
                    <input
                        type="text"
                        id="register-first-name"
                        name="first_name"
                        value="{{ old('first_name') }}"
                        required
                        autofocus
                        autocomplete="given-name"
                        placeholder="Jane"
                        class="auth-input"
                        maxlength="127"
                    />
                    @error('first_name')
                        <p class="mt-1 text-xs font-medium auth-input-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-2">
                    <label for="register-last-name" class="block text-sm font-semibold text-slate-900 dark:text-white">
                        Last name
                        <span class="font-normal text-slate-500 dark:text-slate-400 text-xs">(optional)</span>
                    </label>
                    <input
                        type="text"
                        id="register-last-name"
                        name="last_name"
                        value="{{ old('last_name') }}"
                        autocomplete="family-name"
                        placeholder="Doe"
                        class="auth-input"
                        maxlength="127"
                    />
                    @error('last_name')
                        <p class="mt-1 text-xs font-medium auth-input-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <x-country-select
                name="country"
                label="Country"
                :value="old('country')"
                :required="true"
                variant="auth"
                placeholder="Select your country"
            />

            <div class="space-y-2">
                <label for="register-company" class="block text-sm font-semibold text-slate-900 dark:text-white">
                    Company
                    <span class="font-normal text-slate-500 dark:text-slate-400 text-xs">(optional)</span>
                </label>
                <input
                    type="text"
                    id="register-company"
                    name="company"
                    value="{{ old('company') }}"
                    autocomplete="organization"
                    placeholder="Acme Inc."
                    class="auth-input"
                />
                @error('company')
                    <p class="mt-1 text-xs font-medium auth-input-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-2">
                <label for="register-email" class="block text-sm font-semibold text-slate-900 dark:text-white">
                    Email address <span class="text-red-600 dark:text-red-400" aria-hidden="true">*</span>
                </label>
                <input
                    type="email"
                    id="register-email"
                    name="email"
                    value="{{ old('email') }}"
                    required
                    autocomplete="email"
                    placeholder="me@company.com"
                    class="auth-input"
                />
                @error('email')
                    <p class="mt-1 text-xs font-medium auth-input-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-2">
                <div class="flex items-center justify-between gap-3">
                    <label for="register-password" class="block text-sm font-semibold text-slate-900 dark:text-white">
                        Password <span class="text-red-600 dark:text-red-400" aria-hidden="true">*</span>
                    </label>
                    <button
                        type="button"
                        @click="generatePassword()"
                        :disabled="generatingPassword"
                        class="inline-flex items-center gap-1.5 text-xs font-semibold text-purple-600 dark:text-purple-400 hover:text-purple-700 dark:hover:text-purple-300 disabled:opacity-50 transition"
                    >
                        <span x-text="generatingPassword ? 'Generating…' : 'Generate password'"></span>
                    </button>
                </div>
                <div class="relative">
                    <input
                        :type="showPassword ? 'text' : 'password'"
                        id="register-password"
                        name="password"
                        required
                        autocomplete="new-password"
                        placeholder="Create a strong password"
                        class="auth-input pr-11"
                    />
                    <button
                        type="button"
                        @click="showPassword = !showPassword"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
                        aria-label="Show password"
                    >
                        <span x-show="!showPassword" x-cloak>Show</span>
                        <span x-show="showPassword" x-cloak>Hide</span>
                    </button>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    At least {{ $passwordMinLength }} characters with uppercase, lowercase, numbers, and symbols.
                </p>
                @error('password')
                    <p class="mt-1 text-xs font-medium auth-input-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-2">
                <label for="register-password-confirmation" class="block text-sm font-semibold text-slate-900 dark:text-white">
                    Confirm password <span class="text-red-600 dark:text-red-400" aria-hidden="true">*</span>
                </label>
                <div class="relative">
                    <input
                        :type="showConfirmPassword ? 'text' : 'password'"
                        id="register-password-confirmation"
                        name="password_confirmation"
                        required
                        autocomplete="new-password"
                        placeholder="Repeat your password"
                        class="auth-input pr-11"
                    />
                    <button
                        type="button"
                        @click="showConfirmPassword = !showConfirmPassword"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
                        aria-label="Show confirm password"
                    >
                        <span x-show="!showConfirmPassword" x-cloak>Show</span>
                        <span x-show="showConfirmPassword" x-cloak>Hide</span>
                    </button>
                </div>
                @error('password_confirmation')
                    <p class="mt-1 text-xs font-medium auth-input-error">{{ $message }}</p>
                @enderror
            </div>
        </fieldset>

        <div class="flex items-start gap-3">
            <input
                id="register-agree"
                type="checkbox"
                name="agree"
                value="1"
                required
                @checked(old('agree'))
                class="w-4 h-4 mt-1 rounded-md border-slate-300 dark:border-slate-600 text-purple-600 focus:ring-purple-500 cursor-pointer flex-shrink-0"
            >
            <label for="register-agree" class="text-xs text-slate-700 dark:text-slate-300 leading-relaxed cursor-pointer">
                I agree to the
                <a href="{{ route('terms') }}" target="_blank" rel="noopener" class="text-purple-600 dark:text-purple-400 hover:underline font-semibold">Terms of Service</a>
                and
                <a href="{{ route('privacy') }}" target="_blank" rel="noopener" class="text-purple-600 dark:text-purple-400 hover:underline font-semibold">Privacy Policy</a>
            </label>
        </div>
        @error('agree')
            <p class="text-xs font-medium auth-input-error">{{ $message }}</p>
        @enderror
        @error('registration_token')
            <p class="text-xs font-medium auth-input-error">{{ $message }}</p>
        @enderror

        <button type="submit" class="auth-btn-primary w-full">
            Create account
        </button>
    </form>

    <p class="text-center text-sm text-slate-600 dark:text-slate-400">
        Already have an account?
        <a href="{{ route('login') }}" class="font-semibold text-purple-600 dark:text-purple-400 hover:underline">Sign in</a>
    </p>
</div>
