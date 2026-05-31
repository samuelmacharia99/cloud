@extends('layouts.customer')

@section('title', 'Notification Preferences')

@section('content')
<div class="space-y-6 max-w-4xl">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Notification Preferences</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Choose which email and SMS alerts you receive.</p>
    </div>

    @if (session('status'))
        <div class="rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 px-4 py-3 text-green-800 dark:text-green-200">
            Notification preferences updated.
        </div>
    @endif

    <form method="POST" action="{{ route('profile.notifications.update') }}" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-6">
        @csrf
        @method('PATCH')

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 dark:border-slate-700 text-left">
                        <th class="py-3 pr-4 font-semibold text-slate-900 dark:text-white">Event</th>
                        <th class="py-3 px-4 font-semibold text-slate-900 dark:text-white">Email</th>
                        <th class="py-3 pl-4 font-semibold text-slate-900 dark:text-white">SMS</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    @foreach($events as $eventKey => $label)
                        @php
                            $pref = $saved[$eventKey] ?? null;
                            $emailEnabled = $pref ? $pref->email_enabled : true;
                            $smsEnabled = $pref ? $pref->sms_enabled : true;
                        @endphp
                        <tr>
                            <td class="py-3 pr-4 text-slate-700 dark:text-slate-300">{{ $label }}</td>
                            <td class="py-3 px-4">
                                <input type="hidden" name="preferences[{{ $eventKey }}][email]" value="0">
                                <input type="checkbox" name="preferences[{{ $eventKey }}][email]" value="1" @checked($emailEnabled) class="rounded">
                            </td>
                            <td class="py-3 pl-4">
                                <input type="hidden" name="preferences[{{ $eventKey }}][sms]" value="0">
                                <input type="checkbox" name="preferences[{{ $eventKey }}][sms]" value="1" @checked($smsEnabled) class="rounded">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg">Save Preferences</button>
        </div>
    </form>
</div>
@endsection
