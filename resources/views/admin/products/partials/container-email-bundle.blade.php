@php
    $emailPlans = \App\Models\Product::query()
        ->where('type', 'email_hosting')
        ->where('is_active', true)
        ->orderBy('name')
        ->get(['id', 'name', 'monthly_price', 'yearly_price']);
    $selectedEmailId = old('bundled_email_product_id', $product->bundled_email_product_id ?? null);
@endphp

<div class="border-t border-slate-200 dark:border-slate-800 pt-6" x-show="productType === 'container_hosting'" x-cloak>
    <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-2">Bundled email plan</h3>
    <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">
        Optionally include an Email Hosting plan with this Application Hosting product. The customer uses one domain for the app and mail.
        Free (<code class="text-xs">$0</code>) email plans are supported. Delay controls when the first email renewal invoice is due.
    </p>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="lg:col-span-2">
            <label for="bundled_email_product_id" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Email plan</label>
            <select id="bundled_email_product_id" name="bundled_email_product_id"
                class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm text-slate-900 dark:text-white @error('bundled_email_product_id') border-red-500 @enderror">
                <option value="">None — no email bundle</option>
                @foreach ($emailPlans as $plan)
                    <option value="{{ $plan->id }}" @selected((string) $selectedEmailId === (string) $plan->id)>
                        {{ $plan->name }}
                        ({{ number_format((float) $plan->monthly_price, 2) }}/mo
                        @if ($plan->yearly_price !== null)
                            · {{ number_format((float) $plan->yearly_price, 2) }}/yr
                        @endif)
                    </option>
                @endforeach
            </select>
            @error('bundled_email_product_id')
                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="flex items-center gap-3 cursor-pointer">
                <input type="hidden" name="bundle_email_include_in_invoice" value="0">
                <input type="checkbox" name="bundle_email_include_in_invoice" value="1" class="w-4 h-4 text-blue-600 rounded"
                    @checked(old('bundle_email_include_in_invoice', $product->bundle_email_include_in_invoice ?? false))>
                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Include email price on this invoice</span>
            </label>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400 ml-7">Off = free bundle offer (service still created). On = charge the email plan price now (may be $0).</p>
        </div>

        <div>
            <label for="bundle_email_billing_cycle" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Email billing cycle</label>
            <select id="bundle_email_billing_cycle" name="bundle_email_billing_cycle"
                class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm text-slate-900 dark:text-white">
                <option value="" @selected(old('bundle_email_billing_cycle', $product->bundle_email_billing_cycle ?? '') === '')>Follow application plan</option>
                <option value="monthly" @selected(old('bundle_email_billing_cycle', $product->bundle_email_billing_cycle ?? '') === 'monthly')>Monthly</option>
                <option value="annual" @selected(old('bundle_email_billing_cycle', $product->bundle_email_billing_cycle ?? '') === 'annual')>Annual</option>
            </select>
        </div>

        <div>
            <label for="bundle_email_billing_delay_months" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">First email bill after (months)</label>
            <input type="number" id="bundle_email_billing_delay_months" name="bundle_email_billing_delay_months" min="0" max="36" step="1"
                value="{{ old('bundle_email_billing_delay_months', $product->bundle_email_billing_delay_months ?? 0) }}"
                class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm text-slate-900 dark:text-white @error('bundle_email_billing_delay_months') border-red-500 @enderror">
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">0 = first renewal after one billing cycle from order. 1 = one free month then the cycle, etc.</p>
            @error('bundle_email_billing_delay_months')
                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>
    </div>
</div>
