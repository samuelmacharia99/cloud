@extends('layouts.admin')

@section('title', 'Edit Reseller')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.resellers.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Resellers</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <a href="{{ route('admin.resellers.show', $user) }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $user->name }}</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Edit</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Edit Reseller</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Update reseller account details.</p>
    </div>

    <!-- Form -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <form method="POST" action="{{ route('admin.resellers.update', $user) }}" class="space-y-8">
            @csrf
            @method('PUT')

            <!-- Two-column layout -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Left Column -->
                <div class="space-y-6">
                    <!-- Full Name -->
                    <div>
                        <label for="name" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Full Name</label>
                        <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" placeholder="John Doe" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('name') border-red-500 @enderror" required>
                        @error('name')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Email -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Email Address</label>
                        <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" placeholder="reseller@example.com" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('email') border-red-500 @enderror" required>
                        @error('email')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Company -->
                    <div>
                        <label for="company" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Company <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <input type="text" id="company" name="company" value="{{ old('company', $user->company) }}" placeholder="Company Name Inc." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        @error('company')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Country -->
                    <div>
                        <label for="country" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Country <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <input type="text" id="country" name="country" value="{{ old('country', $user->country) }}" placeholder="Kenya" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        @error('country')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Phone -->
                    <div>
                        <label for="phone" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Phone <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <input type="tel" id="phone" name="phone" value="{{ old('phone', $user->phone) }}" placeholder="+254 712 345 678" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        @error('phone')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Notes -->
                    <div>
                        <label for="notes" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Notes <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <textarea id="notes" name="notes" rows="5" placeholder="Add any internal notes about this reseller..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm resize-none">{{ old('notes', $user->notes) }}</textarea>
                        @error('notes')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <!-- Right Column -->
                <div class="space-y-6">
                    <!-- Password -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Password <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(leave blank to keep current)</span></label>
                        <div class="relative">
                            <input type="password" id="password" name="password" placeholder="Leave blank to keep current password" class="w-full px-4 py-2 pr-12 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('password') border-red-500 @enderror">
                            <button
                                type="button"
                                id="generate-reseller-password"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition"
                                title="Generate password"
                                aria-label="Generate password">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                </svg>
                            </button>
                        </div>
                        @error('password')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                        <p id="generated-password-hint" class="mt-1 text-xs text-emerald-600 dark:text-emerald-400 hidden">Password generated and applied.</p>
                    </div>

                    <!-- Confirm Password -->
                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Confirm Password</label>
                        <input type="password" id="password_confirmation" name="password_confirmation" placeholder="Confirm password" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                    </div>

                    <!-- Reseller Package Section Header -->
                    <div class="pt-6 border-t border-slate-200 dark:border-slate-800">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Reseller Package</h3>
                    </div>

                    <!-- Package Selection -->
                    <div>
                        <label for="reseller_package_id" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Package <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <select id="reseller_package_id" name="reseller_package_id" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('reseller_package_id') border-red-500 @enderror">
                            <option value="">— No package —</option>
                            @foreach ($packages->groupBy('billing_cycle') as $cycle => $cyclePackages)
                                <optgroup label="{{ ucfirst($cycle) }}">
                                    @foreach ($cyclePackages as $package)
                                        <option value="{{ $package->id }}" @selected(old('reseller_package_id', $user->reseller_package_id) == $package->id)>
                                            {{ $package->name }} — Ksh {{ number_format($package->price, 0) }}/{{ $cycle === 'monthly' ? 'mo' : 'yr' }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @error('reseller_package_id')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">You can change the package anytime.</p>
                    </div>

                    <div>
                        <label for="commission_rate" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Commission rate (%)</label>
                        <input type="number" step="0.01" min="0" max="100" id="commission_rate" name="commission_rate" value="{{ old('commission_rate', $user->commission_rate) }}" placeholder="e.g. 25" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white text-sm">
                        @error('commission_rate')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Percentage of managed customer revenue attributed to this reseller (for reporting).</p>
                    </div>

                    @include('admin.resellers.partials.directadmin-fields', ['user' => $user])
                </div>
            </div>

            <!-- Form Actions -->
            <div class="flex items-center justify-between pt-6 border-t border-slate-200 dark:border-slate-800">
                <a href="{{ route('admin.resellers.show', $user) }}" class="px-6 py-2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium transition">
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

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const passwordInput = document.getElementById('password');
    const confirmInput = document.getElementById('password_confirmation');
    const generateBtn = document.getElementById('generate-reseller-password');
    const hint = document.getElementById('generated-password-hint');

    if (!passwordInput || !confirmInput || !generateBtn) {
        return;
    }

    generateBtn.addEventListener('click', async () => {
        try {
            generateBtn.disabled = true;
            const res = await fetch("{{ route('admin.customers.generate-password') }}?length=16", {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            if (!res.ok) {
                throw new Error('Failed to generate password');
            }

            const data = await res.json();
            const generated = data.password || '';
            if (!generated) {
                throw new Error('No password returned');
            }

            passwordInput.value = generated;
            confirmInput.value = generated;
            passwordInput.type = 'text';
            confirmInput.type = 'text';

            if (hint) {
                hint.classList.remove('hidden');
            }
        } catch (error) {
            console.error(error);
            alert('Failed to generate password. Please try again.');
        } finally {
            generateBtn.disabled = false;
        }
    });
});
</script>
@endpush
