@php
    $contact = $contact ?? [];
@endphp
<div>
    <label class="block text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">First name</label>
    <input type="text" name="registrant[first_name]" value="{{ old('registrant.first_name', $contact['first_name'] ?? '') }}" required class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-slate-800 border-slate-300 dark:border-slate-600 text-slate-900 dark:text-white">
    @error('registrant.first_name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
</div>
<div>
    <label class="block text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">Last name</label>
    <input type="text" name="registrant[last_name]" value="{{ old('registrant.last_name', $contact['last_name'] ?? '') }}" required class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-slate-800 border-slate-300 dark:border-slate-600 text-slate-900 dark:text-white">
    @error('registrant.last_name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
</div>
<div>
    <label class="block text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">Email</label>
    <input type="email" name="registrant[email]" value="{{ old('registrant.email', $contact['email'] ?? '') }}" required class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-slate-800 border-slate-300 dark:border-slate-600 text-slate-900 dark:text-white">
    @error('registrant.email')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
</div>
<div>
    <label class="block text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">Phone</label>
    <input type="text" name="registrant[phone]" value="{{ old('registrant.phone', $contact['phone'] ?? '') }}" required placeholder="+2547…" class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-slate-800 border-slate-300 dark:border-slate-600 text-slate-900 dark:text-white">
    @error('registrant.phone')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
</div>
<div class="md:col-span-2">
    <label class="block text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">Organization (optional)</label>
    <input type="text" name="registrant[company]" value="{{ old('registrant.company', $contact['company'] ?? '') }}" class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-slate-800 border-slate-300 dark:border-slate-600 text-slate-900 dark:text-white">
</div>
<div class="md:col-span-2">
    <label class="block text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">Address</label>
    <input type="text" name="registrant[address1]" value="{{ old('registrant.address1', $contact['address1'] ?? '') }}" required class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-slate-800 border-slate-300 dark:border-slate-600 text-slate-900 dark:text-white">
    @error('registrant.address1')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
</div>
<div>
    <label class="block text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">City</label>
    <input type="text" name="registrant[city]" value="{{ old('registrant.city', $contact['city'] ?? '') }}" required class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-slate-800 border-slate-300 dark:border-slate-600 text-slate-900 dark:text-white">
    @error('registrant.city')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
</div>
<div>
    <label class="block text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">State / region</label>
    <input type="text" name="registrant[state]" value="{{ old('registrant.state', $contact['state'] ?? '') }}" class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-slate-800 border-slate-300 dark:border-slate-600 text-slate-900 dark:text-white">
</div>
<div>
    <label class="block text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">Postal code</label>
    <input type="text" name="registrant[postal_code]" value="{{ old('registrant.postal_code', $contact['postal_code'] ?? '') }}" class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-slate-800 border-slate-300 dark:border-slate-600 text-slate-900 dark:text-white">
</div>
<div>
    <x-country-select name="registrant[country]" :value="old('registrant.country', $contact['country'] ?? 'KE')" :required="true" label="Country" />
    @error('registrant.country')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
</div>
