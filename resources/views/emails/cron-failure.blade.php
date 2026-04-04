@extends('emails._layout')

@section('content')
<h1>Cron Job Failure Alert</h1>

<div class="alert alert-danger">
    <strong>A cron job has failed!</strong>
</div>

<h2>Job Details</h2>
<table>
    <tr>
        <td><strong>Job Name:</strong></td>
        <td>{{ $job->name }}</td>
    </tr>
    <tr>
        <td><strong>Command:</strong></td>
        <td><code>{{ $job->command }}</code></td>
    </tr>
    <tr>
        <td><strong>Schedule:</strong></td>
        <td><code>{{ $job->schedule }}</code></td>
    </tr>
    <tr>
        <td><strong>Last Attempt:</strong></td>
        <td>{{ $job->last_ran_at?->format('F d, Y H:i:s') ?? 'Never' }}</td>
    </tr>
    @if($latestLog)
        <tr>
            <td><strong>Duration:</strong></td>
            <td>{{ $latestLog->duration_formatted }}</td>
        </tr>
        <tr>
            <td><strong>Status:</strong></td>
            <td><strong style="color: #dc2626;">Failed</strong></td>
        </tr>
    @endif
</table>

@if($latestLog && $latestLog->exception)
    <h2>Error Details</h2>
    <div style="background-color: #fee2e2; border-left: 4px solid #dc2626; color: #991b1b; padding: 12px; margin: 16px 0; border-radius: 4px;">
        <p><strong>Exception Message:</strong></p>
        <p style="font-family: monospace; white-space: pre-wrap; font-size: 12px; margin: 8px 0 0 0;">{{ $latestLog->exception }}</p>
    </div>
@endif

@if($latestLog && $latestLog->output)
    <h2>Command Output</h2>
    <div style="background-color: #f3f4f6; border: 1px solid #e5e7eb; padding: 12px; border-radius: 4px; font-family: monospace; font-size: 12px; white-space: pre-wrap;">{{ $latestLog->output }}</div>
@endif

<div class="alert alert-warning">
    <strong>Action Required:</strong> Please investigate and fix this job as soon as possible. Automatic retries may have occurred, but if this continues, manual intervention is needed.
</div>

<p>
    <a href="{{ route('admin.cron.show', $job) }}" style="display: inline-block; padding: 10px 16px; background-color: #dc2626; color: white; text-decoration: none; border-radius: 6px; font-weight: bold;">
        View Job Details
    </a>
</p>

<p style="margin-top: 20px; font-size: 12px; color: #6b7280;">
    This is an automated alert from {{ \App\Models\Setting::getValue('company_name', 'Talksasa Cloud') }}.
</p>
@endsection
