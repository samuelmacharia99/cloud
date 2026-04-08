@extends('layouts.customer')

@section('title', 'Transfer Domain')

@section('breadcrumb')
<div class="flex items-center gap-2">
    <a href="{{ route('customer.domains.index') }}" class="text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
        Domains
    </a>
    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
    </svg>
    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Transfer Domain</p>
</div>
@endsection

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Transfer Domain</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-2">Move your domain from another registrar to us. It takes just a few minutes!</p>
    </div>

    <!-- Info Box -->
    <div class="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-xl p-4">
        <div class="flex gap-3">
            <svg class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
            </svg>
            <div class="text-sm text-blue-900 dark:text-blue-300">
                <strong>Before you start:</strong> You'll need the EPP code (authorization code) from your current registrar. It's usually found in your domain settings.
            </div>
        </div>
    </div>

    <!-- Transfer Form -->
    <form id="transferForm" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8 space-y-6">
        @csrf

        <!-- Domain Name -->
        <div>
            <label class="block text-sm font-semibold text-slate-900 dark:text-white mb-2">
                Domain Name
            </label>
            <div class="flex gap-2">
                <input type="text" name="domain_name" placeholder="example" required
                    class="flex-1 px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white placeholder-slate-500 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                >
                <select name="extension" required
                    class="px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                >
                    <option value="">Select TLD</option>
                    <option value=".com">.com</option>
                    <option value=".net">.net</option>
                    <option value=".org">.org</option>
                    <option value=".io">.io</option>
                    <option value=".co">.co</option>
                    <option value=".uk">.uk</option>
                    <option value=".de">.de</option>
                    <option value=".fr">.fr</option>
                    <option value=".ca">.ca</option>
                </select>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Enter your domain without the extension (e.g., "mycompany" for mycompany.com)</p>
        </div>

        <!-- EPP Code -->
        <div>
            <label class="block text-sm font-semibold text-slate-900 dark:text-white mb-2">
                EPP Code (Authorization Code)
            </label>
            <input type="text" name="epp_code" placeholder="Your EPP code from current registrar" required
                class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white placeholder-slate-500 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Find this in your current registrar's domain settings. You may need to request it.</p>
        </div>

        <!-- Current Registrar -->
        <div>
            <label class="block text-sm font-semibold text-slate-900 dark:text-white mb-2">
                Current Registrar
            </label>
            <input type="text" name="old_registrar" placeholder="e.g., GoDaddy, Namecheap, etc." required
                class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white placeholder-slate-500 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >
        </div>

        <!-- Registrar URL (Optional) -->
        <div>
            <label class="block text-sm font-semibold text-slate-900 dark:text-white mb-2">
                Registrar Website (Optional)
            </label>
            <input type="url" name="old_registrar_url" placeholder="https://example.com"
                class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white placeholder-slate-500 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">We'll include this link to help you manage your transfer</p>
        </div>

        <!-- Checkbox -->
        <div class="flex items-start gap-3">
            <input type="checkbox" id="confirmTransfer" required
                class="w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-blue-600 focus:ring-2 focus:ring-blue-500 mt-1"
            >
            <label for="confirmTransfer" class="text-sm text-slate-600 dark:text-slate-400">
                I understand that I should authorize this transfer with my current registrar, and I have the EPP code available.
            </label>
        </div>

        <!-- Submit Button -->
        <div class="flex gap-3 pt-4">
            <button type="button" onclick="window.history.back()"
                class="flex-1 px-6 py-3 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-white font-semibold hover:bg-slate-200 dark:hover:bg-slate-700 transition"
            >
                Cancel
            </button>
            <button type="submit" id="submitBtn"
                class="flex-1 px-6 py-3 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-semibold transition"
            >
                Start Transfer
            </button>
        </div>
    </form>

    <!-- FAQ -->
    <div class="bg-slate-50 dark:bg-slate-800 rounded-xl p-6">
        <h3 class="font-semibold text-slate-900 dark:text-white mb-4">Common Questions</h3>
        <div class="space-y-4">
            <div>
                <h4 class="font-medium text-slate-900 dark:text-white mb-1">How long does a transfer take?</h4>
                <p class="text-sm text-slate-600 dark:text-slate-400">Transfers typically complete within 3-5 business days, depending on your current registrar.</p>
            </div>
            <div>
                <h4 class="font-medium text-slate-900 dark:text-white mb-1">Will my domain go offline?</h4>
                <p class="text-sm text-slate-600 dark:text-slate-400">No, your domain will remain online throughout the transfer process.</p>
            </div>
            <div>
                <h4 class="font-medium text-slate-900 dark:text-white mb-1">Do I need to renew my domain?</h4>
                <p class="text-sm text-slate-600 dark:text-slate-400">Transfers include a free 1-year renewal. Do not renew with your current registrar during the transfer.</p>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.getElementById('transferForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Processing...';

    const formData = new FormData(this);

    try {
        const response = await fetch('{{ route("customer.domains.process-transfer") }}', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value,
            },
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            // Redirect to checkout if redirect URL is provided
            if (data.redirect) {
                window.location.href = data.redirect;
            } else {
                window.location.href = '{{ route("customer.domains.index") }}?success=' + encodeURIComponent(data.message);
            }
        } else {
            alert('Error: ' + data.message);
            submitBtn.disabled = false;
            submitBtn.textContent = 'Start Transfer';
        }
    } catch (error) {
        alert('An error occurred. Please try again.');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Start Transfer';
    }
});
</script>
@endpush

@endsection
