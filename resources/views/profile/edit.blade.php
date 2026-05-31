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
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-2">Notifications</h2>
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Manage email and SMS alerts for your account.</p>
            <a href="{{ route('profile.notifications') }}" class="inline-flex items-center px-4 py-2 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-900 dark:text-white rounded-lg text-sm font-medium">Notification preferences</a>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            @include('profile.partials.delete-user-form')
        </div>
    </div>
</div>
@endsection
