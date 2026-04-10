@extends('layouts.customer')

@section('title', 'Profile Settings')

@section('content')
<div class="space-y-6 max-w-4xl">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Profile Settings</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Manage your account profile information, phone number, and email address.</p>
    </div>

    <div class="space-y-6">
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            @include('profile.partials.update-profile-information-form')
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            @include('profile.partials.delete-user-form')
        </div>
    </div>
</div>
@endsection
