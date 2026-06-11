@props([
    'label' => 'Attachments',
    'help' => 'Images or documents (PDF, Word, TXT). Up to 5 files, 10 MB each.',
])

<div>
    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
        {{ $label }}
        <span class="font-normal text-slate-500 dark:text-slate-400">(optional)</span>
    </label>
    <input
        type="file"
        name="attachments[]"
        multiple
        accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.txt,image/*,application/pdf"
        {{ $attributes->merge(['class' => 'block w-full text-sm text-slate-600 dark:text-slate-300 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200 dark:file:bg-slate-800 dark:file:text-slate-200']) }}
    >
    @if ($help)
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $help }}</p>
    @endif
    @error('attachments')
        <p class="text-red-600 dark:text-red-400 text-sm mt-2">{{ $message }}</p>
    @enderror
    @error('attachments.*')
        <p class="text-red-600 dark:text-red-400 text-sm mt-2">{{ $message }}</p>
    @enderror
</div>
