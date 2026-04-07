@extends('layouts.admin')

@section('title', 'Currency Settings')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Currency Settings</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Manage currencies and exchange rates</p>
        </div>
    </div>

    <!-- Alerts -->
    @if ($errors->any())
        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-lg p-4">
            <p class="text-red-700 dark:text-red-400 font-semibold mb-2">Errors:</p>
            <ul class="text-sm text-red-600 dark:text-red-300 space-y-1">
                @foreach ($errors->all() as $error)
                    <li>• {{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (session('success'))
        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 rounded-lg p-4">
            <p class="text-green-700 dark:text-green-400">{{ session('success') }}</p>
        </div>
    @endif

    <!-- Base Currency & Status -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Base Currency</p>
            <p class="text-2xl font-bold text-slate-900 dark:text-white">{{ $baseCurrency?->code ?? 'N/A' }}</p>
            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">{{ $baseCurrency?->name }}</p>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Active Currencies</p>
            <p class="text-2xl font-bold text-slate-900 dark:text-white">{{ $currencies->where('is_active', true)->count() }}</p>
            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">of {{ $currencies->count() }} total</p>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Exchange Rates</p>
            @if ($baseCurrency && $baseCurrency->rate_updated_at)
                <p class="text-sm font-semibold text-slate-900 dark:text-white">
                    {{ $baseCurrency->rate_updated_at->format('M d, Y H:i') }}
                </p>
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">
                    {{ $baseCurrency->rate_updated_at->diffForHumans() }}
                </p>
            @else
                <p class="text-sm font-semibold text-red-600">Never updated</p>
            @endif
        </div>
    </div>

    <!-- Refresh Rates & Add Currency -->
    <div class="flex flex-wrap gap-3">
        <form action="{{ route('admin.currencies.refresh') }}" method="POST" style="display:inline;">
            @csrf
            <button type="submit" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition">
                🔄 Refresh Exchange Rates
            </button>
        </form>

        <button type="button" onclick="document.getElementById('addCurrencyModal').classList.remove('hidden')" class="px-6 py-3 bg-green-600 hover:bg-green-700 text-white rounded-lg font-semibold transition">
            + Add Currency
        </button>
    </div>

    @if ($ratesStale)
        <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-lg p-4">
            <p class="text-yellow-700 dark:text-yellow-400">
                <strong>⚠️ Exchange rates are outdated</strong> (older than 24 hours). Please refresh them to ensure accurate conversions.
            </p>
        </div>
    @endif

    <!-- Currencies Table -->
    <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 overflow-hidden">
        <table class="w-full">
            <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
                <tr>
                    <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Code</th>
                    <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Name</th>
                    <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Symbol</th>
                    <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Exchange Rate</th>
                    <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Last Updated</th>
                    <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                    <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                @forelse ($currencies->sortBy('order') as $currency)
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition">
                        <td class="px-6 py-4">
                            <span class="font-mono font-semibold text-slate-900 dark:text-white">{{ $currency->code }}</span>
                            @if ($currency->code === 'KES')
                                <span class="ml-2 px-2 py-1 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 text-xs rounded">Base</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-slate-700 dark:text-slate-300">{{ $currency->name }}</td>
                        <td class="px-6 py-4 text-slate-700 dark:text-slate-300 font-semibold">{{ $currency->symbol }}</td>
                        <td class="px-6 py-4 text-slate-700 dark:text-slate-300">
                            <span class="font-mono">{{ number_format($currency->exchange_rate, 6) }}</span>
                        </td>
                        <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                            @if ($currency->rate_updated_at)
                                {{ $currency->rate_updated_at->format('M d, Y') }}
                                <br>
                                <span class="text-xs">{{ $currency->rate_updated_at->diffForHumans() }}</span>
                            @else
                                <span class="text-red-600 dark:text-red-400">Not updated</span>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            @if ($currency->is_active)
                                <span class="px-3 py-1 bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300 text-sm rounded-full">Active</span>
                            @else
                                <span class="px-3 py-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm rounded-full">Inactive</span>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            <button
                                type="button"
                                onclick="editCurrency('{{ $currency->id }}', '{{ $currency->code }}', '{{ $currency->name }}', '{{ $currency->symbol }}', {{ $currency->is_active ? 'true' : 'false' }}, {{ $currency->order }})"
                                class="text-blue-600 dark:text-blue-400 hover:underline text-sm font-semibold"
                            >
                                Edit
                            </button>
                            @if ($currency->code !== 'KES')
                                <form action="{{ route('admin.currencies.destroy', $currency) }}" method="POST" style="display:inline;" onsubmit="return confirm('Delete this currency?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="ml-3 text-red-600 dark:text-red-400 hover:underline text-sm font-semibold">Delete</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-slate-600 dark:text-slate-400">
                            No currencies found. <a href="#" onclick="document.getElementById('addCurrencyModal').classList.remove('hidden')" class="text-blue-600 hover:underline">Add one</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Test Conversion Section -->
    <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 p-6">
        <h2 class="text-xl font-bold text-slate-900 dark:text-white mb-4">Test Currency Conversion</h2>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4" x-data="currencyTester()">
            <div>
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Amount</label>
                <input type="number" x-model="amount" step="0.01" min="0.01" placeholder="1000" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white">
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">From</label>
                <select x-model="fromCurrency" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white">
                    @foreach ($currencies->where('is_active', true) as $currency)
                        <option value="{{ $currency->code }}">{{ $currency->code }} - {{ $currency->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">To</label>
                <select x-model="toCurrency" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white">
                    @foreach ($currencies->where('is_active', true) as $currency)
                        <option value="{{ $currency->code }}">{{ $currency->code }} - {{ $currency->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end">
                <button @click="testConversion()" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition">
                    Convert
                </button>
            </div>
        </div>
        <div class="mt-4" x-show="result" x-cloak>
            <div class="p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg">
                <p class="text-blue-700 dark:text-blue-300">
                    <span x-text="amount"></span>
                    <span x-text="fromCurrency"></span>
                    = <strong x-text="result"></strong>
                    <span x-text="toCurrency"></span>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Currency Modal -->
<div id="addCurrencyModal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50">
    <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 p-8 max-w-md w-full">
        <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-4">Add New Currency</h2>
        <form action="{{ route('admin.currencies.store') }}" method="POST" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Currency Code (e.g., USD)</label>
                <input type="text" name="code" maxlength="3" placeholder="USD" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" required>
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Currency Name</label>
                <input type="text" name="name" placeholder="United States Dollar" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" required>
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Symbol</label>
                <input type="text" name="symbol" placeholder="$" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" required>
            </div>
            <div class="flex gap-3 pt-4">
                <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition">Add</button>
                <button type="button" onclick="document.getElementById('addCurrencyModal').classList.add('hidden')" class="flex-1 px-4 py-2 border-2 border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-semibold hover:bg-slate-100 dark:hover:bg-slate-800 transition">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function editCurrency(id, code, name, symbol, isActive, order) {
    alert('Edit functionality coming soon for: ' + code);
}

function currencyTester() {
    return {
        amount: 1000,
        fromCurrency: 'KES',
        toCurrency: 'USD',
        result: null,

        async testConversion() {
            try {
                const response = await fetch('{{ route("admin.currencies.test-conversion") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify({
                        amount: parseFloat(this.amount),
                        from_currency: this.fromCurrency,
                        to_currency: this.toCurrency
                    })
                });

                const data = await response.json();
                if (data.success) {
                    this.result = Number(data.converted_amount).toLocaleString('en-US', { maximumFractionDigits: 2 });
                } else {
                    alert('Conversion failed: ' + data.error);
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }
    };
}
</script>
@endsection
