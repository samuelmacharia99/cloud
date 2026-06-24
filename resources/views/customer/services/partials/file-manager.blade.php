@php($containerUploadMaxMb = (int) config('security.container_file_upload.max_size_mb', 100))
@php($editorMaxKb = (int) round(((int) config('containers.file_editor.max_bytes', 524288)) / 1024))
@php($filesTabActive = request('tab') === 'files')

<div class="bg-white dark:bg-slate-800 rounded-lg shadow mb-8">
    <div x-data="fileManager()" class="border border-gray-200 dark:border-slate-700 rounded-lg">
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
                <button @click="$refs.fileInput.click()" class="px-3 py-2 bg-green-50 dark:bg-green-900/30 text-green-600 dark:text-green-300 rounded hover:bg-green-100 dark:hover:bg-green-900/50 text-sm font-medium">
                    ⬆️ Upload
                </button>
                <button @click="loadDirectory()" :disabled="loading" class="px-3 py-2 bg-slate-50 dark:bg-slate-700/50 text-slate-600 dark:text-slate-300 rounded hover:bg-slate-100 dark:hover:bg-slate-700 text-sm font-medium disabled:opacity-50">
                    ↻ Refresh
                </button>
                <input type="file" x-ref="fileInput" @change="handleFileSelect" class="hidden" multiple>
                <span class="text-xs text-slate-500 dark:text-slate-400">Max {{ $containerUploadMaxMb }} MB upload · {{ $editorMaxKb }} KB editor limit</span>
                <button @click="deleteSelected()" x-show="selected.size > 0" class="px-3 py-2 bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-300 rounded hover:bg-red-100 dark:hover:bg-red-900/50 text-sm font-medium">
                    🗑️ Delete (<span x-text="selected.size"></span>)
                </button>
            </div>

            <template x-if="error">
                <div class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 rounded text-sm flex items-center justify-between">
                    <span x-text="error"></span>
                    <button @click="error = null" class="text-red-600 dark:text-red-400">✕</button>
                </div>
            </template>

            <template x-if="uploading">
                <div class="p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm text-blue-700 dark:text-blue-300">Uploading...</span>
                        <span class="text-sm text-blue-700 dark:text-blue-300" x-text="`${uploadProgress}%`"></span>
                    </div>
                    <div class="w-full bg-blue-200 dark:bg-blue-900 rounded-full h-2">
                        <div class="bg-blue-600 h-2 rounded-full transition-all" :style="`width: ${uploadProgress}%`"></div>
                    </div>
                </div>
            </template>

            <template x-if="!loading && entries.length > 0">
                <div class="border border-gray-200 dark:border-slate-700 rounded overflow-hidden">
                    <table class="w-full text-sm">
                        <tbody>
                            <template x-for="entry in entries" :key="entry.name">
                                <tr class="border-b border-gray-200 dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700/40">
                                    <td class="px-4 py-3 w-6">
                                        <input type="checkbox" @change="toggleSelect(entry.name)" :checked="selected.has(entry.name)" class="rounded">
                                    </td>
                                    <td class="px-4 py-3 w-6">
                                        <span x-text="entry.type === 'dir' ? '📁' : '📄'"></span>
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
                                    <td class="px-4 py-3 text-right text-gray-500 dark:text-slate-400" x-show="entry.type !== 'dir'">
                                        <span x-text="formatBytes(entry.size)"></span>
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-500 dark:text-slate-400 text-xs">
                                        <span x-text="formatDate(entry.modified)"></span>
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap space-x-2">
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

            <div x-show="editorOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @keydown.escape.window="closeEditor()">
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
        </div>
    </template>
    </div>
</div>

@push('scripts')
<script>
function fileManager() {
    return {
        maxUploadBytes: {{ $containerUploadMaxMb }} * 1024 * 1024,
        maxUploadMb: {{ $containerUploadMaxMb }},
        open: @json($filesTabActive),
        loading: false,
        uploading: false,
        uploadProgress: 0,
        currentPath: '/',
        entries: [],
        breadcrumbs: [],
        error: null,
        selected: new Set(),
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
                const response = await fetch(`{{ route('customer.services.container.files.index', $service->id) }}?path=${encodeURIComponent(this.currentPath)}`, {
                    headers: {
                        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content
                    }
                });

                if (!response.ok) {
                    const data = await response.json();
                    throw new Error(data.error || 'Failed to load directory');
                }

                const data = await response.json();
                this.entries = data.entries;
                this.breadcrumbs = data.breadcrumbs;
                this.currentPath = data.path;
                this.selected.clear();
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

        toggleSelect(name) {
            if (this.selected.has(name)) {
                this.selected.delete(name);
            } else {
                this.selected.add(name);
            }
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
                const response = await fetch(`{{ route('customer.services.container.files.content', $service->id) }}?path=${encodeURIComponent(path)}`, {
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
                    },
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
            if (!this.editorPath || this.editorSaving) {
                return;
            }

            this.editorSaving = true;
            this.error = null;

            try {
                const response = await fetch(`{{ route('customer.services.container.files.save', $service->id) }}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        path: this.editorPath,
                        content: this.editorContent,
                    }),
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

        async newFolder() {
            const name = prompt('Folder name:');
            if (!name) return;

            const path = this.joinPath(this.currentPath, name.trim());

            try {
                const response = await fetch(`{{ route('customer.services.container.files.mkdir', $service->id) }}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({ path })
                });

                if (!response.ok) {
                    const data = await response.json();
                    throw new Error(data.error || 'Failed to create folder');
                }

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

            const path = this.joinPath(this.currentPath, trimmed);

            try {
                const response = await fetch(`{{ route('customer.services.container.files.create', $service->id) }}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ path }),
                });

                const data = await response.json();
                if (!response.ok) {
                    throw new Error(data.error || 'Failed to create file');
                }

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

            if (trimmed === name) {
                return;
            }

            const path = this.joinPath(this.currentPath, name);

            try {
                const response = await fetch(`{{ route('customer.services.container.files.rename', $service->id) }}`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ path, name: trimmed }),
                });

                const data = await response.json();
                if (!response.ok) {
                    throw new Error(data.error || 'Failed to rename');
                }

                this.selected.delete(name);
                this.loadDirectory();
            } catch (err) {
                this.error = err.message;
            }
        },

        handleFileSelect(event) {
            const files = event.target.files;
            if (!files.length) return;

            for (let file of files) {
                this.uploadFile(file);
            }

            event.target.value = '';
        },

        async uploadFile(file) {
            if (file.size > this.maxUploadBytes) {
                this.error = `"${file.name}" is too large. Maximum upload size is ${this.maxUploadMb} MB.`;
                return;
            }

            const path = this.joinPath(this.currentPath, file.name);
            const formData = new FormData();
            formData.append('path', path);
            formData.append('file', file);

            this.uploading = true;
            this.uploadProgress = 0;
            this.error = null;

            try {
                const xhr = new XMLHttpRequest();

                xhr.upload.addEventListener('progress', (event) => {
                    if (event.lengthComputable) {
                        this.uploadProgress = Math.round((event.loaded / event.total) * 100);
                    }
                });

                await new Promise((resolve, reject) => {
                    xhr.addEventListener('load', () => {
                        if (xhr.status >= 200 && xhr.status < 300) {
                            resolve();
                        } else {
                            let message = 'Upload failed';
                            try {
                                const data = JSON.parse(xhr.responseText);
                                message = data.error || data.message || message;
                            } catch (_) {}
                            reject(new Error(message));
                        }
                    });

                    xhr.addEventListener('error', () => reject(new Error('Upload failed')));
                    xhr.open('POST', `{{ route('customer.services.container.files.upload', $service->id) }}`);
                    xhr.setRequestHeader('X-CSRF-TOKEN', document.head.querySelector('meta[name="csrf-token"]').content);
                    xhr.send(formData);
                });

                this.loadDirectory();
            } catch (err) {
                this.error = err.message;
            } finally {
                this.uploading = false;
                this.uploadProgress = 0;
            }
        },

        async downloadFile(name) {
            const path = this.joinPath(this.currentPath, name);
            const url = new URL(`{{ route('customer.services.container.files.download', $service->id) }}`, window.location);
            url.searchParams.append('path', path);

            const a = document.createElement('a');
            a.href = url.toString();
            a.download = name;
            a.click();
        },

        async deleteFile(name) {
            if (!await window.appConfirm(`Delete "${name}"?`, 'Delete file', 'Delete')) return;

            const path = this.joinPath(this.currentPath, name);

            try {
                const response = await fetch(`{{ route('customer.services.container.files.delete', $service->id) }}`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({ path })
                });

                if (!response.ok) {
                    const data = await response.json();
                    throw new Error(data.error || 'Delete failed');
                }

                this.loadDirectory();
            } catch (err) {
                this.error = err.message;
            }
        },

        async deleteSelected() {
            if (!await window.appConfirm(`Delete ${this.selected.size} item(s)?`, 'Delete selected', 'Delete')) return;

            for (let name of this.selected) {
                await this.deleteFile(name);
            }

            this.selected.clear();
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
