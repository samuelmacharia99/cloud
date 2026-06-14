@php
    $templateSlug = $service->product?->containerTemplate?->slug ?? '';
    $templateName = $service->product?->containerTemplate?->name ?? 'Container';
@endphp

<div class="space-y-8">
    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50/80 dark:bg-slate-900/40 p-6">
        <h3 class="text-xl font-bold text-slate-900 dark:text-white">{{ $templateName }} — deployment guide</h3>
        <p class="text-sm text-slate-600 dark:text-slate-300 mt-2 max-w-3xl">
            Step-by-step instructions for deploying your application on Talksasa Cloud.
            This guide is tailored to your selected stack — you only see what applies to {{ $templateName }}.
        </p>
    </div>

    @switch($templateSlug)
        @case('laravel')
            @include('customer.services.partials.docs.laravel')
            @break
        @case('nodejs')
            @include('customer.services.partials.docs.nodejs')
            @break
        @case('python')
            @include('customer.services.partials.docs.python')
            @break
        @case('ruby')
            @include('customer.services.partials.docs.ruby')
            @break
        @case('php')
            @include('customer.services.partials.docs.php')
            @break
        @case('wordpress')
            @include('customer.services.partials.docs.wordpress')
            @break
        @case('ghost')
            @include('customer.services.partials.docs.ghost')
            @break
        @case('strapi')
            @include('customer.services.partials.docs.strapi')
            @break
        @case('static-site')
            @include('customer.services.partials.docs.static-site')
            @break
        @default
            @include('customer.services.partials.docs.generic')
    @endswitch

    @include('customer.services.partials.docs.shared')
</div>
