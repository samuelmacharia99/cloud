{{-- SEO + analytics for reseller landing --}}
<title>{{ $landing['meta_title'] ?: ($branding['company_name'] ?? config('app.name')) }}</title>
@if (! empty($landing['meta_description']))
    <meta name="description" content="{{ $landing['meta_description'] }}">
@elseif (! empty($branding['tagline']))
    <meta name="description" content="{{ $branding['tagline'] }}">
@endif
<meta property="og:title" content="{{ $landing['meta_title'] ?: ($branding['company_name'] ?? config('app.name')) }}">
@if (! empty($landing['meta_description']) || ! empty($branding['tagline']))
    <meta property="og:description" content="{{ $landing['meta_description'] ?: ($branding['tagline'] ?? '') }}">
@endif
<meta property="og:type" content="website">
@if (! empty($branding['logo_url']))
    <meta property="og:image" content="{{ $branding['logo_url'] }}">
@endif
@if (! empty($branding['favicon_url']))
    <link rel="icon" href="{{ $branding['favicon_url'] }}">
@endif
@if (! empty($landing['gtm_id']))
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{{ $landing['gtm_id'] }}');</script>
@endif
@if (! empty($landing['ga_id']) && empty($landing['gtm_id']))
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $landing['ga_id'] }}"></script>
    <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{{ $landing['ga_id'] }}');</script>
@endif
