@props(['amount', 'currency' => null, 'showSymbol' => true, 'decimals' => null, 'convertFromKES' => false])

@php
    use App\Helpers\CurrencyHelper;
    use App\Models\Currency;
    use App\Services\UserCurrencyService;
    use Illuminate\Support\Facades\Cache;

    if ($currency === null) {
        $currency = CurrencyHelper::getSelectedCurrencyCode();
    }

    $currencyModel = Cache::remember("currency:formatter:{$currency}", 300, function () use ($currency) {
        return Currency::where('code', $currency)->first();
    });
    $symbol = $currencyModel?->symbol ?? $currency;

    $displayAmount = (float) $amount;

    if ($convertFromKES) {
        $displayAmount = CurrencyHelper::convertFromBase($displayAmount);
        $currency = CurrencyHelper::getSelectedCurrencyCode();
        $currencyModel = CurrencyHelper::getSelectedCurrency();
        $symbol = $currencyModel?->symbol ?? $currency;
    }

    if ($decimals === null) {
        $decimals = app(UserCurrencyService::class)->decimalsFor($currency);
    }

    $formatted = number_format($displayAmount, $decimals, '.', ',');
@endphp

<span {{ $attributes->merge(['class' => 'font-medium text-slate-900 dark:text-white']) }}>
    @if ($showSymbol)
        <span class="text-sm text-slate-600 dark:text-slate-400">{{ $symbol }}</span>
    @endif
    {{ $formatted }}
</span>
