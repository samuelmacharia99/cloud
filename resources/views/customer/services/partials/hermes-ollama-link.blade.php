@if (!empty($hermesOllamaLinkPanel))
    <div class="mt-6 pt-6 border-t border-amber-200 dark:border-amber-800">
        <h4 class="text-sm font-semibold text-slate-900 dark:text-white">Ollama in this project</h4>
        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
            Hermes and Ollama are separate containers. Connect them so Hermes uses the model running in Ollama
            over the private Docker network (same host) instead of a cloud API key.
        </p>

        @if (!empty($hermesOllamaLinkPanel['connected']))
            <div class="mt-3 rounded-lg border border-emerald-200 dark:border-emerald-800 bg-white/70 dark:bg-slate-900/40 px-4 py-3 text-sm">
                <p class="font-medium text-emerald-800 dark:text-emerald-200">Connected</p>
                <p class="mt-1 text-slate-700 dark:text-slate-300">
                    @if (!empty($hermesOllamaLinkPanel['connected']['service_name']))
                        Using <strong>{{ $hermesOllamaLinkPanel['connected']['service_name'] }}</strong>
                    @else
                        Using project Ollama
                    @endif
                    @if (!empty($hermesOllamaLinkPanel['connected']['model']))
                        (<code class="font-mono text-xs">{{ $hermesOllamaLinkPanel['connected']['model'] }}</code>)
                    @endif
                    via {{ $hermesOllamaLinkPanel['connected']['via'] }}.
                </p>
                @if (!empty($hermesOllamaLinkPanel['connected']['base_url']))
                    <p class="mt-1 font-mono text-xs break-all text-slate-600 dark:text-slate-400">{{ $hermesOllamaLinkPanel['connected']['base_url'] }}</p>
                @endif
            </div>
        @endif

        @if (!empty($hermesOllamaLinkPanel['candidates']))
            @php
                $runnableOllama = collect($hermesOllamaLinkPanel['candidates'])->firstWhere('running', true);
            @endphp
            @if (empty($hermesOllamaLinkPanel['container_running']))
                <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">Start Hermes before connecting Ollama.</p>
            @elseif (empty($runnableOllama))
                <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">Start Ollama before connecting it to Hermes.</p>
            @else
                <form
                    method="POST"
                    action="{{ route('customer.services.container.hermes.ollama.connect', $service) }}"
                    class="mt-4 flex flex-col sm:flex-row sm:items-end gap-3"
                    data-confirm="Hermes will use this Ollama API. The Hermes stack restarts once (brief downtime)."
                    data-confirm-title="Connect Ollama"
                >
                    @csrf
                    <div class="flex-1 min-w-0">
                        <label for="hermes-ollama-service" class="block text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Ollama service</label>
                        <select
                            id="hermes-ollama-service"
                            name="ollama_service_id"
                            required
                            class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-900 dark:text-white text-sm"
                        >
                            @foreach ($hermesOllamaLinkPanel['candidates'] as $candidate)
                                <option
                                    value="{{ $candidate['id'] }}"
                                    @selected(($hermesOllamaLinkPanel['connected']['service_id'] ?? null) === $candidate['id'])
                                    @disabled(empty($candidate['running']))
                                >
                                    {{ $candidate['name'] }}{{ empty($candidate['running']) ? ' (stopped)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button
                        type="submit"
                        class="shrink-0 px-4 py-2.5 bg-slate-900 hover:bg-slate-800 dark:bg-amber-600 dark:hover:bg-amber-700 text-white rounded-lg font-medium transition"
                    >
                        {{ !empty($hermesOllamaLinkPanel['connected']) ? 'Reconnect' : 'Connect Ollama' }}
                    </button>
                </form>
            @endif
        @elseif (!empty($hermesOllamaLinkPanel['empty_reason']))
            <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">{{ $hermesOllamaLinkPanel['empty_reason'] }}</p>
        @endif
    </div>
@endif
