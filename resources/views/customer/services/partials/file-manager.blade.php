@php($containerUploadMaxMb = (int) config('security.container_file_upload.max_size_mb', 100))
@php($editorMaxKb = (int) round(((int) config('containers.file_editor.max_bytes', 524288)) / 1024))
@php($maxExtractMb = (int) config('containers.file_manager.max_extract_mb', 2048))
@php($maxArchiveMb = (int) config('containers.file_manager.max_archive_download_mb', 500))
@php($filesTabActive = request('tab') === 'files')

<div class="bg-white dark:bg-slate-800 rounded-lg shadow mb-8">
    <div x-data="fileManager()" @keydown.escape.window="if (!editorOpen && !picker.open) clearSelection()" class="border border-gray-200 dark:border-slate-700 rounded-lg">
        <div class="border-b border-gray-200 dark:border-slate-700 px-6 py-4 flex items-center justify-between">
            <button @click="open = !open" class="flex items-center gap-2 font-medium text-gray-700 dark:text-slate-200 hover:text-gray-900 dark:hover:text-white">
                <span x-text="open ? '▼' : '▶'" class="text-sm"></span>
                <span>📁 File Manager</span>
            </button>
        </div>

    <template x-if="open">
        <div class="p-6 space-y-4">
            <div class="flex items-center gap-2 text-sm flex-wrap">
                <template x-for="(crumb, idx) in breadcrumbs" :key="idx">
                    <div class="flex items-center gap-2">
                        <a @click.prevent="navigate(crumb.path)" href="#" class="text-blue-600 dark:text-blue-400 hover:underline">
                            <span x-text="crumb.label"></span>
                        </a>
                        <span x-show="idx < breadcrumbs.length - 1" class="text-gray-400">/</span>
                    </div>
                </template>
            </div>

            <div class="flex items-center gap-2 flex-wrap">
                <button @click="newFile()" class="px-3 py-2 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-300 rounded hover:bg-indigo-100 dark:hover:bg-indigo-900/50 text-sm font-medium">
                    📄 New File
                </button>
                <button @click="newFolder()" class="px-3 py-2 bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-300 rounded hover:bg-blue-100 dark:hover:bg-blue-900/50 text-sm font-medium">
                    ➕ New Folder
                </button>
                <button @click="$refs.fileInput.click()" :disabled="uploading" class="px-3 py-2 bg-green-50 dark:bg-green-900/30 text-green-600 dark:text-green-300 rounded hover:bg-green-100 dark:hover:bg-green-900/50 text-sm font-medium disabled:opacity-50">
                    ⬆️ Upload
                </button>
                <label class="inline-flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300 cursor-pointer select-none" title="Zip, tar, tar.gz and tgz files are unpacked into this folder after upload">
                    <input type="checkbox" x-model="extractAfterUpload" class="rounded">
                    Extract archives after upload
                </label>
                <button @click="loadDirectory()" :disabled="loading" class="px-3 py-2 bg-slate-50 dark:bg-slate-700/50 text-slate-600 dark:text-slate-300 rounded hover:bg-slate-100 dark:hover:bg-slate-700 text-sm font-medium disabled:opacity-50">
                    ↻ Refresh
                </button>
                <input type="file" x-ref="fileInput" @change="handleFileSelect" class="hidden" multiple>
                <span class="text-xs text-slate-500 dark:text-slate-400">Max {{ $containerUploadMaxMb }} MB per file · {{ $editorMaxKb }} KB editor limit</span>
            </div>

            <div x-show="selected.length > 0" x-cloak class="flex items-center gap-2 flex-wrap p-2 rounded-lg bg-slate-50 dark:bg-slate-900/40 border border-slate-200 dark:border-slate-700">
                <span class="text-sm text-slate-700 dark:text-slate-200"><span class="font-semibold" x-text="selected.length"></span> selected</span>
                <button @click="deleteSelected()" :disabled="busy" class="px-3 py-1.5 bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-300 rounded hover:bg-red-100 dark:hover:bg-red-900/50 text-sm font-medium disabled:opacity-50">🗑️ Delete</button>
                <button @click="openPicker('move')" :disabled="busy" class="px-3 py-1.5 bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 rounded hover:bg-amber-100 dark:hover:bg-amber-900/50 text-sm font-medium disabled:opacity-50">➡️ Move</button>
                <button @click="openPicker('copy')" :disabled="busy" class="px-3 py-1.5 bg-sky-50 dark:bg-sky-900/30 text-sky-700 dark:text-sky-300 rounded hover:bg-sky-100 dark:hover:bg-sky-900/50 text-sm font-medium disabled:opacity-50">📋 Copy</button>
                <button @click="downloadSelected()" :disabled="busy || operation.active" class="px-3 py-1.5 bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 rounded hover:bg-emerald-100 dark:hover:bg-emerald-900/50 text-sm font-medium disabled:opacity-50">🗜️ Download zip</button>
                <button @click="clearSelection()" class="px-3 py-1.5 text-slate-500 dark:text-slate-400 hover:underline text-sm">Clear</button>
                <span class="text-xs text-slate-500 dark:text-slate-400 ml-auto">Shift-click selects a range · Esc clears</span>
            </div>

            <template x-if="error">
                <div class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 rounded text-sm flex items-start justify-between gap-3">
                    <span class="break-words" x-text="error"></span>
                    <button @click="error = null" class="text-red-600 dark:text-red-400">✕</button>
                </div>
            </template>
            <template x-if="notice">
                <div class="p-3 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300 rounded text-sm flex items-start justify-between gap-3">
                    <span class="break-words" x-text="notice"></span>
                    <button @click="notice = null" class="text-emerald-700 dark:text-emerald-400">✕</button>
                </div>
            </template>

            <template x-if="uploading || uploads.length > 0">
                <div class="p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-blue-700 dark:text-blue-300" x-text="uploading ? `Uploading ${uploadCount} file(s)...` : 'Upload finished'"></span>
                        <div class="flex items-center gap-3">
                            <span x-show="uploading" class="text-sm text-blue-700 dark:text-blue-300" x-text="`${uploadProgress}%`"></span>
                            <button x-show="!uploading" @click="uploads = []" class="text-xs text-blue-700 dark:text-blue-300 hover:underline">Dismiss</button>
                        </div>
                    </div>
                    <div x-show="uploading" class="w-full bg-blue-200 dark:bg-blue-900 rounded-full h-2">
                        <div class="bg-blue-600 h-2 rounded-full transition-all" :style="`width: ${uploadProgress}%`"></div>
                    </div>
                    <ul class="text-xs space-y-0.5">
                        <template x-for="row in uploads" :key="row.name">
                            <li class="flex items-center justify-between gap-2">
                                <span class="font-mono truncate" x-text="row.name"></span>
                                <span :class="row.status === 'failed' ? 'text-red-600 dark:text-red-300' : 'text-slate-600 dark:text-slate-300'" x-text="row.status === 'failed' ? row.error : (row.status === 'extracting' ? 'uploaded · extracting' : row.status)"></span>
                            </li>
                        </template>
                    </ul>
                </div>
            </template>

            <template x-if="operation.token">
                <div class="p-3 rounded border space-y-2"
                     :class="operation.status === 'failed' ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800' : (operation.status === 'completed' ? 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800' : 'bg-indigo-50 dark:bg-indigo-900/20 border-indigo-200 dark:border-indigo-800')">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-slate-800 dark:text-slate-100">
                            <span class="font-medium" x-text="operation.type === 'archive' ? 'Preparing download' : 'Extracting archive'"></span>
                            <span class="text-slate-600 dark:text-slate-300" x-text="' · ' + operation.label"></span>
                        </span>
                        <div class="flex items-center gap-3">
                            <span x-show="operation.active" class="text-sm tabular-nums text-slate-700 dark:text-slate-200" x-text="`${operation.percent}%`"></span>
                            <a x-show="operation.status === 'completed' && operation.type === 'archive' && operation.downloadUrl" :href="operation.downloadUrl" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded" @click="setTimeout(() => clearOperation(), 1500)">Download</a>
                            <button x-show="!operation.active" @click="clearOperation()" class="text-xs text-slate-600 dark:text-slate-300 hover:underline">Dismiss</button>
                        </div>
                    </div>
                    <div x-show="operation.active" class="w-full bg-indigo-200 dark:bg-indigo-900 rounded-full h-2">
                        <div class="bg-indigo-600 h-2 rounded-full transition-all" :style="`width: ${operation.percent}%`"></div>
                    </div>
                </div>
            </template>

            <template x-if="!loading && entries.length > 0">
                <div class="border border-gray-200 dark:border-slate-700 rounded overflow-hidden">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-900/40 text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            <tr>
                                <th class="px-4 py-2 w-6 text-left">
                                    <input type="checkbox" :checked="allSelected()" x-effect="$el.indeterminate = selected.length > 0 && !allSelected()" @change="toggleAll($event.target.checked)" class="rounded" title="Select all">
                                </th>
                                <th class="px-4 py-2 w-6"></th>
                                <th class="px-4 py-2 text-left font-medium">Name</th>
                                <th class="px-4 py-2 text-right font-medium">Size</th>
                                <th class="px-4 py-2 text-right font-medium">Modified</th>
                                <th class="px-4 py-2 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(entry, index) in entries" :key="entry.name">
                                <tr class="border-t border-gray-200 dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700/40" :class="isSelected(entry.name) ? 'bg-blue-50/60 dark:bg-blue-900/20' : ''">
                                    <td class="px-4 py-3 w-6">
                                        <input type="checkbox" @click="toggleSelect(entry.name, index, $event)" :checked="isSelected(entry.name)" class="rounded">
                                    </td>
                                    <td class="px-4 py-3 w-6">
                                        <span x-text="entry.type === 'dir' ? '📁' : (entry.archive ? '🗜️' : '📄')"></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <button
                                            @click="entry.type === 'dir' ? navigate(joinPath(currentPath, entry.name)) : (entry.editable ? openEditor(entry.name) : (entry.viewable ? openViewer(entry.name) : downloadFile(entry.name)))"
                                            :class="entry.type === 'dir' ? 'text-blue-600 dark:text-blue-400 hover:underline cursor-pointer' : 'text-gray-700 dark:text-slate-200 hover:underline cursor-pointer'"
                                            class="break-all text-left"
                                        >
                                            <span x-text="entry.name"></span>
                                        </button>
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-500 dark:text-slate-400">
                                        <span x-show="entry.type !== 'dir'" x-text="formatBytes(entry.size)"></span>
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-500 dark:text-slate-400 text-xs">
                                        <span x-text="formatDate(entry.modified)"></span>
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap space-x-2">
                                        <button x-show="entry.archive" @click="extractEntry(entry.name)" :disabled="operation.active" class="text-emerald-700 dark:text-emerald-300 hover:underline text-sm font-medium disabled:opacity-50">Extract</button>
                                        <button @click="renameEntry(entry.name)" class="text-slate-600 dark:text-slate-400 hover:underline text-sm">Rename</button>
                                        <button x-show="entry.type !== 'dir' && entry.viewable" @click="openViewer(entry.name)" class="text-slate-600 dark:text-slate-400 hover:underline text-sm">View</button>
                                        <button x-show="entry.type !== 'dir' && entry.editable" @click="openEditor(entry.name)" class="text-blue-600 dark:text-blue-400 hover:underline text-sm">Edit</button>
                                        <button x-show="entry.type !== 'dir'" @click="downloadFile(entry.name)" class="text-slate-600 dark:text-slate-400 hover:underline text-sm">Download</button>
                                        <button @click="deleteFile(entry.name)" class="text-red-600 dark:text-red-400 hover:underline text-sm">Delete</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>

            <template x-if="!loading && entries.length === 0">
                <div class="text-center py-12 text-gray-500 dark:text-slate-400">
                    <div class="text-4xl mb-2">📂</div>
                    <div>Empty directory</div>
                </div>
            </template>

            <template x-if="loading">
                <div class="text-center py-12">
                    <div class="inline-flex items-center gap-2">
                        <div class="w-4 h-4 bg-blue-500 rounded-full animate-bounce"></div>
                        <span class="text-gray-600 dark:text-slate-400">Loading directory...</span>
                    </div>
                </div>
            </template>

            <p class="text-[11px] text-slate-500 dark:text-slate-400">
                Archives extract up to {{ $maxExtractMb }} MB uncompressed; zip downloads pack up to {{ $maxArchiveMb }} MB. Entries that would write outside the folder or contain links are refused.
            </p>

            {{-- Editor / viewer --}}
            <div x-show="editorOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @keydown.escape.window="if (editorOpen) closeEditor()">
                <div class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl w-full max-w-5xl max-h-[90vh] flex flex-col border border-slate-200 dark:border-slate-700">
                    <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400" x-text="editorReadOnly ? 'Viewing' : 'Editing'"></p>
                            <p class="font-mono text-sm text-slate-900 dark:text-white truncate" x-text="editorPath"></p>
                        </div>
                        <button @click="closeEditor()" class="text-slate-500 hover:text-slate-800 dark:hover:text-white text-xl leading-none">✕</button>
                    </div>

                    <div class="flex-1 overflow-hidden p-4">
                        <template x-if="editorLoading">
                            <div class="text-center py-16 text-slate-500 dark:text-slate-400">Loading file...</div>
                        </template>
                        <textarea
                            x-show="!editorLoading"
                            x-model="editorContent"
                            :readonly="editorReadOnly"
                            class="w-full h-[55vh] font-mono text-sm leading-6 p-4 rounded-lg border border-slate-300 dark:border-slate-600 bg-slate-950 text-slate-100 resize-y focus:outline-none focus:ring-2 focus:ring-blue-500"
                            :class="editorReadOnly ? 'cursor-default opacity-90' : ''"
                            spellcheck="false"
                        ></textarea>
                    </div>

                    <div class="px-4 py-3 border-t border-slate-200 dark:border-slate-700 flex items-center justify-between gap-3">
                        <p class="text-xs text-slate-500 dark:text-slate-400" x-text="editorReadOnly ? 'Read-only preview' : (editorDirty ? 'Unsaved changes' : 'Saved')"></p>
                        <div class="flex items-center gap-2">
                            <button @click="closeEditor()" type="button" class="px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-sm" x-text="editorReadOnly ? 'Close' : 'Cancel'"></button>
                            <button x-show="!editorReadOnly" @click="saveEditor()" :disabled="editorSaving || editorLoading" type="button" class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-sm font-medium">
                                <span x-text="editorSaving ? 'Saving...' : 'Save'"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Folder picker for move / copy --}}
            <div x-show="picker.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @keydown.escape.window="if (picker.open) closePicker()">
                <div class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col border border-slate-200 dark:border-slate-700">
                    <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400" x-text="picker.mode === 'copy' ? 'Copy to' : 'Move to'"></p>
                            <p class="text-sm text-slate-900 dark:text-white truncate"><span x-text="selected.length"></span> item(s) → <span class="font-mono" x-text="picker.path"></span></p>
                        </div>
                        <button @click="closePicker()" class="text-slate-500 hover:text-slate-800 dark:hover:text-white text-xl leading-none">✕</button>
                    </div>
                    <div class="px-4 py-2 border-b border-slate-200 dark:border-slate-700 flex items-center gap-2 text-sm flex-wrap">
                        <template x-for="(crumb, idx) in picker.breadcrumbs" :key="'p' + idx">
                            <div class="flex items-center gap-2">
                                <a @click.prevent="pickerNavigate(crumb.path)" href="#" class="text-blue-600 dark:text-blue-400 hover:underline" x-text="crumb.label"></a>
                                <span x-show="idx < picker.breadcrumbs.length - 1" class="text-gray-400">/</span>
                            </div>
                        </template>
                        <button @click="pickerNewFolder()" class="ml-auto text-xs text-blue-600 dark:text-blue-400 hover:underline">➕ New folder here</button>
                    </div>
                    <div class="flex-1 overflow-auto min-h-[12rem]">
                        <template x-if="picker.loading">
                            <div class="text-center py-10 text-slate-500 dark:text-slate-400">Loading...</div>
                        </template>
                        <template x-if="!picker.loading && picker.dirs.length === 0">
                            <div class="text-center py-10 text-slate-500 dark:text-slate-400">No sub-folders here</div>
                        </template>
                        <ul x-show="!picker.loading" class="divide-y divide-slate-200 dark:divide-slate-700">
                            <template x-for="dir in picker.dirs" :key="dir.name">
                                <li>
                                    <button @click="pickerNavigate(joinPath(picker.path, dir.name))" class="w-full text-left px-4 py-2 text-sm hover:bg-slate-50 dark:hover:bg-slate-800 flex items-center gap-2">
                                        <span>📁</span><span class="break-all" x-text="dir.name"></span>
                                        <span x-show="isSelected(dir.name) && picker.path === currentPath" class="ml-auto text-xs text-amber-600">selected item</span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>
                    <div class="px-4 py-3 border-t border-slate-200 dark:border-slate-700 flex items-center justify-between gap-3">
                        <p class="text-xs text-slate-500 dark:text-slate-400" x-text="picker.path === currentPath ? 'Choose a different folder' : 'Existing names at the destination are skipped'"></p>
                        <div class="flex items-center gap-2">
                            <button @click="closePicker()" type="button" class="px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-sm">Cancel</button>
                            <button @click="confirmPicker()" :disabled="busy || picker.loading || picker.path === currentPath" type="button" class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-sm font-medium">
                                <span x-text="busy ? 'Working...' : (picker.mode === 'copy' ? 'Copy here' : 'Move here')"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
    </div>
</div>

@push('scripts')
<script>
function fileManager() {
    const csrf = () => document.head.querySelector('meta[name="csrf-token"]').content;
    const jsonHeaders = () => ({ 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() });
    const operationUrl = (token) => `{{ container_route('files.operation', $service->id, '00000000-0000-0000-0000-000000000000') }}`.replace('00000000-0000-0000-0000-000000000000', token);
    const archiveDownloadUrl = (token) => `{{ container_route('files.archive-download', $service->id, '00000000-0000-0000-0000-000000000000') }}`.replace('00000000-0000-0000-0000-000000000000', token);

    return {
        maxUploadBytes: {{ $containerUploadMaxMb }} * 1024 * 1024,
        maxUploadMb: {{ $containerUploadMaxMb }},
        open: @json($filesTabActive),
        loading: false,
        busy: false,
        uploading: false,
        uploadProgress: 0,
        uploadCount: 0,
        uploads: [],
        extractAfterUpload: false,
        currentPath: '/',
        entries: [],
        breadcrumbs: [],
        error: null,
        notice: null,
        selected: [],
        lastClickedIndex: null,
        operation: { token: null, type: null, status: null, percent: 0, label: '', active: false, downloadUrl: null, timer: null },
        picker: { open: false, mode: 'move', path: '/', dirs: [], breadcrumbs: [], loading: false },
        editorOpen: false,
        editorLoading: false,
        editorSaving: false,
        editorPath: '',
        editorContent: '',
        editorOriginal: '',
        editorDirty: false,
        editorReadOnly: false,

        init() {
            this.loadDirectory();
            this.$watch('editorContent', (value) => {
                this.editorDirty = value !== this.editorOriginal;
            });
        },

        async loadDirectory() {
            this.loading = true;
            this.error = null;

            try {
                const response = await fetch(`{{ container_route('files.index', $service->id) }}?path=${encodeURIComponent(this.currentPath)}`, {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() }
                });

                if (!response.ok) {
                    const data = await response.json();
                    throw new Error(data.error || 'Failed to load directory');
                }

                const data = await response.json();
                this.entries = data.entries;
                this.breadcrumbs = data.breadcrumbs;
                this.currentPath = data.path;
                this.selected = [];
                this.lastClickedIndex = null;
            } catch (err) {
                this.error = err.message;
            } finally {
                this.loading = false;
            }
        },

        navigate(path) {
            this.currentPath = path;
            this.loadDirectory();
        },

        joinPath(base, name) {
            if (!base || base === '/') return `/${name}`;
            return `${base.replace(/\/+$/, '')}/${name}`;
        },

        selectedPaths() {
            return this.selected.map((name) => this.joinPath(this.currentPath, name));
        },

        isSelected(name) {
            return this.selected.includes(name);
        },

        toggleSelect(name, index, event) {
            if (event && event.shiftKey && this.lastClickedIndex !== null) {
                const [from, to] = [Math.min(this.lastClickedIndex, index), Math.max(this.lastClickedIndex, index)];
                const range = this.entries.slice(from, to + 1).map((entry) => entry.name);
                const union = new Set([...this.selected, ...range]);
                this.selected = this.entries.map((entry) => entry.name).filter((n) => union.has(n));
            } else if (this.isSelected(name)) {
                this.selected = this.selected.filter((n) => n !== name);
            } else {
                this.selected = [...this.selected, name];
            }
            this.lastClickedIndex = index;
        },

        allSelected() {
            return this.entries.length > 0 && this.entries.every((entry) => this.isSelected(entry.name));
        },

        toggleAll(checked) {
            this.selected = checked ? this.entries.map((entry) => entry.name) : [];
        },

        clearSelection() {
            this.selected = [];
            this.lastClickedIndex = null;
        },

        async openEditor(name) {
            await this.openFile(name, false);
        },

        async openViewer(name) {
            await this.openFile(name, true);
        },

        async openFile(name, readOnly) {
            const path = this.joinPath(this.currentPath, name);
            this.editorOpen = true;
            this.editorLoading = true;
            this.editorPath = path;
            this.editorContent = '';
            this.editorOriginal = '';
            this.editorDirty = false;
            this.editorReadOnly = readOnly;
            this.error = null;

            try {
                const response = await fetch(`{{ container_route('files.content', $service->id) }}?path=${encodeURIComponent(path)}`, {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
                });

                const data = await response.json();
                if (!response.ok) {
                    throw new Error(data.error || 'Failed to open file');
                }

                this.editorReadOnly = readOnly || !data.editable;
                this.editorContent = data.content || '';
                this.editorOriginal = this.editorContent;
            } catch (err) {
                this.error = err.message;
                this.editorOpen = false;
            } finally {
                this.editorLoading = false;
            }
        },

        async saveEditor() {
            if (!this.editorPath || this.editorSaving) return;

            this.editorSaving = true;
            this.error = null;

            try {
                const response = await fetch(`{{ container_route('files.save', $service->id) }}`, {
                    method: 'PUT',
                    headers: jsonHeaders(),
                    body: JSON.stringify({ path: this.editorPath, content: this.editorContent }),
                });

                const data = await response.json();
                if (!response.ok) {
                    throw new Error(data.error || 'Failed to save file');
                }

                this.editorOriginal = this.editorContent;
                this.editorDirty = false;
                this.loadDirectory();
            } catch (err) {
                this.error = err.message;
            } finally {
                this.editorSaving = false;
            }
        },

        async closeEditor() {
            if (!this.editorReadOnly && this.editorDirty && !await window.appConfirm('Discard unsaved changes?', 'Unsaved changes')) {
                return;
            }

            this.editorOpen = false;
            this.editorPath = '';
            this.editorContent = '';
            this.editorOriginal = '';
            this.editorDirty = false;
            this.editorReadOnly = false;
        },

        async postJson(url, body, method = 'POST') {
            const response = await fetch(url, { method, headers: jsonHeaders(), body: JSON.stringify(body) });
            let data = {};
            try { data = await response.json(); } catch (_) {}
            if (!response.ok) {
                throw new Error(data.error || data.message || 'Request failed');
            }
            return data;
        },

        async newFolder() {
            const name = prompt('Folder name:');
            if (!name) return;

            try {
                await this.postJson(`{{ container_route('files.mkdir', $service->id) }}`, { path: this.joinPath(this.currentPath, name.trim()) });
                this.loadDirectory();
            } catch (err) {
                this.error = err.message;
            }
        },

        async newFile() {
            const name = prompt('File name (e.g. index.html, .env, script.php):');
            if (!name) return;

            const trimmed = name.trim();
            if (!trimmed || trimmed.includes('/') || trimmed.includes('\\')) {
                this.error = 'File name cannot include path separators.';
                return;
            }

            try {
                await this.postJson(`{{ container_route('files.create', $service->id) }}`, { path: this.joinPath(this.currentPath, trimmed) });
                await this.loadDirectory();
                const created = this.entries.find((entry) => entry.name === trimmed);
                if (created?.editable) {
                    await this.openEditor(trimmed);
                }
            } catch (err) {
                this.error = err.message;
            }
        },

        async renameEntry(name) {
            const newName = prompt('Rename to:', name);
            if (!newName) return;

            const trimmed = newName.trim();
            if (!trimmed || trimmed.includes('/') || trimmed.includes('\\')) {
                this.error = 'Name cannot include path separators.';
                return;
            }
            if (trimmed === name) return;

            try {
                await this.postJson(`{{ container_route('files.rename', $service->id) }}`, { path: this.joinPath(this.currentPath, name), name: trimmed }, 'PATCH');
                this.selected = this.selected.filter((n) => n !== name);
                this.loadDirectory();
            } catch (err) {
                this.error = err.message;
            }
        },

        handleFileSelect(event) {
            const files = Array.from(event.target.files || []);
            event.target.value = '';
            if (!files.length) return;
            this.uploadFiles(files);
        },

        async uploadFiles(files) {
            const tooLarge = files.filter((file) => file.size > this.maxUploadBytes);
            if (tooLarge.length) {
                this.error = `${tooLarge.map((f) => `"${f.name}"`).join(', ')} exceed${tooLarge.length === 1 ? 's' : ''} the ${this.maxUploadMb} MB upload limit.`;
                files = files.filter((file) => file.size <= this.maxUploadBytes);
                if (!files.length) return;
            }

            const formData = new FormData();
            formData.append('path', this.currentPath);
            formData.append('extract', this.extractAfterUpload ? '1' : '0');
            for (const file of files) {
                formData.append('files[]', file);
            }

            this.uploading = true;
            this.uploadProgress = 0;
            this.uploadCount = files.length;
            this.uploads = files.map((file) => ({ name: file.name, status: 'uploading', error: null }));
            this.error = null;

            try {
                const xhr = new XMLHttpRequest();
                xhr.upload.addEventListener('progress', (event) => {
                    if (event.lengthComputable) {
                        this.uploadProgress = Math.round((event.loaded / event.total) * 100);
                    }
                });

                const data = await new Promise((resolve, reject) => {
                    xhr.addEventListener('load', () => {
                        let payload = {};
                        try { payload = JSON.parse(xhr.responseText); } catch (_) {}
                        if (xhr.status >= 200 && xhr.status < 300) {
                            resolve(payload);
                        } else if (payload.files) {
                            resolve(payload);
                        } else {
                            reject(new Error(payload.error || payload.message || (payload.errors ? Object.values(payload.errors).flat().join(' ') : 'Upload failed')));
                        }
                    });
                    xhr.addEventListener('error', () => reject(new Error('Upload failed')));
                    xhr.open('POST', `{{ container_route('files.upload', $service->id) }}`);
                    xhr.setRequestHeader('X-CSRF-TOKEN', csrf());
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.send(formData);
                });

                this.uploads = (data.files || []).map((row) => ({ name: row.name, status: row.status, error: row.error || null }));
                const extracting = (data.files || []).find((row) => row.status === 'extracting' && row.operation);
                if (extracting) {
                    this.watchOperation(extracting.operation);
                }
                if (data.error && !(data.files || []).some((row) => row.status !== 'failed')) {
                    this.error = data.error;
                }
                this.loadDirectory();
            } catch (err) {
                this.error = err.message;
                this.uploads = this.uploads.map((row) => ({ ...row, status: 'failed', error: err.message }));
            } finally {
                this.uploading = false;
                this.uploadProgress = 0;
            }
        },

        async downloadFile(name) {
            const url = new URL(`{{ container_route('files.download', $service->id) }}`, window.location);
            url.searchParams.append('path', this.joinPath(this.currentPath, name));

            const a = document.createElement('a');
            a.href = url.toString();
            a.download = name;
            a.click();
        },

        async deleteFile(name) {
            if (!await window.appConfirm(`Delete "${name}"?`, 'Delete', 'Delete')) return;
            await this.deletePaths([name]);
        },

        async deleteSelected() {
            const names = [...this.selected];
            if (!names.length) return;
            const preview = names.slice(0, 5).map((n) => `• ${n}`).join('\n') + (names.length > 5 ? `\n… and ${names.length - 5} more` : '');
            if (!await window.appConfirm(`Delete ${names.length} item(s)? Folders are removed with everything inside them.\n\n${preview}`, 'Delete selected', 'Delete')) return;
            await this.deletePaths(names);
        },

        async deletePaths(names) {
            this.busy = true;
            this.error = null;
            try {
                const data = await this.postJson(`{{ container_route('files.batch-delete', $service->id) }}`, { paths: names.map((n) => this.joinPath(this.currentPath, n)) }, 'DELETE');
                const failed = Object.keys(data.failed || {});
                if (failed.length) {
                    this.error = `${failed.length} item(s) could not be deleted: ` + failed.map((p) => `${p.split('/').pop()} (${data.failed[p]})`).join('; ');
                } else if (names.length > 1) {
                    this.notice = `Deleted ${data.deleted.length} item(s).`;
                }
                this.selected = [];
                this.loadDirectory();
            } catch (err) {
                this.error = err.message;
            } finally {
                this.busy = false;
            }
        },

        async openPicker(mode) {
            if (!this.selected.length) return;
            this.picker.mode = mode;
            this.picker.open = true;
            await this.pickerNavigate(this.currentPath);
        },

        closePicker() {
            this.picker.open = false;
        },

        async pickerNavigate(path) {
            this.picker.loading = true;
            try {
                const response = await fetch(`{{ container_route('files.index', $service->id) }}?path=${encodeURIComponent(path)}`, {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() }
                });
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'Failed to load folder');
                this.picker.path = data.path;
                this.picker.breadcrumbs = data.breadcrumbs;
                this.picker.dirs = data.entries.filter((entry) => entry.type === 'dir');
            } catch (err) {
                this.error = err.message;
            } finally {
                this.picker.loading = false;
            }
        },

        async pickerNewFolder() {
            const name = prompt('New folder name:');
            if (!name) return;
            try {
                await this.postJson(`{{ container_route('files.mkdir', $service->id) }}`, { path: this.joinPath(this.picker.path, name.trim()) });
                await this.pickerNavigate(this.joinPath(this.picker.path, name.trim()));
            } catch (err) {
                this.error = err.message;
            }
        },

        async confirmPicker() {
            const names = [...this.selected];
            if (!names.length || this.picker.path === this.currentPath) return;
            this.busy = true;
            this.error = null;
            try {
                const data = await this.postJson(`{{ container_route('files.move', $service->id) }}`, {
                    paths: names.map((n) => this.joinPath(this.currentPath, n)),
                    destination: this.picker.path,
                    mode: this.picker.mode,
                });
                if (data.success) {
                    this.notice = data.message;
                } else {
                    this.error = data.message || data.error;
                }
                this.picker.open = false;
                this.selected = [];
                this.loadDirectory();
            } catch (err) {
                this.error = err.message;
            } finally {
                this.busy = false;
            }
        },

        async extractEntry(name) {
            const path = this.joinPath(this.currentPath, name);
            const remove = await window.appConfirm(`Extract "${name}" into this folder? Existing files with the same names are overwritten. The archive itself is deleted afterwards.`, 'Extract archive', 'Extract');
            if (!remove) return;
            this.error = null;
            try {
                const data = await this.postJson(`{{ container_route('files.extract', $service->id) }}`, { path, destination: this.currentPath, delete_archive: true });
                this.watchOperation(data.operation);
            } catch (err) {
                this.error = err.message;
            }
        },

        async downloadSelected() {
            const names = [...this.selected];
            if (!names.length) return;
            this.error = null;
            try {
                const data = await this.postJson(`{{ container_route('files.archive', $service->id) }}`, { paths: names.map((n) => this.joinPath(this.currentPath, n)) });
                this.watchOperation(data.operation);
            } catch (err) {
                this.error = err.message;
            }
        },

        watchOperation(state) {
            this.clearOperation();
            this.applyOperation(state);
            if (this.operation.active) {
                this.operation.timer = setInterval(() => this.pollOperation(), 1000);
            }
        },

        applyOperation(state) {
            const active = state.status === 'queued' || state.status === 'running';
            this.operation.token = state.token;
            this.operation.type = state.type;
            this.operation.status = state.status;
            this.operation.percent = state.percent || 0;
            this.operation.label = state.label || '';
            this.operation.active = active;
            this.operation.downloadUrl = (!active && state.status === 'completed' && state.type === 'archive') ? archiveDownloadUrl(state.token) : null;
            if (!active) {
                if (this.operation.timer) { clearInterval(this.operation.timer); this.operation.timer = null; }
                if (state.type === 'extract') {
                    this.loadDirectory();
                    if (state.status === 'completed') this.notice = state.label;
                }
                if (state.status === 'completed' && state.type === 'archive' && this.operation.downloadUrl) {
                    window.location.assign(this.operation.downloadUrl);
                }
            }
        },

        async pollOperation() {
            if (!this.operation.token) return;
            try {
                const response = await fetch(operationUrl(this.operation.token), { headers: { 'Accept': 'application/json' } });
                if (!response.ok) {
                    if (response.status === 404) {
                        this.applyOperation({ ...this.operation, status: 'failed', label: 'The operation expired before it finished.' });
                    }
                    return;
                }
                this.applyOperation(await response.json());
            } catch (_) {
                // transient; keep polling
            }
        },

        clearOperation() {
            if (this.operation.timer) { clearInterval(this.operation.timer); }
            this.operation = { token: null, type: null, status: null, percent: 0, label: '', active: false, downloadUrl: null, timer: null };
        },

        formatBytes(bytes) {
            const units = ['B', 'KB', 'MB', 'GB'];
            bytes = Math.max(bytes, 0);
            const pow = Math.floor((bytes ? Math.log(bytes) : 0) / Math.log(1024));
            const index = Math.min(pow, units.length - 1);
            bytes /= Math.pow(1024, index);
            return Math.round(bytes * 100) / 100 + ' ' + units[index];
        },

        formatDate(timestamp) {
            const date = new Date(timestamp * 1000);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
    }
}
</script>
@endpush
