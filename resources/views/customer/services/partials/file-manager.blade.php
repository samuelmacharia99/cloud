@php($containerUploadMaxMb = (int) config('security.container_file_upload.max_size_mb', 100))

<div class="bg-white rounded-lg shadow mb-8">
    <div x-data="fileManager()" class="border border-gray-200 rounded-lg">
        <!-- Header -->
        <div class="border-b border-gray-200 px-6 py-4 flex items-center justify-between">
            <button @click="open = !open" class="flex items-center gap-2 font-medium text-gray-700 hover:text-gray-900">
                <span x-text="open ? '▼' : '▶'" class="text-sm"></span>
                <span>📁 File Manager</span>
            </button>
        </div>

    <!-- Manager content -->
    <template x-if="open">
        <div class="p-6 space-y-4">
            <!-- Breadcrumb navigation -->
            <div class="flex items-center gap-2 text-sm">
                <template x-for="(crumb, idx) in breadcrumbs" :key="idx">
                    <div class="flex items-center gap-2">
                        <a @click="navigate(crumb.path)" href="#" class="text-blue-600 hover:text-blue-800">
                            <span x-text="crumb.label"></span>
                        </a>
                        <span x-show="idx < breadcrumbs.length - 1" class="text-gray-400">/</span>
                    </div>
                </template>
            </div>

            <!-- Toolbar -->
            <div class="flex items-center gap-2 flex-wrap">
                <button @click="newFolder()" class="px-3 py-2 bg-blue-50 text-blue-600 rounded hover:bg-blue-100 text-sm font-medium">
                    ➕ New Folder
                </button>
                <button @click="$refs.fileInput.click()" class="px-3 py-2 bg-green-50 text-green-600 rounded hover:bg-green-100 text-sm font-medium">
                    ⬆️ Upload
                </button>
                <input type="file" x-ref="fileInput" @change="handleFileSelect" class="hidden" multiple>
                <span class="text-xs text-slate-500 dark:text-slate-400">Max {{ $containerUploadMaxMb }} MB per file (zip supported)</span>
                <button @click="deleteSelected()" x-show="selected.size > 0" class="px-3 py-2 bg-red-50 text-red-600 rounded hover:bg-red-100 text-sm font-medium">
                    🗑️ Delete (<span x-text="selected.size"></span>)
                </button>
            </div>

            <!-- Error message -->
            <template x-if="error">
                <div class="p-3 bg-red-50 border border-red-200 text-red-700 rounded text-sm flex items-center justify-between">
                    <span x-text="error"></span>
                    <button @click="error = null" class="text-red-600 hover:text-red-800">✕</button>
                </div>
            </template>

            <!-- Upload progress -->
            <template x-if="uploading">
                <div class="p-3 bg-blue-50 border border-blue-200 rounded">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm text-blue-700">Uploading...</span>
                        <span class="text-sm text-blue-700" x-text="`${uploadProgress}%`"></span>
                    </div>
                    <div class="w-full bg-blue-200 rounded-full h-2">
                        <div class="bg-blue-600 h-2 rounded-full transition-all" :style="`width: ${uploadProgress}%`"></div>
                    </div>
                </div>
            </template>

            <!-- Files table -->
            <template x-if="!loading && entries.length > 0">
                <div class="border border-gray-200 rounded overflow-hidden">
                    <table class="w-full text-sm">
                        <tbody>
                            <template x-for="entry in entries" :key="entry.name">
                                <tr class="border-b border-gray-200 hover:bg-gray-50"
                                    @dragover.prevent="dragOver = true"
                                    @dragleave="dragOver = false"
                                    @drop.prevent="dropFiles">
                                    <td class="px-4 py-3 w-6">
                                        <input type="checkbox" @change="toggleSelect(entry.name)" :checked="selected.has(entry.name)" class="rounded">
                                    </td>
                                    <td class="px-4 py-3 w-6">
                                        <span x-text="entry.type === 'dir' ? '📁' : '📄'"></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <button @click="entry.type === 'dir' ? navigate(joinPath(currentPath, entry.name)) : downloadFile(entry.name)"
                                            :class="entry.type === 'dir' ? 'text-blue-600 hover:text-blue-800 cursor-pointer' : 'text-gray-700'"
                                            class="break-all text-left">
                                            <span x-text="entry.name"></span>
                                        </button>
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-500" x-show="entry.type !== 'dir'">
                                        <span x-text="formatBytes(entry.size)"></span>
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-500 text-xs">
                                        <span x-text="formatDate(entry.modified)"></span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <button @click="deleteFile(entry.name)" class="text-red-600 hover:text-red-800 text-sm">Delete</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>

            <!-- Empty state -->
            <template x-if="!loading && entries.length === 0">
                <div class="text-center py-12 text-gray-500">
                    <div class="text-4xl mb-2">📂</div>
                    <div>Empty directory</div>
                </div>
            </template>

            <!-- Loading -->
            <template x-if="loading">
                <div class="text-center py-12">
                    <div class="inline-flex items-center gap-2">
                        <div class="w-4 h-4 bg-blue-500 rounded-full animate-bounce"></div>
                        <span class="text-gray-600">Loading directory...</span>
                    </div>
                </div>
            </template>
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
        open: false,
        loading: false,
        uploading: false,
        uploadProgress: 0,
        currentPath: '/',
        entries: [],
        breadcrumbs: [],
        dragOver: false,
        error: null,
        selected: new Set(),

        init() {
            this.loadDirectory();
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
                console.error('Error loading directory:', err);
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

        async newFolder() {
            const name = prompt('Folder name:');
            if (!name) return;

            const path = this.joinPath(this.currentPath, name);

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
                this.error = `“${file.name}” is too large. Maximum upload size is ${this.maxUploadMb} MB.`;
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
                            if (xhr.status === 413) {
                                message = `Upload rejected: file too large for the web server (HTTP 413). `
                                    + `Ask your host to set nginx client_max_body_size and PHP post_max_size to at least ${this.maxUploadMb}M.`;
                            }
                            try {
                                const data = JSON.parse(xhr.responseText);
                                message = data.error || data.message || message;
                            } catch (_) {}
                            reject(new Error(message));
                        }
                    });

                    xhr.addEventListener('error', () => reject(new Error('Upload failed')));
                    xhr.addEventListener('abort', () => reject(new Error('Upload cancelled')));

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

        dropFiles(event) {
            this.dragOver = false;
            const files = event.dataTransfer.files;
            if (!files.length) return;

            for (let file of files) {
                this.uploadFile(file);
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
            if (!confirm(`Delete "${name}"?`)) return;

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
            if (!confirm(`Delete ${this.selected.size} item(s)?`)) return;

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
            return new Date(timestamp * 1000).toLocaleDateString() + ' ' + new Date(timestamp * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
    }
}
</script>
@endpush
