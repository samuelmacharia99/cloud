# Talksasa Cloud - Project Structure & Implementation Plan

## Phase 5: Production-Ready Implementation (Payments, Resellers, Settings)

### Directory Structure

```
app/
├── Enums/
│   ├── PaymentStatus.php          # pending, completed, failed, reversed
│   ├── PaymentMethod.php          # mpesa, card, bank_transfer, wallet, manual
│   ├── InvoiceStatus.php          # draft, unpaid, paid, overdue, cancelled
│   └── ServiceStatus.php          # active, pending, provisioning, suspended, terminated, failed
├── Http/
│   ├── Controllers/
│   │   ├── Admin/
│   │   │   ├── PaymentController.php       # Payment CRUD + reconciliation
│   │   │   ├── ResellerController.php      # Reseller management + promote/demote
│   │   │   ├── SettingController.php       # Settings panel (grouped tabs)
│   │   │   └── ServiceController.php       # (updated) Service provisioning actions
│   │   └── Customer/
│   │       ├── PaymentController.php       # Customer payment portal
│   │       ├── InvoiceController.php       # (updated) Invoice detail view
│   │       └── OrderController.php         # (updated) Order detail view
│   ├── Requests/
│   │   ├── StorePaymentRequest.php         # Payment creation validation
│   │   ├── UpdatePaymentRequest.php        # Payment update validation
│   │   ├── UpdateSettingRequest.php        # Settings batch update
│   │   ├── PromoteResellerRequest.php      # (optional) Reseller promotion
│   │   └── ProvisionServiceRequest.php     # Service provisioning request
│   └── Resources/
│       ├── PaymentResource.php             # Payment API serialization
│       ├── ResellerResource.php            # Reseller API serialization
│       └── InvoiceResource.php             # Invoice API serialization
├── Models/
│   ├── Enums/ (via app/Enums/)
│   ├── Payment.php                 # (updated) with payment_method, currency
│   ├── User.php                    # (updated) is_reseller scope
│   ├── Setting.php                 # (updated) setValue(), getValue() helpers
│   ├── Service.php                 # (updated) provisioning actions
│   └── Invoice.php                 # (unchanged but related)
├── Policies/
│   ├── PaymentPolicy.php           # User can only view own payments
│   ├── ResellerPolicy.php          # Admin-only promote/demote
│   ├── SettingPolicy.php           # Admin-only settings
│   └── ServicePolicy.php           # Authorization for service actions
└── Traits/
    └── SerializesPaymentMethods.php # Reusable method for payment method badges

resources/
├── views/
│   ├── admin/
│   │   ├── payments/
│   │   │   ├── index.blade.php     # Payment list with filters
│   │   │   ├── show.blade.php      # Payment detail + receipt
│   │   │   ├── create.blade.php    # Manual payment creation form
│   │   │   └── edit.blade.php      # Payment status/notes update
│   │   ├── resellers/
│   │   │   ├── index.blade.php     # Reseller list with stats
│   │   │   └── show.blade.php      # Reseller detail (overview/services/customers tabs)
│   │   ├── settings/
│   │   │   └── index.blade.php     # 8-tab settings panel (Alpine.js)
│   │   ├── services/
│   │   │   ├── index.blade.php     # (updated) Services list
│   │   │   ├── show.blade.php      # (updated) Service detail + provisioning actions
│   │   │   └── partials/
│   │   │       ├── status-timeline.blade.php
│   │   │       └── action-buttons.blade.php
│   │   ├── invoices/
│   │   │   ├── index.blade.php     # (updated) Invoices list
│   │   │   └── show.blade.php      # (updated) Invoice detail + payments
│   │   ├── payments/ (duplication - see above)
│   │   └── layouts/
│   │       └── app.blade.php       # Main admin layout
│   ├── customer/
│   │   ├── payments/
│   │   │   ├── index.blade.php     # Payment list (my payments)
│   │   │   └── show.blade.php      # Payment detail page
│   │   ├── invoices/
│   │   │   ├── index.blade.php     # (updated) Invoice list
│   │   │   └── show.blade.php      # (updated) Invoice detail + payment options
│   │   ├── orders/
│   │   │   ├── index.blade.php     # (updated) Order list
│   │   │   └── show.blade.php      # (updated) Order detail
│   │   ├── services/
│   │   │   ├── index.blade.php     # (updated) My services
│   │   │   └── show.blade.php      # (updated) Service detail + manage
│   │   └── layouts/
│   │       └── app.blade.php       # Main customer layout
│   └── components/
│       ├── payment-badge.blade.php         # Payment method badge
│       ├── status-badge.blade.php          # (updated) Status badges for all entities
│       ├── currency-formatter.blade.php    # Currency display with KES symbol
│       ├── payment-method-icon.blade.php   # Payment method SVG icons
│       ├── modal.blade.php                 # Reusable modal component
│       ├── form-input.blade.php            # Form field wrapper
│       ├── form-select.blade.php           # Select field wrapper
│       ├── form-textarea.blade.php         # Textarea wrapper
│       ├── confirmation-dialog.blade.php   # Alpine.js confirmation
│       ├── table-pagination.blade.php      # Table with pagination
│       └── data-table.blade.php            # Advanced data table (sortable, filterable)

database/
├── migrations/
│   └── 2026_04_02_070000_add_reseller_pricing_table.php  # Reseller pricing overrides (future)
└── seeders/
    └── (all completed - ProductSeeder, ServiceSeeder, etc.)

tests/
├── Feature/
│   ├── PaymentTest.php             # Payment CRUD + authorization
│   ├── ResellerTest.php            # Reseller promotion/demotion
│   ├── SettingTest.php             # Settings update + validation
│   └── ServiceProvisioningTest.php # Service status transitions
└── Unit/
    ├── Models/
    │   ├── PaymentTest.php         # Payment model methods
    │   └── UserTest.php            # User scopes
    └── Enums/
        └── PaymentStatusTest.php   # Enum logic
```

## Implementation Order

### Part 1: Foundations (Enums, Traits, Policies)
1. Create Enums (PaymentStatus, PaymentMethod, InvoiceStatus, ServiceStatus)
2. Create Authorization Policies
3. Create Request Validation classes

### Part 2: Backend Controllers & Resources
4. Update Payment Model & Controller
5. Create Reseller Controller
6. Create Settings Controller
7. Customer Payment Controller

### Part 3: Blade Components (Reusable UI)
8. Payment method badge component
9. Status badge component (multi-type)
10. Currency formatter component
11. Confirmation dialog component
12. Modal component
13. Data table component

### Part 4: Admin Views
14. Admin payments (index, show, create, edit)
15. Admin resellers (index, show)
16. Admin settings (tabbed form)

### Part 5: Customer Views
17. Customer payments (index, show)
18. Customer invoices (updated)
19. Customer orders (updated)

### Part 6: Navigation & Polish
20. Sidebar navigation updates
21. CSS polish & dark mode verification
22. Form error handling
23. Loading states & transitions

## Key Principles

- **Type Safety**: Use Enums for all status/method fields
- **Authorization**: Policies enforce user/role boundaries
- **Validation**: FormRequest classes validate all inputs
- **Reusability**: Blade components for payment badges, status badges
- **Dark Mode**: All views support Tailwind dark:* utilities
- **Accessibility**: ARIA labels, semantic HTML, focus management
- **Performance**: Eager loading, pagination, query optimization
- **Future-Proofing**: Audit logging hooks, webhook structure ready
