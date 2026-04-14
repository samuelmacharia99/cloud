@extends('layouts.reseller')

@section('title', 'Edit Customer')

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Edit Customer</h1>
        <a href="{{ route('reseller.customers.show', $customer) }}" class="px-4 py-2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white text-sm font-medium">
            ← Back
        </a>
    </div>

    <form method="POST" action="{{ route('reseller.customers.update', $customer) }}" class="space-y-6">
        @csrf
        @method('PATCH')

        <!-- Basic Information -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Basic Information</h2>

            <div class="space-y-4">
                <!-- Name -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Full Name <span class="text-red-600">*</span></label>
                    <input type="text" name="name" value="{{ old('name', $customer->name) }}" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white @error('name') border-red-500 @enderror">
                    @error('name')<span class="text-red-600 text-sm">{{ $message }}</span>@enderror
                </div>

                <!-- Email -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Email <span class="text-red-600">*</span></label>
                    <input type="email" name="email" value="{{ old('email', $customer->email) }}" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white @error('email') border-red-500 @enderror">
                    @error('email')<span class="text-red-600 text-sm">{{ $message }}</span>@enderror
                </div>

                <!-- Password -->
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300">Password</label>
                        <button type="button" @click="showPassword = !showPassword" class="text-purple-600 dark:text-purple-400 text-sm hover:underline font-medium">
                            <span x-show="!showPassword">Show</span>
                            <span x-show="showPassword">Hide</span>
                        </button>
                    </div>
                    <div class="relative" x-data="{ showPassword: false, password: '' }">
                        <input
                            type="password"
                            name="password"
                            :type="showPassword ? 'text' : 'password'"
                            x-model="password"
                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white pr-12 @error('password') border-red-500 @enderror">
                        <button
                            type="button"
                            @click="
                                const length = 16;
                                const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
                                let pass = '';
                                for (let i = 0; i < length; i++) {
                                    pass += chars.charAt(Math.floor(Math.random() * chars.length));
                                }
                                password = pass;
                                document.querySelector('input[name=password]').value = pass;
                                document.querySelector('input[name=password_confirmation]').value = pass;
                            "
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-purple-600 dark:text-purple-400 hover:text-purple-700 dark:hover:text-purple-300 font-medium text-sm">
                            🔄 Generate
                        </button>
                    </div>

                    <!-- Password Strength -->
                    <div class="mt-2" x-data="{
                        getStrength(pass) {
                            if (!pass) return 0;
                            if (pass.length < 8) return 1;
                            let strength = 1;
                            if (/[a-z]/.test(pass) && /[A-Z]/.test(pass)) strength++;
                            if (/\d/.test(pass)) strength++;
                            if (/[^a-zA-Z\d]/.test(pass)) strength++;
                            return strength;
                        },
                        get strength() {
                            return this.getStrength(document.querySelector('input[name=password]')?.value || '');
                        }
                    }">
                        <div class="flex gap-1">
                            <div class="flex-1 h-2 rounded-full" :class="strength >= 1 ? 'bg-red-500' : 'bg-slate-300'"></div>
                            <div class="flex-1 h-2 rounded-full" :class="strength >= 2 ? 'bg-amber-500' : 'bg-slate-300'"></div>
                            <div class="flex-1 h-2 rounded-full" :class="strength >= 3 ? 'bg-emerald-500' : 'bg-slate-300'"></div>
                            <div class="flex-1 h-2 rounded-full" :class="strength >= 4 ? 'bg-blue-500' : 'bg-slate-300'"></div>
                        </div>
                        <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Leave blank to keep current password</p>
                    </div>

                    @error('password')<span class="text-red-600 text-sm">{{ $message }}</span>@enderror
                </div>

                <!-- Confirm Password -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Confirm Password</label>
                    <input type="password" name="password_confirmation" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white @error('password_confirmation') border-red-500 @enderror">
                    @error('password_confirmation')<span class="text-red-600 text-sm">{{ $message }}</span>@enderror
                </div>

                <!-- Status -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Status <span class="text-red-600">*</span></label>
                    <select name="status" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white @error('status') border-red-500 @enderror">
                        <option value="active" @selected(old('status', $customer->status) === 'active')>Active</option>
                        <option value="suspended" @selected(old('status', $customer->status) === 'suspended')>Suspended</option>
                        <option value="inactive" @selected(old('status', $customer->status) === 'inactive')>Inactive</option>
                    </select>
                    @error('status')<span class="text-red-600 text-sm">{{ $message }}</span>@enderror
                </div>
            </div>
        </div>

        <!-- Contact & Company Information -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Contact Information</h2>

            <div class="space-y-4">
                <!-- Phone -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Phone Number</label>
                    <input type="text" name="phone" value="{{ old('phone', $customer->phone) }}" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white @error('phone') border-red-500 @enderror">
                    @error('phone')<span class="text-red-600 text-sm">{{ $message }}</span>@enderror
                </div>

                <!-- Company -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Company Name</label>
                    <input type="text" name="company" value="{{ old('company', $customer->company) }}" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white @error('company') border-red-500 @enderror">
                    @error('company')<span class="text-red-600 text-sm">{{ $message }}</span>@enderror
                </div>

                <!-- Country -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Country</label>
                    <input type="text" name="country" value="{{ old('country', $customer->country) }}" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white @error('country') border-red-500 @enderror">
                    @error('country')<span class="text-red-600 text-sm">{{ $message }}</span>@enderror
                </div>

                <!-- Address -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Address</label>
                    <input type="text" name="address" value="{{ old('address', $customer->address) }}" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white @error('address') border-red-500 @enderror">
                    @error('address')<span class="text-red-600 text-sm">{{ $message }}</span>@enderror
                </div>

                <!-- City -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">City</label>
                    <input type="text" name="city" value="{{ old('city', $customer->city) }}" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white @error('city') border-red-500 @enderror">
                    @error('city')<span class="text-red-600 text-sm">{{ $message }}</span>@enderror
                </div>

                <!-- Postal Code -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Postal Code</label>
                    <input type="text" name="postal_code" value="{{ old('postal_code', $customer->postal_code) }}" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white @error('postal_code') border-red-500 @enderror">
                    @error('postal_code')<span class="text-red-600 text-sm">{{ $message }}</span>@enderror
                </div>

                <!-- VAT Number -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">VAT Number</label>
                    <input type="text" name="vat_number" value="{{ old('vat_number', $customer->vat_number) }}" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white @error('vat_number') border-red-500 @enderror">
                    @error('vat_number')<span class="text-red-600 text-sm">{{ $message }}</span>@enderror
                </div>

                <!-- Notes -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Notes</label>
                    <textarea name="notes" rows="4" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white @error('notes') border-red-500 @enderror">{{ old('notes', $customer->notes) }}</textarea>
                    @error('notes')<span class="text-red-600 text-sm">{{ $message }}</span>@enderror
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="flex gap-3 justify-end">
            <a href="{{ route('reseller.customers.show', $customer) }}" class="px-6 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-medium rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 transition">
                Cancel
            </a>
            <button type="submit" class="px-6 py-2.5 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">
                Save Changes
            </button>
        </div>
    </form>
</div>
@endsection
