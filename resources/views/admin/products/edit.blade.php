@extends('layouts.admin')

@section('title', 'Edit Product')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.products.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Products</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <a href="{{ route('admin.products.show', $product) }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $product->name }}</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Edit</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Edit Product</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Update product details and pricing.</p>
    </div>

    <!-- Form -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <form method="POST" action="{{ route('admin.products.update', $product) }}" class="space-y-8">
            @csrf
            @method('PUT')

            <!-- Two-column layout -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Left Column -->
                <div class="space-y-6">
                    <!-- Name -->
                    <div>
                        <label for="name" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Product Name</label>
                        <input type="text" id="name" name="name" value="{{ old('name', $product->name) }}" placeholder="Shared Hosting Plan" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('name') border-red-500 @enderror" required>
                        @error('name')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Slug -->
                    <div>
                        <label for="slug" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Slug</label>
                        <input type="text" id="slug" name="slug" value="{{ old('slug', $product->slug) }}" placeholder="shared-hosting-plan" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('slug') border-red-500 @enderror" required>
                        @error('slug')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Description -->
                    <div>
                        <label for="description" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Description <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <textarea id="description" name="description" rows="4" placeholder="Describe this product..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm resize-none">{{ old('description', $product->description) }}</textarea>
                        @error('description')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Category -->
                    <div>
                        <label for="category" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Category <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <input type="text" id="category" name="category" value="{{ old('category', $product->category) }}" placeholder="e.g., Hosting, VPS, Servers" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('category') border-red-500 @enderror">
                        @error('category')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Type -->
                    <div>
                        <label for="type" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Product Type</label>
                        <select id="type" name="type" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('type') border-red-500 @enderror" required @change="$nextTick(() => {})">
                            <option value="">Select a type...</option>
                            <option value="shared_hosting" @selected(old('type', $product->type) === 'shared_hosting')>Shared Hosting</option>
                            <option value="container_hosting" @selected(old('type', $product->type) === 'container_hosting')>Container Hosting</option>
                            <option value="vps" @selected(old('type', $product->type) === 'vps')>VPS Server</option>
                            <option value="dedicated_server" @selected(old('type', $product->type) === 'dedicated_server')>Dedicated Server</option>
                            <option value="ssl" @selected(old('type', $product->type) === 'ssl')>SSL Certificate</option>
                            <option value="email_hosting" @selected(old('type', $product->type) === 'email_hosting')>Email Hosting</option>
                        </select>
                        @error('type')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Container Template (conditional - only for container hosting) -->
                    <div x-show="document.getElementById('type').value === 'container_hosting'">
                        <label for="container_template_id" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Container Template <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(required for container hosting)</span></label>
                        <select id="container_template_id" name="container_template_id" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('container_template_id') border-red-500 @enderror">
                            <option value="">Select a container template...</option>
                            @foreach(\App\Models\ContainerTemplate::all() as $template)
                                <option value="{{ $template->id }}" @selected(old('container_template_id', $product->container_template_id) == $template->id)>{{ $template->name }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Select which container template this package is for (PHP, Node.js, Python, etc.)</p>
                        @error('container_template_id')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Status toggle -->
                    <div>
                        <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Status</label>
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" name="is_active" value="1" class="w-4 h-4 text-blue-600 rounded" @checked(old('is_active', $product->is_active) === '1' || old('is_active', $product->is_active) === 1)>
                            <span class="text-sm text-slate-700 dark:text-slate-300">Active</span>
                        </label>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="space-y-6">
                    <!-- Monthly Price -->
                    <div>
                        <label for="monthly_price" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Monthly Price <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <div class="relative">
                            <span class="absolute left-4 top-2 text-slate-500 dark:text-slate-400 text-sm">$</span>
                            <input type="number" id="monthly_price" name="monthly_price" value="{{ old('monthly_price', $product->monthly_price) }}" placeholder="0.00" step="0.01" min="0" class="w-full pl-7 pr-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('monthly_price') border-red-500 @enderror">
                        </div>
                        @error('monthly_price')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Yearly Price -->
                    <div>
                        <label for="yearly_price" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Yearly Price <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <div class="relative">
                            <span class="absolute left-4 top-2 text-slate-500 dark:text-slate-400 text-sm">$</span>
                            <input type="number" id="yearly_price" name="yearly_price" value="{{ old('yearly_price', $product->yearly_price) }}" placeholder="0.00" step="0.01" min="0" class="w-full pl-7 pr-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('yearly_price') border-red-500 @enderror">
                        </div>
                        @error('yearly_price')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Wholesale Pricing Section -->
                    <div class="pt-4 border-t border-slate-200 dark:border-slate-700">
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-4">Wholesale Rate (for Resellers)</p>

                        <!-- Wholesale Monthly Price -->
                        <div class="mb-4">
                            <label for="wholesale_monthly_price" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Wholesale Monthly <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                            <div class="relative">
                                <span class="absolute left-4 top-2 text-slate-500 dark:text-slate-400 text-sm">$</span>
                                <input type="number" id="wholesale_monthly_price" name="wholesale_monthly_price" value="{{ old('wholesale_monthly_price', $product->wholesale_monthly_price) }}" placeholder="0.00" step="0.01" min="0" class="w-full pl-7 pr-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('wholesale_monthly_price') border-red-500 @enderror">
                            </div>
                            @error('wholesale_monthly_price')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Wholesale Yearly Price -->
                        <div>
                            <label for="wholesale_yearly_price" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Wholesale Yearly <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                            <div class="relative">
                                <span class="absolute left-4 top-2 text-slate-500 dark:text-slate-400 text-sm">$</span>
                                <input type="number" id="wholesale_yearly_price" name="wholesale_yearly_price" value="{{ old('wholesale_yearly_price', $product->wholesale_yearly_price) }}" placeholder="0.00" step="0.01" min="0" class="w-full pl-7 pr-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('wholesale_yearly_price') border-red-500 @enderror">
                            </div>
                            @error('wholesale_yearly_price')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Set these so resellers can calculate their markup and margin.</p>
                        </div>
                    </div>

                    <!-- Setup Fee -->
                    <div>
                        <label for="setup_fee" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Setup Fee <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <div class="relative">
                            <span class="absolute left-4 top-2 text-slate-500 dark:text-slate-400 text-sm">$</span>
                            <input type="number" id="setup_fee" name="setup_fee" value="{{ old('setup_fee', $product->setup_fee) }}" placeholder="0.00" step="0.01" min="0" class="w-full pl-7 pr-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('setup_fee') border-red-500 @enderror">
                        </div>
                        @error('setup_fee')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Provisioning Driver Key -->
                    <div>
                        <label for="provisioning_driver_key" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Provisioning Driver Key <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <input type="text" id="provisioning_driver_key" name="provisioning_driver_key" value="{{ old('provisioning_driver_key', $product->provisioning_driver_key) }}" placeholder="driver_key" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        @error('provisioning_driver_key')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Featured & Reseller toggles -->
                    <div class="space-y-2">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" name="featured" value="1" class="w-4 h-4 text-blue-600 rounded" @checked(old('featured', $product->featured) === '1' || old('featured', $product->featured) === 1)>
                            <span class="text-sm text-slate-700 dark:text-slate-300">Featured Product</span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" name="visible_to_resellers" value="1" class="w-4 h-4 text-blue-600 rounded" @checked(old('visible_to_resellers', $product->visible_to_resellers) === '1' || old('visible_to_resellers', $product->visible_to_resellers) === 1)>
                            <span class="text-sm text-slate-700 dark:text-slate-300">Visible to Resellers</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Resource Limits (Full width) - Hide for VPS/Dedicated Server -->
            <div x-show="document.getElementById('type').value !== 'vps' && document.getElementById('type').value !== 'dedicated_server'">
                <label for="resource_limits" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Resource Limits <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(JSON format, optional)</span></label>
                <textarea id="resource_limits" name="resource_limits" rows="4" placeholder='{"cpu": "2", "memory": "2GB", "disk": "20GB"}' class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm font-mono resize-none">{{ old('resource_limits') ? (is_array(old('resource_limits')) ? json_encode(old('resource_limits')) : old('resource_limits')) : ($product->resource_limits ? json_encode($product->resource_limits) : '') }}</textarea>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Enter valid JSON or leave blank</p>
                @error('resource_limits')
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <!-- Container Overage Billing (conditional) -->
            @if ($product->type === 'container_hosting')
                <div class="border-t border-slate-200 dark:border-slate-800 pt-6">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Container Overage Billing</h3>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Enable Overage -->
                        <div>
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" name="overage_enabled" value="1" class="w-4 h-4 text-blue-600 rounded" @checked(old('overage_enabled', $product->overage_enabled))>
                                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Enable overage billing</span>
                            </label>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Charge customers for usage above template allocation</p>
                        </div>

                        <!-- CPU Overage Rate -->
                        <div>
                            <label for="cpu_overage_rate" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">CPU Overage Rate (KES/core-hour)</label>
                            <input type="number" id="cpu_overage_rate" name="cpu_overage_rate" value="{{ old('cpu_overage_rate', $product->cpu_overage_rate ?? 0) }}" step="0.01" min="0" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('cpu_overage_rate') border-red-500 @enderror">
                            @error('cpu_overage_rate')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- RAM Overage Rate -->
                        <div>
                            <label for="ram_overage_rate" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">RAM Overage Rate (KES/GB-hour)</label>
                            <input type="number" id="ram_overage_rate" name="ram_overage_rate" value="{{ old('ram_overage_rate', $product->ram_overage_rate ?? 0) }}" step="0.01" min="0" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('ram_overage_rate') border-red-500 @enderror">
                            @error('ram_overage_rate')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            @endif

            <!-- Server Configuration (VPS / Dedicated Server) -->
            <div x-show="document.getElementById('type').value === 'vps' || document.getElementById('type').value === 'dedicated_server'" class="border-t border-slate-200 dark:border-slate-800 pt-6">
                <div class="space-y-4 mb-4">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Server Configuration</h3>
                    <div class="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                        <p class="text-sm text-blue-900 dark:text-blue-300">Login credentials (username: <code class="font-mono">root</code>, password: auto-generated) will be emailed to the customer and admin upon provisioning.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Server Specs -->
                    <div>
                        <label for="server_specs" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Server Specifications <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <textarea id="server_specs" name="resource_limits[specs]" rows="3" placeholder="2 vCPU, 4GB RAM, 100GB SSD" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm resize-none">{{ old('resource_limits.specs') ? old('resource_limits.specs') : ($product->resource_limits['specs'] ?? '') }}</textarea>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">E.g., "2 vCPU, 4GB RAM, 100GB SSD"</p>
                    </div>

                    <!-- Datacenter Location -->
                    <div>
                        <label for="server_location" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Datacenter Location <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <input type="text" id="server_location" name="resource_limits[location]" value="{{ old('resource_limits.location') ? old('resource_limits.location') : ($product->resource_limits['location'] ?? '') }}" placeholder="Nairobi, Kenya" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                    </div>

                    <!-- Operating System -->
                    <div>
                        <label for="server_os" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Operating System <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <select id="server_os" name="resource_limits[os]" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                            <option value="">Select an OS...</option>
                            <option value="ubuntu_2204" @selected((old('resource_limits.os') ? old('resource_limits.os') : ($product->resource_limits['os'] ?? '')) === 'ubuntu_2204')>Ubuntu 22.04 LTS</option>
                            <option value="ubuntu_2004" @selected((old('resource_limits.os') ? old('resource_limits.os') : ($product->resource_limits['os'] ?? '')) === 'ubuntu_2004')>Ubuntu 20.04 LTS</option>
                            <option value="centos_8" @selected((old('resource_limits.os') ? old('resource_limits.os') : ($product->resource_limits['os'] ?? '')) === 'centos_8')>CentOS 8</option>
                            <option value="debian_11" @selected((old('resource_limits.os') ? old('resource_limits.os') : ($product->resource_limits['os'] ?? '')) === 'debian_11')>Debian 11</option>
                            <option value="windows_2022" @selected((old('resource_limits.os') ? old('resource_limits.os') : ($product->resource_limits['os'] ?? '')) === 'windows_2022')>Windows Server 2022</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="flex items-center justify-between pt-6 border-t border-slate-200 dark:border-slate-800">
                <a href="{{ route('admin.products.show', $product) }}" class="px-6 py-2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium transition">
                    Cancel
                </a>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
