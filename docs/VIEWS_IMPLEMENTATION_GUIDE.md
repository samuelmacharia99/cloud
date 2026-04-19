# Views Implementation Guide - Phase 5

## Status: 3 of 9 Views Complete ✅

### Completed Views (Using New Components)
- ✅ `resources/views/admin/payments/index.blade.php` — Filters, payment-badge, status-badge, currency-formatter
- ✅ `resources/views/admin/payments/show.blade.php` — Payment detail with related invoice, timeline
- ✅ `resources/views/customer/payments/index.blade.php` — My payments list with stats

---

## Remaining Views (6 Views)

### 1. Admin Payments Create Form
**File:** `resources/views/admin/payments/create.blade.php`

**Purpose:** Manual payment recording form for admin

**Key Components:**
- `x-form-select` for user selection (with search)
- `x-form-select` for payment method (enum dropdown)
- `x-form-input` for amount
- `x-form-input` for transaction reference
- `x-form-select` for status
- `x-form-input` for paid_at datetime
- `x-form-select` for currency
- `x-form-input` for notes textarea

**Controller Variables:**
```php
$users             // User::where('is_admin', false)->orderBy('name')->get()
$invoices          // Invoice::orderBy('invoice_number')->get()
$paymentMethods    // PaymentMethod::options()
$statuses          // PaymentStatus::options()
```

**Validation:** StorePaymentRequest automatically validates

---

### 2. Admin Payments Edit Form
**File:** `resources/views/admin/payments/edit.blade.php`

**Purpose:** Update payment status and notes only (not amount/method)

**Key Components:**
- Payment details display (read-only): amount, method, currency, reference
- `x-form-select` for status (with validation hint about transitions)
- `x-form-input` for notes
- Original invoice link (if exists)

**Important:** Status transitions are validated in UpdatePaymentRequest:
- `pending` → `completed` or `failed` only
- `completed` → `reversed` only
- `failed` and `reversed` cannot be changed

**UI Hint:** Show current status and allowed transitions

---

### 3. Admin Resellers Index
**File:** `resources/views/admin/resellers/index.blade.php`

**Purpose:** List all resellers with management actions

**Key Components:**
- Filter by status (active/inactive)
- Table with columns:
  - Reseller avatar + name/email
  - Company name
  - Services managed (withCount)
  - Customers served (via services count)
  - Revenue (sum of service amounts)
  - Promote/Demote actions
  - View link

**Controller Variables:**
```php
$resellers  // User::where('is_reseller', true)->withCount([...])
```

**Pattern:** Same as admin/customers index but focused on reseller stats

---

### 4. Admin Resellers Show Detail
**File:** `resources/views/admin/resellers/show.blade.php`

**Purpose:** Reseller detail page with tabs for overview, services, customers

**Key Components:**
- Header: Avatar, name, company, email, contact
- Status badge
- Action buttons: Edit (modal), Demote (confirmation dialog)
- 3 Tabs (Alpine.js):
  
**Tab 1: Overview**
  - Profile summary
  - Key stats: services managed, customers, revenue
  - Placeholder: Pricing tiers, wallet balance, commissions
  
**Tab 2: Services**
  - Table of services this reseller manages
  - Columns: Service name, customer, status, next renewal
  
**Tab 3: Customers**
  - Table of customers this reseller serves
  - Columns: Customer name, email, services count, total spend

**Controller Variables:**
```php
$user        // The reseller User model
$services    // Service::where('reseller_id', $user->id)->with('user', 'product')
$customers   // Unique customers served
```

---

### 5. Admin Settings Tabbed Form
**File:** `resources/views/admin/settings/index.blade.php`

**Purpose:** Configuration panel with 8 tabs

**Key Components:**
- Left sidebar (or tabs) with 8 groups:
  1. **General** — site_name, site_url, site_email, support_email, timezone, date_format, currency, currency_symbol
  2. **Billing** — billing_company, billing_address, billing_city, billing_country, billing_vat_number, invoice_prefix, invoice_due_days, grace_period_days
  3. **Tax** — tax_enabled (toggle), tax_rate (number), tax_name (text), tax_inclusive (toggle), tax_number (text)
  4. **Payment Methods** — toggles for mpesa/card/bank_transfer/manual, plus credentials (shortcode, passkey, stripe key, bank details)
  5. **Provisioning** — provisioning_mode (select), auto_provision (toggle), suspend_on_overdue (toggle), terminate_after_days (number)
  6. **Branding** — logo_url, favicon_url, primary_color (color picker), company_name, footer_text
  7. **Email** — smtp_host, smtp_port, smtp_user, smtp_password (password field), mail_from_name, mail_from_address
  8. **Notifications** — toggles for notify_new_order, notify_payment, notify_service_suspend, notify_ticket

**Alpine.js Implementation:**
```blade
<div x-data="{ activeTab: 'general' }">
    <!-- Tab navigation -->
    <!-- Tab content (conditionally shown) -->
</div>
```

**Form Setup:**
- Method: POST to `/admin/settings`
- Input array format: `settings[key_name]`
- UpdateSettingRequest validates

**Pattern:** Each tab is a form section with fieldsets grouped by category

---

### 6. Customer Payment Detail
**File:** `resources/views/customer/payments/show.blade.php`

**Purpose:** Individual payment receipt/detail

**Key Components:**
- Large amount display with currency formatter
- Payment method badge + icon (prominent)
- Status badge
- Transaction reference (mono font, copyable)
- Paid at timestamp (if completed)
- Related invoice card (if exists) with link
- Notes (if present)
- Receipt download button (placeholder for future)
- Print button (native browser print)

**Layout:**
- Hero section: Amount + Method
- Details grid: Status, Reference, Date
- Related invoice card
- Notes (if any)
- Action buttons

**Controller Variables:**
```php
$payment    // Payment::with('invoice')->find($id)
```

---

## Component Usage Reference

### Form Components
```blade
<!-- Text input with error handling -->
<x-form-input name="site_name" label="Site Name" value="{{ $settings['site_name'] ?? '' }}" required />

<!-- Select dropdown -->
<x-form-select name="currency" label="Currency" :options="['KES' => 'KES', 'USD' => 'USD']" value="KES" />

<!-- Date input -->
<x-form-input name="from_date" type="date" label="From Date" />

<!-- Password input -->
<x-form-input name="smtp_password" type="password" label="Password" />

<!-- Textarea -->
<x-form-input name="notes" label="Notes" placeholder="Enter notes..." />
```

### Display Components
```blade
<!-- Currency formatter -->
<x-currency-formatter :amount="$payment->amount" currency="KES" />

<!-- Payment method badge -->
<x-payment-badge :method="$payment->payment_method" />

<!-- Status badge (multi-type) -->
<x-status-badge :status="$payment->status" type="payment" />

<!-- Payment method icon -->
<x-payment-method-icon :method="$payment->payment_method" class="w-6 h-6" />

<!-- Confirmation dialog -->
<x-confirmation-dialog 
    title="Demote User?" 
    message="This user will no longer be a reseller."
    confirmText="Demote"
    danger
    action="{{ route('admin.resellers.demote', $user) }}"
>
    Demote Reseller
</x-confirmation-dialog>
```

---

## Layout Structure

### Admin Layout
```blade
@extends('layouts.admin')
@section('title', 'Page Title')
@section('breadcrumb')
    <a href="/admin">Admin</a> / <span>Current Page</span>
@endsection
@section('content')
    <!-- Content here -->
@endsection
```

### Customer Layout
```blade
@extends('layouts.customer')
@section('title', 'Page Title')
@section('content')
    <!-- Content here -->
@endsection
```

---

## Design Patterns Used

### Table Pattern (Repeating)
```blade
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50">
                <tr>
                    <th class="px-6 py-4 text-left text-sm font-semibold">Column</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                @forelse ($items as $item)
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                        <td class="px-6 py-4">...</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="N" class="px-6 py-12 text-center">
                            <!-- Empty state with icon -->
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($items->hasPages())
        <div class="border-t border-slate-200 dark:border-slate-800 px-6 py-4">
            {{ $items->links() }}
        </div>
    @endif
</div>
```

### Card Pattern (Repeating)
```blade
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Title</h2>
    <!-- Content -->
</div>
```

### Form Section Pattern (For settings)
```blade
<fieldset>
    <legend class="text-sm font-medium text-slate-900 dark:text-white mb-4">Section Title</legend>
    <div class="space-y-4">
        <x-form-input ... />
        <x-form-select ... />
    </div>
</fieldset>
```

---

## Testing Checklist

For each view, test:
- [ ] Form validation (submit invalid data, see errors)
- [ ] Dark mode (toggle theme, verify colors)
- [ ] Responsive (mobile, tablet, desktop)
- [ ] Authorization (customer can't access admin views)
- [ ] Data display (verify all fields show correctly)
- [ ] Pagination (if applicable)
- [ ] Links (all route() calls work)
- [ ] Components render correctly

---

## Implementation Timeline

**Estimated time per view:**
- Simple list view: 20-30 minutes
- Detail view with sidebar: 30-40 minutes
- Form (create/edit): 30-40 minutes
- Tabbed form: 40-50 minutes

**Total: 4-6 hours for all 6 remaining views**

---

## Common Mistakes to Avoid

1. ❌ Hardcoding status values → ✅ Use components like `<x-status-badge>`
2. ❌ Inline styling → ✅ Use Tailwind classes with dark: variants
3. ❌ No dark mode → ✅ All views must have `dark:*` classes
4. ❌ Missing error states → ✅ Form components auto-handle validation
5. ❌ Circular routes → ✅ Double-check route() calls exist
6. ❌ N+1 queries → ✅ Use eager loading (with(), withCount())

---

## Next Steps

1. ✅ Backend complete (enums, policies, validation, controllers)
2. ✅ Components created (7 Blade components)
3. ✅ 3 views updated (admin/payments/index, show; customer/payments/index)
4. 🔜 Create 6 remaining views (estimated 4-6 hours)
5. 🔜 Register policies in AuthServiceProvider
6. 🔜 Test authorization on all routes
7. 🔜 Smoke test in browser (admin & customer flows)
8. 🔜 Deploy to production

---

## Code Quality Checklist

Before submitting each view:
- [ ] Uses components for consistency
- [ ] Dark mode classes on all elements
- [ ] Proper error handling (validation messages visible)
- [ ] No hardcoded strings (use enum labels, ternaries rare)
- [ ] Follows Tailwind grid/spacing patterns
- [ ] Pagination if applicable
- [ ] Empty states with icons
- [ ] Hover/transition effects

---

**Status:** Backend ready. Views in progress. No blockers.
