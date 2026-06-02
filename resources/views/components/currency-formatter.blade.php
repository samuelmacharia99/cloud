@props(['amount', 'currency' => null, 'showSymbol' => true, 'decimals' => 2, 'convertFromKES' => false])

@php
    use App\Models\Currency;
    use App\Models\Setting;
    use Illuminate\Support\Facades\Cache;

    // Use provided currency or get the selected currency from settings
    if ($currency === null) {
        $currency = Setting::getValue('currency', 'KES');
    }

    // Cache currency lookup to avoid repeated DB queries in tables/lists.
    $currencyModel = Cache::remember("currency:formatter:{$currency}", 300, function () use ($currency) {
        return Currency::where('code', $currency)->first();
    });
    $symbol = $currencyModel?->symbol ?? $currency;

    $displayAmount = (float) $amount;

    // If convertFromKES is true, convert from KES to the target currency
    if ($convertFromKES && $currency !== 'KES' && $currencyModel) {
        $displayAmount = $displayAmount * $currencyModel->exchange_rate;
    }

    $formatted = number_format($displayAmount, $decimals, '.', ',');
@endphp

<span {{ $attributes->merge(['class' => 'font-medium text-slate-900 dark:text-white']) }}>
    @if ($showSymbol)
        <span class="text-sm text-slate-600 dark:text-slate-400">{{ $symbol }}</span>
    @endif
    {{ $formatted }}
</span>
