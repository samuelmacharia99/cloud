<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
    <div class="px-6 py-5 border-b border-slate-200 dark:border-slate-800">
        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">DirectAdmin Nameservers</h2>
        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
            Set domain nameservers per DirectAdmin node. Checkout uses the node tied to the hosting package; standalone domain orders use the first active node below.
            <a href="{{ route('admin.nodes.index') }}" class="text-blue-600 dark:text-blue-400 hover:underline">Manage nodes</a>
        </p>
    </div>

    @if($directAdminNodes->isEmpty())
        <div class="px-6 py-10 text-center text-slate-500 dark:text-slate-400">
            <p>No DirectAdmin nodes configured yet.</p>
            <a href="{{ route('admin.nodes.create', ['type' => 'directadmin']) }}" class="inline-block mt-3 text-sm text-blue-600 dark:text-blue-400 hover:underline">Add a DirectAdmin node</a>
        </div>
    @else
        <form method="POST" action="{{ route('admin.settings.update-node-nameservers') }}" class="p-6 space-y-6" @submit.prevent="window.submitForm($el)">
            @csrf

            @foreach($directAdminNodes as $node)
                <div class="border border-slate-200 dark:border-slate-700 rounded-lg p-5 space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 class="font-medium text-slate-900 dark:text-white">{{ $node->name }}</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ $node->hostname }} · {{ $node->ip_address }}</p>
                        </div>
                        <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium {{ $node->is_active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' }}">
                            {{ $node->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">NS1 <span class="text-red-500">*</span></label>
                            <input type="text" name="nodes[{{ $node->id }}][nameserver_1]" value="{{ old('nodes.'.$node->id.'.nameserver_1', $node->nameserver_1) }}" required class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">NS2</label>
                            <input type="text" name="nodes[{{ $node->id }}][nameserver_2]" value="{{ old('nodes.'.$node->id.'.nameserver_2', $node->nameserver_2) }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">NS3 <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="text" name="nodes[{{ $node->id }}][nameserver_3]" value="{{ old('nodes.'.$node->id.'.nameserver_3', $node->nameserver_3) }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">NS4 <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="text" name="nodes[{{ $node->id }}][nameserver_4]" value="{{ old('nodes.'.$node->id.'.nameserver_4', $node->nameserver_4) }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                        </div>
                    </div>
                </div>
            @endforeach

            <div class="flex justify-end pt-2">
                <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors">
                    Save Nameservers
                </button>
            </div>
        </form>
    @endif
</div>
