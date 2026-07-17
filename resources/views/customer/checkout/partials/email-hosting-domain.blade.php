@foreach($emailHostingItems as $item)
    @php $key = $item['key']; @endphp
    <div class="mb-6 last:mb-0 border-t border-slate-200 dark:border-slate-700 pt-6 first:border-t-0 first:pt-0">
        <p class="font-medium text-slate-900 dark:text-white mb-3">{{ $item['name'] }}</p>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Mail domain</label>
        <input type="text" name="email_domain[{{ $key }}]" value="{{ old("email_domain.$key") }}"
            list="email-domains-{{ $key }}"
            placeholder="example.com"
            required
            class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
        @if($customerDomains->isNotEmpty())
            <datalist id="email-domains-{{ $key }}">
                @foreach($customerDomains as $domain)
                    <option value="{{ $domain->fqdn() }}"></option>
                @endforeach
            </datalist>
        @endif
        @error("email_domain.$key")
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>
@endforeach
