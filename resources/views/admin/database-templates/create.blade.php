@extends('layouts.admin')

@section('content')
<div class="max-w-3xl mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold mb-6">Create Database Template</h1>

    <form method="POST" action="{{ route('admin.database-templates.store') }}" class="space-y-5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-6">
        @csrf
        @include('admin.database-templates.partials.form', ['template' => null])
        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Create</button>
            <a href="{{ route('admin.database-templates.index') }}" class="px-4 py-2 bg-slate-100 text-slate-700 rounded-lg hover:bg-slate-200">Cancel</a>
        </div>
    </form>
</div>
@endsection
