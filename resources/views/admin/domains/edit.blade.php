@extends('layouts.admin')

@section('title', 'Edit ' . $domain->name)

@section('breadcrumb')
<div class="flex items-center gap-2">
    <a href="{{ route('admin.domains.index') }}" class="text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Domains</a>
    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
    </svg>
    <a href="{{ route('admin.domains.show', $domain) }}" class="text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $domain->name }}</a>
    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
    </svg>
    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Edit</p>
</div>
@endsection

@section('content')
<div class="space-y-6 max-w-2xl">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Edit {{ $domain->name }}</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Update domain settings and information.</p>
    </div>

    <form method="POST" action="{{ route('admin.domains.update', $domain) }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 space-y-6">
        @csrf
        @method('PATCH')

        <!-- Domain Name (Read-only) -->
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Domain Name</label>
            <input type="text" value="{{ $domain->name }}" disabled class="w-full px-4 py-2 bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-lg text-slate-600 dark:text-slate-400 text-sm cursor-not-allowed">
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Domain name cannot be changed</p>
        </div>

        <hr class="border-slate-200 dark:border-slate-700">

        <!-- Extension -->
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Extension</label>
            <select name="extension" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                <option value="">Select extension</option>
                @foreach ($extensions as $ext)
                    <option value="{{ $ext }}" @selected($domain->extension === $ext)>{{ $ext }}</option>
                @endforeach
            </select>
            @error('extension')
                <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Registrar -->
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Registrar</label>
            <input type="text" name="registrar" value="{{ $domain->registrar }}" placeholder="e.g., GoDaddy, Namecheap" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
            @error('registrar')
                <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Status -->
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Status</label>
            <select name="status" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                <option value="active" @selected($domain->status === 'active')>Active</option>
                <option value="expired" @selected($domain->status === 'expired')>Expired</option>
                <option value="suspended" @selected($domain->status === 'suspended')>Suspended</option>
            </select>
            @error('status')
                <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <hr class="border-slate-200 dark:border-slate-700">

        <!-- Registration Date -->
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Registration Date</label>
            <input type="date" name="registered_at" value="{{ $domain->registered_at ? $domain->registered_at->format('Y-m-d') : '' }}" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
            @error('registered_at')
                <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Expiry Date -->
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Expiry Date <span class="text-red-600">*</span></label>
            <input type="date" name="expires_at" value="{{ $domain->expires_at->format('Y-m-d') }}" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
            @error('expires_at')
                <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Auto Renew -->
        <div class="flex items-center gap-3">
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="auto_renew" @checked($domain->auto_renew) class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-2 focus:ring-blue-500">
                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Auto Renewal Enabled</span>
            </label>
            <span class="text-xs text-slate-500 dark:text-slate-400">Automatically renew before expiration</span>
        </div>

        <hr class="border-slate-200 dark:border-slate-700">

        <!-- Nameservers -->
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Nameserver 1</label>
            <input type="text" name="nameserver_1" value="{{ $domain->nameserver_1 }}" placeholder="ns1.example.com" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Nameserver 2</label>
            <input type="text" name="nameserver_2" value="{{ $domain->nameserver_2 }}" placeholder="ns2.example.com" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
        </div>

        <!-- Notes -->
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Notes</label>
            <textarea name="notes" rows="4" placeholder="Add any notes about this domain..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">{{ $domain->notes }}</textarea>
        </div>

        <!-- Form Actions -->
        <div class="flex gap-3 pt-4">
            <a href="{{ route('admin.domains.show', $domain) }}" class="flex-1 px-4 py-3 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-900 dark:text-white font-medium rounded-lg transition text-center">
                Cancel
            </a>
            <button type="submit" class="flex-1 px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                Save Changes
            </button>
        </div>
    </form>
</div>
@endsection
