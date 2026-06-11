@php
    use App\Services\UserCurrencyService;
    $currencyService = app(UserCurrencyService::class);
    $currentCode = $currencyService->codeFor(auth()->user());
    $options = $currencyService->activeOptions();
@endphp

<form method="POST" action="{{ route('currency.update') }}" class="inline-flex items-center gap-2">
    @csrf
    <label for="display-currency" class="sr-only">Display currency</label>
    <select
        id="display-currency"
        name="currency"
        onchange="this.form.submit()"
        class="text-sm border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 px-2 py-1.5"
    >
        @foreach ($options as $option)
            <option value="{{ $option['code'] }}" @selected($option['code'] === $currentCode)>
                {{ $option['code'] }} ({{ $option['symbol'] }})
            </option>
        @endforeach
    </select>
</form>
