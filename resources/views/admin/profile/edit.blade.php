@extends('layouts.admin')

@section('title', 'Admin Profile')

@section('breadcrumb')
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Admin Profile</p>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">My Profile</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Manage your admin account settings and notification phone numbers.</p>
    </div>

    <!-- Profile Form Card -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800">
        <!-- Success Message -->
        @if (session('success'))
            <div class="bg-emerald-50 dark:bg-emerald-900/20 border-b border-emerald-200 dark:border-emerald-700 p-4">
                <p class="text-emerald-700 dark:text-emerald-300 text-sm font-medium">✓ {{ session('success') }}</p>
            </div>
        @endif

        <!-- Error Messages -->
        @if ($errors->any())
            <div class="bg-red-50 dark:bg-red-900/20 border-b border-red-200 dark:border-red-700 p-4">
                <ul class="text-red-700 dark:text-red-300 text-sm space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>• {{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="p-8">
            <form method="POST" action="{{ route('admin.profile.update') }}" class="space-y-6">
                @csrf
                @method('PATCH')

                <!-- Basic Info Section -->
                <fieldset>
                    <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Basic Information</legend>

                    <div class="space-y-4">
                        <!-- Name -->
                        <div>
                            <label for="name" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
                                Full Name
                                <span class="text-red-500">*</span>
                            </label>
                            <input type="text"
                                   id="name"
                                   name="name"
                                   value="{{ old('name', $admin->name) }}"
                                   class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white @error('name') border-red-500 @enderror"
                                   required>
                            @error('name')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Email -->
                        <div>
                            <label for="email" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
                                Email Address
                                <span class="text-red-500">*</span>
                            </label>
                            <input type="email"
                                   id="email"
                                   name="email"
                                   value="{{ old('email', $admin->email) }}"
                                   class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white @error('email') border-red-500 @enderror"
                                   required>
                            @error('email')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Phone -->
                        <div>
                            <label for="phone" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
                                Primary Phone (Optional)
                            </label>
                            <input type="text"
                                   id="phone"
                                   name="phone"
                                   value="{{ old('phone', $admin->phone) }}"
                                   placeholder="e.g., 0712345678 or +254712345678"
                                   class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white @error('phone') border-red-500 @enderror">
                            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Will be automatically normalized to 254XXXXXXXXX format</p>
                            @error('phone')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </fieldset>

                <!-- Notification Phones Section -->
                <fieldset class="pt-6 border-t border-slate-200 dark:border-slate-800">
                    <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">
                        Notification Phone Numbers
                        <span class="text-sm font-normal text-slate-600 dark:text-slate-400">(For Order & Payment SMS)</span>
                    </legend>

                    <!-- Info Box -->
                    <div class="mb-4 p-4 bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                        <p class="text-sm text-blue-900 dark:text-blue-300">
                            <strong>ℹ️ How this works:</strong> These phone numbers will receive SMS notifications about new orders, payments, and system events. You can add up to 10 numbers. They will be automatically normalized to Kenyan format (254XXXXXXXXX).
                        </p>
                    </div>

                    <!-- Notification Phones List -->
                    <div x-data="notificationPhones()" class="space-y-3">
                        <!-- Existing Phones -->
                        <template x-for="(phone, index) in phones" :key="index">
                            <div class="flex gap-2 items-end">
                                <div class="flex-1">
                                    <input type="text"
                                           name="notification_phones[]"
                                           x-model="phones[index]"
                                           placeholder="e.g., 0712345678"
                                           class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                                </div>
                                <button type="button"
                                        @click="phones.splice(index, 1)"
                                        class="px-3 py-2 text-red-600 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg font-medium transition text-sm">
                                    Remove
                                </button>
                            </div>
                        </template>

                        <!-- Add Phone Button -->
                        <button type="button"
                                @click="phones.push('')"
                                x-show="phones.length < 10"
                                class="mt-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition text-sm">
                            + Add Phone Number
                        </button>

                        <!-- Count -->
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-4">
                            <span x-text="phones.length"></span> / 10 phone numbers added
                        </p>
                    </div>

                    @error('notification_phones')
                        <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </fieldset>

                <!-- Submit Button -->
                <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex gap-3">
                    <a href="{{ route('admin.settings.index') }}" class="px-6 py-2 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                        Back
                    </a>
                    <button type="submit" class="flex-1 px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                        Save Profile Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function notificationPhones() {
    return {
        phones: [
            @if ($admin->notification_phones)
                @json($admin->notification_phones)
            @else
                []
            @endif
        ]
    }
}
</script>
@endsection
