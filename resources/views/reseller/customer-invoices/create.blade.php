@extends('layouts.reseller')

@section('title', 'Create Customer Invoice')

@section('content')
<div class="space-y-6 max-w-3xl" x-data="invoiceForm()">
    <div>
        <a href="{{ route('reseller.customer-invoices.index') }}" class="text-sm text-purple-600">← Customer billing</a>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white mt-2">Create invoice</h1>
    </div>

    <form method="POST" action="{{ route('reseller.customer-invoices.store') }}" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-6">
        @csrf

        <div>
            <label class="block text-sm font-medium mb-2">Customer</label>
            <select name="customer_id" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
                <option value="">Select customer</option>
                @foreach ($customers as $customer)
                    <option value="{{ $customer->id }}" @selected(old('customer_id', $selectedCustomer?->id) == $customer->id)>{{ $customer->name }} ({{ $customer->email }})</option>
                @endforeach
            </select>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium mb-2">Status</label>
                <select name="status" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
                    <option value="unpaid" @selected(old('status', 'unpaid') === 'unpaid')>Unpaid (send to customer)</option>
                    <option value="draft" @selected(old('status') === 'draft')>Draft</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-2">Due date</label>
                <input type="date" name="due_date" value="{{ old('due_date', now()->addDays(7)->format('Y-m-d')) }}" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium mb-2">Tax rate (%)</label>
            <input type="number" name="tax_rate" step="0.01" min="0" max="100" value="{{ old('tax_rate', 0) }}" class="w-full max-w-xs px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
        </div>

        <div>
            <div class="flex justify-between items-center mb-3">
                <h2 class="font-semibold">Line items</h2>
                <button type="button" @click="addItem()" class="text-sm text-purple-600 font-medium">+ Add line</button>
            </div>
            <template x-for="(item, index) in items" :key="index">
                <div class="grid grid-cols-1 md:grid-cols-12 gap-3 mb-3 p-4 rounded-lg bg-slate-50 dark:bg-slate-800/50">
                    <div class="md:col-span-5">
                        <input type="text" :name="'items['+index+'][description]'" x-model="item.description" placeholder="Description" required class="w-full px-3 py-2 border rounded-lg bg-white dark:bg-slate-800">
                    </div>
                    <div class="md:col-span-2">
                        <input type="number" :name="'items['+index+'][quantity]'" x-model="item.quantity" step="0.01" min="0.01" required class="w-full px-3 py-2 border rounded-lg bg-white dark:bg-slate-800">
                    </div>
                    <div class="md:col-span-3">
                        <input type="number" :name="'items['+index+'][unit_price]'" x-model="item.unit_price" step="0.01" min="0" required class="w-full px-3 py-2 border rounded-lg bg-white dark:bg-slate-800">
                    </div>
                    <div class="md:col-span-2 flex items-center">
                        <button type="button" @click="removeItem(index)" x-show="items.length > 1" class="text-sm text-red-600">Remove</button>
                    </div>
                </div>
            </template>
        </div>

        <div>
            <label class="block text-sm font-medium mb-2">Notes</label>
            <textarea name="notes" rows="3" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">{{ old('notes') }}</textarea>
        </div>

        <button type="submit" class="px-6 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium">Create invoice</button>
    </form>
</div>

<script>
function invoiceForm() {
    const oldItems = @json(old('items', [['description' => '', 'quantity' => 1, 'unit_price' => 0]]));
    return {
        items: oldItems.length ? oldItems : [{ description: '', quantity: 1, unit_price: 0 }],
        addItem() { this.items.push({ description: '', quantity: 1, unit_price: 0 }); },
        removeItem(i) { if (this.items.length > 1) this.items.splice(i, 1); },
    };
}
</script>
@endsection
