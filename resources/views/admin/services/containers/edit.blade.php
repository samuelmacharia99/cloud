@extends('layouts.app')

@section('content')
<div class="min-h-screen bg-gray-100 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-2xl mx-auto bg-white rounded-lg shadow p-6">
        <div class="mb-6">
            <h1 class="text-3xl font-bold text-gray-900">Edit Container Deployment</h1>
            <p class="text-gray-600 mt-2">
                Service: <strong>{{ $service->name }}</strong>
            </p>
        </div>

        <!-- Errors -->
        @if ($errors->any())
            <div class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4">
                <h3 class="text-red-800 font-semibold mb-2">Errors</h3>
                <ul class="list-disc list-inside text-red-700 text-sm space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.services.container.update', $service) }}" class="space-y-6">
            @csrf
            @method('PATCH')

            <!-- Current Status Info -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <p class="text-sm text-blue-800">
                    <strong>Current Status:</strong>
                    <span class="px-2 py-1 rounded text-xs font-semibold {{
                        match($deployment->status) {
                            'running' => 'bg-green-100 text-green-800',
                            'stopped' => 'bg-yellow-100 text-yellow-800',
                            'pending' => 'bg-blue-100 text-blue-800',
                            'provisioning' => 'bg-purple-100 text-purple-800',
                            'failed' => 'bg-red-100 text-red-800',
                            'terminated' => 'bg-gray-100 text-gray-800',
                            default => 'bg-gray-100 text-gray-800'
                        }
                    }}">
                        {{ ucfirst($deployment->status) }}
                    </span>
                </p>
            </div>

            <!-- Status Selection -->
            <div>
                <label for="status" class="block text-sm font-semibold text-gray-700 mb-2">
                    Deployment Status
                </label>
                <select name="status" id="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-gray-700 focus:outline-none focus:border-blue-500" required>
                    <option value="">-- Select Status --</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @if ($deployment->status === $status) selected @endif>
                            {{ ucfirst($status) }}
                        </option>
                    @endforeach
                </select>
                @error('status')
                    <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <!-- Status Info -->
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <h3 class="font-semibold text-gray-900 mb-3">Status Reference</h3>
                <ul class="space-y-2 text-sm text-gray-700">
                    <li><strong>pending:</strong> Container deployment created but not yet provisioned</li>
                    <li><strong>provisioning:</strong> Container deployment in progress</li>
                    <li><strong>running:</strong> Container is actively running</li>
                    <li><strong>stopped:</strong> Container is running but services stopped</li>
                    <li><strong>failed:</strong> Container deployment failed</li>
                    <li><strong>terminated:</strong> Container deployment has been terminated</li>
                </ul>
            </div>

            <!-- Deployment Details -->
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 space-y-2">
                <h3 class="font-semibold text-gray-900 mb-3">Deployment Details</h3>
                <p class="text-sm">
                    <strong>Container Name:</strong>
                    <span class="font-mono text-gray-700">{{ $deployment->container_name }}</span>
                </p>
                <p class="text-sm">
                    <strong>Node:</strong>
                    <span class="text-gray-700">
                        @if ($deployment->node)
                            <a href="{{ route('admin.nodes.show', $deployment->node) }}" class="text-blue-600 hover:text-blue-700">
                                {{ $deployment->node->hostname }}
                            </a>
                        @else
                            <span class="text-gray-500">Not assigned</span>
                        @endif
                    </span>
                </p>
                <p class="text-sm">
                    <strong>Port:</strong>
                    <span class="font-mono text-gray-700">{{ $deployment->assigned_port }}</span>
                </p>
                <p class="text-sm">
                    <strong>Template:</strong>
                    <span class="text-gray-700">{{ $deployment->template->name ?? 'Unknown' }}</span>
                </p>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-3">
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-semibold">
                    Update Status
                </button>
                <a href="{{ route('admin.services.show', $service) }}" class="px-6 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 font-semibold">
                    Cancel
                </a>
            </div>

            <!-- Provision Info -->
            @if ($deployment->status === 'pending')
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                    <p class="text-yellow-800 mb-3">
                        <strong>⚠️ Provision Required</strong><br>
                        This container is pending provisioning. You can:
                    </p>
                    <ul class="list-disc list-inside text-yellow-700 text-sm space-y-1 mb-3">
                        <li>Keep the status as "pending" and use the Provision button in the container panel</li>
                        <li>Change to a different status to mark it as failed or terminated</li>
                    </ul>
                </div>
            @endif
        </form>
    </div>
</div>
@endsection
