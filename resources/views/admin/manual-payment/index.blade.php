@extends('layouts.admin')

@section('title', 'Manual Payment Settings')

@section('breadcrumb')
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Manual Payment Settings</p>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Manual Payment Details</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Configure the bank account details that customers will see when they choose manual payment.</p>
    </div>

    <!-- Main Card -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8">
        <!-- Success Message -->
        @if (session('success'))
            <div class="mb-6 p-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 rounded-lg">
                <p class="text-emerald-700 dark:text-emerald-300 text-sm font-medium">✓ {{ session('success') }}</p>
            </div>
        @endif

        <!-- Error Messages -->
        @if ($errors->any())
            <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-lg">
                <ul class="text-red-700 dark:text-red-300 text-sm space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>• {{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.manual-payment.update') }}" class="space-y-6">
            @csrf

            <!-- Info Box -->
            <div class="p-4 bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                <p class="text-sm text-blue-900 dark:text-blue-300">
                    <strong>ℹ️ How this works:</strong> When customers select "Manual Payment" during checkout, they will see these bank details to make a transfer. After they submit the payment confirmation, an admin can review and approve it.
                </p>
            </div>

            <!-- Bank Details Section -->
            <fieldset class="space-y-4">
                <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Bank Account Information</legend>

                <!-- Bank Name -->
                <div>
                    <label for="bank_name" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
                        Bank Name
                        <span class="text-red-500">*</span>
                    </label>
                    <input type="text"
                           id="bank_name"
                           name="bank_name"
                           value="{{ $bankDetails['bank_name'] }}"
                           placeholder="e.g., Equity Bank Kenya"
                           class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white @error('bank_name') border-red-500 @enderror"
                           required>
                    @error('bank_name')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Account Name -->
                <div>
                    <label for="account_name" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
                        Account Name (Company Name)
                        <span class="text-red-500">*</span>
                    </label>
                    <input type="text"
                           id="account_name"
                           name="account_name"
                           value="{{ $bankDetails['account_name'] }}"
                           placeholder="e.g., Talksasa Cloud Limited"
                           class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white @error('account_name') border-red-500 @enderror"
                           required>
                    @error('account_name')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Account Number -->
                <div>
                    <label for="account_number" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
                        Account Number
                        <span class="text-red-500">*</span>
                    </label>
                    <input type="text"
                           id="account_number"
                           name="account_number"
                           value="{{ $bankDetails['account_number'] }}"
                           placeholder="e.g., 0123456789"
                           class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white @error('account_number') border-red-500 @enderror"
                           required>
                    @error('account_number')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Branch -->
                <div>
                    <label for="branch" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
                        Branch (Optional)
                    </label>
                    <input type="text"
                           id="branch"
                           name="branch"
                           value="{{ $bankDetails['branch'] }}"
                           placeholder="e.g., Westlands Branch"
                           class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white @error('branch') border-red-500 @enderror">
                    @error('branch')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <!-- SWIFT Code -->
                <div>
                    <label for="swift_code" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
                        SWIFT/BIC Code (Optional)
                    </label>
                    <input type="text"
                           id="swift_code"
                           name="swift_code"
                           value="{{ $bankDetails['swift_code'] }}"
                           placeholder="e.g., EQBLKENA"
                           class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white @error('swift_code') border-red-500 @enderror">
                    @error('swift_code')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            </fieldset>

            <!-- Preview -->
            <fieldset class="pt-6 border-t border-slate-200 dark:border-slate-800">
                <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Preview</legend>
                <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-700 rounded-lg">
                    <h3 class="font-semibold text-emerald-900 dark:text-emerald-300 mb-3">Customers will see this:</h3>
                    <div class="space-y-2 text-sm">
                        <div>
                            <p class="text-emerald-700 dark:text-emerald-400 text-xs font-semibold uppercase">Bank Name</p>
                            <p class="text-emerald-900 dark:text-emerald-200 font-bold">{{ $bankDetails['bank_name'] ?: '(Not set)' }}</p>
                        </div>
                        <div>
                            <p class="text-emerald-700 dark:text-emerald-400 text-xs font-semibold uppercase">Account Name</p>
                            <p class="text-emerald-900 dark:text-emerald-200 font-bold">{{ $bankDetails['account_name'] ?: '(Not set)' }}</p>
                        </div>
                        <div>
                            <p class="text-emerald-700 dark:text-emerald-400 text-xs font-semibold uppercase">Account Number</p>
                            <p class="text-emerald-900 dark:text-emerald-200 font-bold font-mono">{{ $bankDetails['account_number'] ?: '(Not set)' }}</p>
                        </div>
                        @if ($bankDetails['branch'])
                            <div>
                                <p class="text-emerald-700 dark:text-emerald-400 text-xs font-semibold uppercase">Branch</p>
                                <p class="text-emerald-900 dark:text-emerald-200">{{ $bankDetails['branch'] }}</p>
                            </div>
                        @endif
                        @if ($bankDetails['swift_code'])
                            <div>
                                <p class="text-emerald-700 dark:text-emerald-400 text-xs font-semibold uppercase">SWIFT Code</p>
                                <p class="text-emerald-900 dark:text-emerald-200 font-mono">{{ $bankDetails['swift_code'] }}</p>
                            </div>
                        @endif
                    </div>
                </div>
            </fieldset>

            <!-- Submit Button -->
            <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex gap-3">
                <a href="{{ route('admin.index') }}" class="px-6 py-2 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                    Cancel
                </a>
                <button type="submit" class="flex-1 px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                    Save Bank Details
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
