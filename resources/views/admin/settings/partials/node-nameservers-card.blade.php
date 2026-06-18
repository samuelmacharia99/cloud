@php
    $directAdminNodes = $directAdminNodes ?? collect();
    $containerHostNodes = $containerHostNodes ?? collect();
    $hasInfrastructureNodes = $directAdminNodes->isNotEmpty() || $containerHostNodes->isNotEmpty();
@endphp

<div class="space-y-6">
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-200 dark:border-slate-800">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Infrastructure Nameservers</h2>
            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                Set domain nameservers per hosting node. Domain checkout and registration use the node tied to the customer’s hosting service, or the platform fallback below when no node match exists.
                <a href="{{ route('admin.nodes.index') }}" class="text-blue-600 dark:text-blue-400 hover:underline">Manage nodes</a>
            </p>
        </div>

        @if (! $hasInfrastructureNodes)
            <div class="px-6 py-10 text-center text-slate-500 dark:text-slate-400">
                <p>No DirectAdmin or container host nodes configured yet.</p>
                <div class="mt-3 flex flex-wrap justify-center gap-4 text-sm">
                    <a href="{{ route('admin.nodes.create', ['type' => 'directadmin']) }}" class="text-blue-600 dark:text-blue-400 hover:underline">Add DirectAdmin node</a>
                    <a href="{{ route('admin.nodes.create', ['type' => 'container_host']) }}" class="text-blue-600 dark:text-blue-400 hover:underline">Add container host</a>
                </div>
            </div>
        @else
            <form method="POST" action="{{ route('admin.settings.update-node-nameservers') }}" class="p-6 space-y-8" @submit.prevent="window.submitForm($el)">
                @csrf

                @if ($directAdminNodes->isNotEmpty())
                    <div class="space-y-4">
                        <div>
                            <h3 class="text-base font-semibold text-slate-900 dark:text-white">DirectAdmin nodes</h3>
                            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Used for shared hosting packages and linked domain registrations.</p>
                        </div>
                        @foreach ($directAdminNodes as $node)
                            @include('admin.settings.partials.node-nameserver-fields', ['node' => $node])
                        @endforeach
                    </div>
                @endif

                @if ($containerHostNodes->isNotEmpty())
                    <div class="space-y-4 {{ $directAdminNodes->isNotEmpty() ? 'pt-6 border-t border-slate-200 dark:border-slate-800' : '' }}">
                        <div>
                            <h3 class="text-base font-semibold text-slate-900 dark:text-white">Container host nodes</h3>
                            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                                Used when customers order domains alongside container hosting, or when a domain is linked to a container service on this node.
                                Custom domains on containers still use an A record to the node IP; these nameservers apply to registrar DNS for domain orders.
                            </p>
                        </div>
                        @foreach ($containerHostNodes as $node)
                            @include('admin.settings.partials.node-nameserver-fields', ['node' => $node])
                        @endforeach
                    </div>
                @endif

                <div class="flex justify-end items-center gap-4 pt-2">
                    <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                    <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors">
                        Save Node Nameservers
                    </button>
                </div>
            </form>
        @endif
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-200 dark:border-slate-800">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Platform Fallback Nameservers</h2>
            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                Used for standalone domain orders when no active node nameservers are configured, and as the default shown at checkout.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.settings.update') }}" class="p-6 space-y-4" @submit.prevent="window.submitForm($el)">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">NS1 <span class="text-red-500">*</span></label>
                    <input type="text" name="settings[domain_ns1]" value="{{ old('settings.domain_ns1', $settings['domain_ns1'] ?? 'ns1.talksasa.cloud') }}" required class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">NS2</label>
                    <input type="text" name="settings[domain_ns2]" value="{{ old('settings.domain_ns2', $settings['domain_ns2'] ?? 'ns2.talksasa.cloud') }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">NS3 <span class="text-slate-400 font-normal">(optional)</span></label>
                    <input type="text" name="settings[domain_ns3]" value="{{ old('settings.domain_ns3', $settings['domain_ns3'] ?? '') }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">NS4 <span class="text-slate-400 font-normal">(optional)</span></label>
                    <input type="text" name="settings[domain_ns4]" value="{{ old('settings.domain_ns4', $settings['domain_ns4'] ?? '') }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                </div>
            </div>

            <div class="flex justify-end items-center gap-4 pt-2">
                <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors">
                    Save Platform Fallback
                </button>
            </div>
        </form>
    </div>
</div>
