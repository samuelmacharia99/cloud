@extends('layouts.admin')

@section('title', 'Edit Customer')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.customers.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Customers</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <a href="{{ route('admin.customers.show', $customer) }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $customer->name }}</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Edit</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Edit Customer</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Update customer details and account settings.</p>
    </div>

    <!-- Form -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <form method="POST" action="{{ route('admin.customers.update', $customer) }}" class="space-y-8">
            @csrf
            @method('PUT')

            <!-- Two-column layout -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Left Column -->
                <div class="space-y-6">
                    <!-- Full Name -->
                    <div>
                        <label for="name" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Full Name</label>
                        <input type="text" id="name" name="name" value="{{ old('name', $customer->name) }}" placeholder="John Doe" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('name') border-red-500 @enderror" required>
                        @error('name')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Email -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Email Address</label>
                        <input type="email" id="email" name="email" value="{{ old('email', $customer->email) }}" placeholder="john@example.com" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('email') border-red-500 @enderror" required>
                        @error('email')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Company -->
                    <div>
                        <label for="company" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Company <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <input type="text" id="company" name="company" value="{{ old('company', $customer->company) }}" placeholder="Company Name Inc." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        @error('company')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Country -->
                    <div>
                        <label for="country" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Country <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <input type="text" id="country" name="country" value="{{ old('country', $customer->country) }}" placeholder="United States" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        @error('country')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Phone -->
                    <div>
                        <label for="phone" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Phone <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <input type="tel" id="phone" name="phone" value="{{ old('phone', $customer->phone) }}" placeholder="+1 (555) 000-0000" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        @error('phone')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Address -->
                    <div>
                        <label for="address" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Address <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <input type="text" id="address" name="address" value="{{ old('address', $customer->address) }}" placeholder="123 Main Street" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        @error('address')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- City -->
                    <div>
                        <label for="city" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">City <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <input type="text" id="city" name="city" value="{{ old('city', $customer->city) }}" placeholder="New York" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        @error('city')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Postal Code -->
                    <div>
                        <label for="postal_code" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Postal Code <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <input type="text" id="postal_code" name="postal_code" value="{{ old('postal_code', $customer->postal_code) }}" placeholder="10001" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        @error('postal_code')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <!-- Right Column -->
                <div class="space-y-6">
                    <!-- Account Status -->
                    <div>
                        <label for="status" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Account Status</label>
                        <select id="status" name="status" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('status') border-red-500 @enderror" required>
                            <option value="active" @selected(old('status', $customer->status) === 'active')>Active</option>
                            <option value="suspended" @selected(old('status', $customer->status) === 'suspended')>Suspended</option>
                            <option value="inactive" @selected(old('status', $customer->status) === 'inactive')>Inactive</option>
                        </select>
                        @error('status')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Password -->
                    <div x-data="{ showPassword: false, passwordStrength: 0 }">
                        <div class="flex items-center justify-between mb-2">
                            <label for="password" class="block text-sm font-medium text-slate-900 dark:text-white">Password <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(leave blank to keep current)</span></label>
                            <button type="button" @click="
                                const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
                                let password = '';
                                for (let i = 0; i < 16; i++) {
                                    password += chars.charAt(Math.floor(Math.random() * chars.length));
                                }
                                document.getElementById('password').value = password;
                                document.getElementById('password_confirmation').value = password;

                                // Calculate password strength
                                let strength = 0;
                                if (password.length >= 8) strength++;
                                if (password.length >= 12) strength++;
                                if (/[a-z]/.test(password)) strength++;
                                if (/[A-Z]/.test(password)) strength++;
                                if (/[0-9]/.test(password)) strength++;
                                if (/[!@#$%^&*]/.test(password)) strength++;
                                passwordStrength = strength;
                            " class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition">
                                🔄 Generate
                            </button>
                        </div>
                        <div class="relative">
                            <input
                                :type="showPassword ? 'text' : 'password'"
                                id="password"
                                name="password"
                                placeholder="Enter a new password (optional)"
                                class="w-full px-4 py-2 pr-10 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('password') border-red-500 @enderror">
                            <button type="button" @click="showPassword = !showPassword" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
                                <svg x-show="!showPassword" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                <svg x-show="showPassword" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-4.803m5.596-3.856a3.375 3.375 0 11-4.753 4.753m4.753-4.753L3.596 3.596m16.807 16.807L6.404 6.404m9.596 9.596a3 3 0 10-4.242-4.242m4.242 4.242L9.172 9.172"/>
                                </svg>
                            </button>
                        </div>
                        @error('password')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                        <!-- Password Strength Indicator -->
                        <div class="mt-2 h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                            <div
                                class="h-full transition-all"
                                :class="{
                                    'w-1/6 bg-red-500': passwordStrength === 1,
                                    'w-2/6 bg-orange-500': passwordStrength === 2,
                                    'w-3/6 bg-yellow-500': passwordStrength === 3,
                                    'w-4/6 bg-lime-500': passwordStrength === 4,
                                    'w-5/6 bg-emerald-500': passwordStrength === 5,
                                    'w-full bg-emerald-600': passwordStrength >= 6,
                                    'w-0': passwordStrength === 0
                                }">
                            </div>
                        </div>
                    </div>

                    <!-- Confirm Password -->
                    <div x-data="{ showConfirmPassword: false }">
                        <label for="password_confirmation" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Confirm Password</label>
                        <div class="relative">
                            <input
                                :type="showConfirmPassword ? 'text' : 'password'"
                                id="password_confirmation"
                                name="password_confirmation"
                                placeholder="Confirm new password"
                                class="w-full px-4 py-2 pr-10 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                            <button type="button" @click="showConfirmPassword = !showConfirmPassword" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
                                <svg x-show="!showConfirmPassword" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                <svg x-show="showConfirmPassword" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-4.803m5.596-3.856a3.375 3.375 0 11-4.753 4.753m4.753-4.753L3.596 3.596m16.807 16.807L6.404 6.404m9.596 9.596a3 3 0 10-4.242-4.242m4.242 4.242L9.172 9.172"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- VAT Number -->
                    <div>
                        <label for="vat_number" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">VAT/Tax ID <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <input type="text" id="vat_number" name="vat_number" value="{{ old('vat_number', $customer->vat_number) }}" placeholder="VAT123456" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        @error('vat_number')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Notes -->
                    <div class="flex-1">
                        <label for="notes" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Notes <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <textarea id="notes" name="notes" rows="6" placeholder="Add any internal notes about this customer..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm resize-none">{{ old('notes', $customer->notes) }}</textarea>
                        @error('notes')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="flex items-center justify-between pt-6 border-t border-slate-200 dark:border-slate-800">
                <a href="{{ route('admin.customers.show', $customer) }}" class="px-6 py-2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium transition">
                    Cancel
                </a>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
