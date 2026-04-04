@extends('emails._layout')

@section('content')
<h1>Cron System Health Alert</h1>

<div class="alert alert-danger">
    <strong>{{ count($issues) }} issue(s) detected in cron job system.</strong>
</div>

@foreach($issues as $issue)
    <div style="margin-bottom: 20px; padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px;">
        @if($issue['type'] === 'hung')
            <h3 style="color: #ea580c; margin-top: 0;">🔴 Hung Job Detected</h3>
            <p><strong>Job:</strong> {{ $issue['job']->name }}</p>
            <p><strong>Command:</strong> <code>{{ $issue['job']->command }}</code></p>
            <p><strong>Running For:</strong> {{ $issue['duration'] }} seconds (max allowed: {{ \App\Models\Setting::getValue('max_execution_time', '120') }}s)</p>
            <p>The job appears to be stuck. You may need to manually kill the process or investigate why it's not completing.</p>
        @elseif($issue['type'] === 'consecutive_failures')
            <h3 style="color: #dc2626; margin-top: 0;">🔴 Repeated Failures</h3>
            <p><strong>Job:</strong> {{ $issue['job']->name }}</p>
            <p><strong>Command:</strong> <code>{{ $issue['job']->command }}</code></p>
            <p><strong>Failures in Last Hour:</strong> {{ $issue['count'] }} times</p>
            <p>This job has failed multiple times consecutively. Please investigate the root cause and fix it.</p>
        @endif
    </div>
@endforeach

<h2>Recommended Actions</h2>
<ol>
    <li>Review the cron job logs in the admin panel at: <a href="{{ route('admin.cron.index') }}">Cron Dashboard</a></li>
    <li>Check application logs for detailed error messages</li>
    <li>Verify database connectivity and server resources (disk space, memory)</li>
    <li>If a job is hung, consider killing the process and investigating why it's not completing</li>
    <li>Verify SMTP and external API connectivity if those are involved</li>
</ol>

<div class="alert alert-info">
    <strong>Production Impact:</strong> If these issues persist, billing notifications and service provisioning may be delayed.
</div>

<p>
    Check the logs and fix these issues as soon as possible.
</p>

<p style="margin-top: 20px; font-size: 12px; color: #6b7280;">
    This is an automated alert from {{ \App\Models\Setting::getValue('company_name', 'Talksasa Cloud') }}.
</p>
@endsection
