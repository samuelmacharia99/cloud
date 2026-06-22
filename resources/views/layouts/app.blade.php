{{--
    Legacy layout shim — forwards to the correct portal shell by role.
    Prefer @extends('layouts.customer'), layouts.admin, or layouts.reseller directly.
--}}
@extends(
    auth()->check()
        ? (auth()->user()->isAdmin()
            ? 'layouts.admin'
            : (auth()->user()->isReseller() ? 'layouts.reseller' : 'layouts.customer'))
        : 'layouts.guest'
)

@section('content')
    @yield('content')
@endsection
