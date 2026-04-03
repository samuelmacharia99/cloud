@if(auth()->user()->is_admin)
    @extends('layouts.admin')

    @section('title', 'Profile Settings')

    @section('breadcrumb')
    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Profile Settings</p>
    @endsection

    @section('content')
    <div class="space-y-6 max-w-4xl">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Profile Settings</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Manage your account profile and security.</p>
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                @include('profile.partials.update-profile-information-form')
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                @include('profile.partials.update-password-form')
            </div>
        </div>
    </div>
    @endsection
@else
    <x-app-layout>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Profile') }}
            </h2>
        </x-slot>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <div class="max-w-xl">
                        @include('profile.partials.update-profile-information-form')
                    </div>
                </div>

                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <div class="max-w-xl">
                        @include('profile.partials.update-password-form')
                    </div>
                </div>

                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <div class="max-w-xl">
                        @include('profile.partials.delete-user-form')
                    </div>
                </div>
            </div>
        </div>
    </x-app-layout>
@endif
