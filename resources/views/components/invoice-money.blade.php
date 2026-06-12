@props(['invoice', 'amount', 'decimals' => null])

<x-currency-formatter
    :amount="$amount"
    :currency="$invoice->displayCurrency()"
    :decimals="$decimals"
    {{ $attributes }}
/>
