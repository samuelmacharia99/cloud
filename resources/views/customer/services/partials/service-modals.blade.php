{{-- Rename / move dialogs shared by the service card and the stack folder.
     Expects the Alpine state showRename, showMove, showNewProject, renameName,
     newProjectName on the enclosing x-data, plus $service, $allProjects, $isWordpress. --}}
    <div x-show="showRename" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-ink-950/60 backdrop-blur-sm" @keydown.escape.window="showRename = false">
        <div class="ui-card w-full max-w-md p-6" @click.outside="showRename = false">
            <h3 class="font-display text-lg font-bold text-ink-950 dark:text-white">Rename service</h3>
            <p class="mt-1 text-sm text-ink-500 dark:text-ink-400">Personal label only — billing is unchanged.</p>
            <form method="POST" action="{{ route('customer.services.rename', $service) }}" class="mt-4 space-y-4">
                @csrf
                @method('PATCH')
                <input type="text" name="name" x-model="renameName" required minlength="2" maxlength="100" class="w-full px-4 py-2.5">
                <div class="flex gap-2">
                    <button type="button" @click="showRename = false" class="btn-secondary flex-1 btn-sm">Cancel</button>
                    <button type="submit" class="btn-primary flex-1 btn-sm">Save</button>
                </div>
            </form>
        </div>
    </div>

    @unless($isWordpress)
    <div x-show="showMove" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-ink-950/60 backdrop-blur-sm" @keydown.escape.window="showMove = false; showNewProject = false">
        <div class="ui-card w-full max-w-md p-6" @click.outside="showMove = false; showNewProject = false">
            <h3 class="font-display text-lg font-bold text-ink-950 dark:text-white">Move to project</h3>
            <p class="mt-1 text-sm text-ink-500 dark:text-ink-400">Or drag the card onto a project.</p>
            <div x-show="!showNewProject" class="mt-4 space-y-2">
                <form method="POST" action="{{ route('customer.services.project', $service) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="project_id" value="">
                    <button type="submit" class="w-full rounded-xl border border-ink-200 dark:border-ink-700 px-3.5 py-2.5 text-left text-sm font-medium text-ink-700 dark:text-ink-200 transition-colors hover:border-brand-300 hover:bg-brand-50/60 dark:hover:bg-white/5">No project</button>
                </form>
                @foreach ($allProjects as $projectOption)
                    <form method="POST" action="{{ route('customer.services.project', $service) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="project_id" value="{{ $projectOption->id }}">
                        <button type="submit" class="w-full rounded-xl border px-3.5 py-2.5 text-left text-sm font-medium transition-colors hover:bg-brand-50/60 dark:hover:bg-white/5 {{ (int) $service->project_id === (int) $projectOption->id ? 'border-brand-400 text-brand-800 dark:text-brand-200 bg-brand-50/70 dark:bg-brand-950/30' : 'border-ink-200 dark:border-ink-700 text-ink-700 dark:text-ink-200 hover:border-brand-300' }}">
                            {{ $projectOption->name }}
                        </button>
                    </form>
                @endforeach
                <button type="button" @click="showNewProject = true" class="w-full rounded-xl border border-dashed border-brand-300 dark:border-brand-800 px-3.5 py-2.5 text-left text-sm font-semibold text-brand-700 dark:text-brand-300 hover:bg-brand-50/60 dark:hover:bg-brand-950/25">+ New project…</button>
                <button type="button" @click="showMove = false" class="btn-secondary btn-sm mt-2 w-full">Cancel</button>
            </div>
            <form x-show="showNewProject" x-cloak method="POST" action="{{ route('customer.projects.store') }}" class="mt-4 space-y-4">
                @csrf
                <input type="hidden" name="service_id" value="{{ $service->id }}">
                <input type="text" name="name" x-model="newProjectName" required minlength="2" maxlength="100" placeholder="Project" class="w-full px-4 py-2.5">
                <div class="flex gap-2">
                    <button type="button" @click="showNewProject = false" class="btn-secondary flex-1 btn-sm">Back</button>
                    <button type="submit" class="btn-primary flex-1 btn-sm">Create &amp; move</button>
                </div>
            </form>
        </div>
    </div>
    @endunless
