# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

**Talksasa Cloud** is a Laravel 11 web hosting billing and provisioning platform. It manages customers, products, services, invoices, payments, domains, and support tickets with role-based access (Admin, Reseller, Customer).

**Tech Stack**: Laravel 11, PHP 8.2+, MySQL 8.0+, Tailwind CSS, Alpine.js, Vite

---

## Setup & Development Commands

### Initial Setup
```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed  # Load demo data (products, services, invoices, payments, settings)
```

### Development Server
```bash
php artisan serve              # Backend on http://localhost:8000
npm run dev                    # Frontend assets (Vite, auto-reload)
```

### Production Build
```bash
composer install --no-dev
npm run build                  # Minified assets
php artisan optimize          # Cache routes, config
```

### Code Quality
```bash
php artisan pint              # Format code (Laravel Pint)
php artisan test              # Run all tests
php artisan test --filter=PaymentTest  # Run specific test
php artisan tinker            # Interactive shell (test models, logic)
```

### Database
```bash
php artisan migrate           # Run pending migrations
php artisan migrate:fresh     # Rollback & re-run all migrations
php artisan migrate:fresh --seed  # Fresh + demo data
php artisan db:seed --class=PaymentSeeder  # Specific seeder
php artisan make:migration create_table_name  # Create migration
php artisan make:model ModelName -m -f  # Model + migration + factory
```

### Cache & Optimization
```bash
php artisan cache:clear
php artisan view:cache        # Compile all Blade views
php artisan route:cache       # Cache route definitions
php artisan optimize
```

---

## Architecture Overview

### High-Level Flow

1. **Request Entry** → Routes (web.php, api.php) → Middleware (auth, role-based)
2. **Authorization** → Policies (permission checks before action)
3. **Validation** → FormRequest classes (input validation & type casting)
4. **Business Logic** → Controllers → Services (reusable business logic)
5. **Database** → Models (Eloquent) → Migrations (schema)
6. **Response** → Views/JSON (Blade templates, Resource classes)

### Role-Based Access Control

- **Admin**: Full platform access, can manage all resources
- **Reseller**: Manage own customers & branded pricing
- **Customer**: Access own services, invoices, domains, tickets

Enforced via:
- Middleware: `admin`, `reseller`, `customer` in routes
- Policies: `app/Policies/*` (Authorization gates)
- Scopes: Models have `byUser()`, `admin()` scopes for filtering

---

## Directory Structure & Key Components

### `app/Models/`
Core Eloquent models representing database entities:
- **User** (customer, admin, reseller)
- **Service** (customer service subscriptions)
- **Invoice**, **InvoiceItem**, **Payment** (billing)
- **Domain**, **DnsZone**, **DnsRecord** (domain management)
- **Product** (hosting products/plans)
- **Order**, **OrderItem** (shopping orders)
- **Ticket**, **TicketReply** (support)
- **ContainerDeployment**, **ContainerMetric** (container hosting)
- **Setting** (app configuration key-value store)

**Key Pattern**: All models use Eloquent relationships (`hasMany`, `belongsTo`, etc.). Check model files for available methods and scopes.

### `app/Http/Controllers/`
Request handlers organized by role:

**Admin Controllers** (`Admin/`):
- Manage customers, products, services, invoices, payments, settings, resellers
- Can create, update, delete resources
- Access all data

**Customer Controllers** (`Customer/`):
- Service browsing & ordering (tech stack selection, cart, checkout)
- Invoice & payment management
- Domain management & transfer
- Container/server management (start/stop/logs/metrics)
- Support tickets

**Reseller Controllers** (`Reseller/`):
- Customer management
- Product catalog (custom pricing)
- Domain pricing overrides

**Auth Controllers** (`Auth/`):
- Login, register, password reset
- Email verification
- Two-factor authentication (SMS-based)

### `app/Services/`
Reusable business logic (not tied to HTTP):
- **PaymentGateway/** - M-Pesa, Stripe, PayPal integration
- **Provisioning/** - Container/server deployment, SSH commands
- **SSH/** - Direct SSH execution for server management
- **CurrencyConversionService** - Exchange rate calculation
- **DomainTransferService** - Domain transfer workflows
- **InvoicePdfService** - PDF generation (DomPDF)
- **MpesaService** - M-Pesa payment processing
- **TwoFactorService** - SMS-based 2FA logic
- **CreditService** - Customer credit/wallet system
- **NotificationService** - SMS alerts

### `app/Enums/`
Type-safe status & method definitions:
- **PaymentStatus**: pending, completed, failed, reversed
- **PaymentMethod**: mpesa, stripe, paypal, manual, wallet
- **InvoiceStatus**: draft, unpaid, paid, overdue, cancelled
- **ServiceStatus**: active, pending, provisioning, suspended, terminated, failed

Usage: `PaymentStatus::Completed` instead of string `'completed'` (prevents typos, enables IDE autocomplete).

### `app/Policies/`
Authorization logic (who can do what):
- **PaymentPolicy** - Only owner can view own payments
- **ResellerPolicy** - Admin-only reseller actions
- **SettingPolicy** - Admin-only settings
- **ServicePolicy** - Owner/admin can manage service

Pattern: `$this->authorize('view', $payment)` in controllers throws 403 if denied.

### `app/Http/Requests/`
Form validation & data transformation:
- **StorePaymentRequest**, **UpdatePaymentRequest**
- **UpdateSettingRequest**
- Auto-validates before controller receives data
- Centralized validation rules & messages

### `resources/views/`
Blade templates organized by role:
- `admin/` - Admin dashboard views
- `customer/` - Customer portal views
- `reseller/` - Reseller views
- `components/` - Reusable Blade components

**Components** (use with `<x-component-name />`):
- `payment-badge` - Color-coded payment method
- `status-badge` - Status indicators (payment, invoice, service)
- `currency-formatter` - KES currency display
- `data-table` - Sortable/filterable tables
- `modal` - Reusable modal dialogs

### `database/migrations/`
Database schema definitions. Always use migrations for schema changes:
```bash
php artisan make:migration add_field_to_table
```

### `database/seeders/`
Demo data generators. Run with `php artisan db:seed`:
- ProductSeeder (products with pricing)
- ServiceSeeder (customer service subscriptions)
- PaymentSeeder (sample payments)
- SettingSeeder (default configuration)

### `tests/`
PHPUnit tests:
- `Feature/` - End-to-end controller/route tests
- `Unit/` - Model methods, enums, helpers

Run: `php artisan test`

---

## Key Patterns & Conventions

### Model Relationships
Models define relationships in the model file:
```php
class User extends Model {
    public function services() { return $this->hasMany(Service::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }
}
```

When querying, use eager loading to avoid N+1:
```php
$users = User::with('services', 'invoices')->get();  // Good
$users = User::all(); foreach ($user->services) // Bad - loads on each iteration
```

### Authorization
Always authorize before acting on a resource:
```php
$payment = Payment::find($id);
$this->authorize('view', $payment);  // Throws 403 if not owner
```

### Validation
Use FormRequest for type-safe validation:
```php
public function store(StorePaymentRequest $request) {
    // $request->validated() has been validated
    $payment = Payment::create($request->validated());
}
```

### Services for Business Logic
Don't put complex logic in controllers. Use services:
```php
// In controller:
$service = new MpesaService();
$result = $service->processPayment($invoice, $phone);

// Service handles the actual payment logic
```

### Type Safety with Enums
Use enums for status/method fields:
```php
$payment->status = PaymentStatus::Completed;  // Not 'completed' string
if ($payment->status === PaymentStatus::Completed) { ... }
```

### Blade Components
Reuse Blade components instead of repeating HTML:
```blade
<x-status-badge :status="$payment->status" type="payment" />
<x-currency-formatter :amount="$invoice->total" currency="KES" />
```

---

## Development Workflow

### Adding a New Feature

1. **Create Migration** (if adding DB table/column):
   ```bash
   php artisan make:migration add_field_to_table
   ```

2. **Update Model** (add relationships, casts, scopes):
   ```php
   class Payment extends Model {
       protected $casts = ['status' => PaymentStatus::class];
       public function scopeByUser() { ... }
   }
   ```

3. **Create Controller** (route handlers):
   ```bash
   php artisan make:controller Admin/PaymentController
   ```

4. **Create Policy** (authorization):
   ```bash
   php artisan make:policy PaymentPolicy --model=Payment
   ```

5. **Create FormRequest** (validation):
   ```bash
   php artisan make:request StorePaymentRequest
   ```

6. **Add Routes** (in `routes/web.php`):
   ```php
   Route::resource('admin/payments', Admin\PaymentController::class);
   ```

7. **Create Views** (Blade templates):
   ```blade
   <!-- resources/views/admin/payments/index.blade.php -->
   ```

8. **Write Tests**:
   ```bash
   php artisan make:test PaymentTest --feature
   ```

### Making a Database Change

Always use migrations, never alter schema manually:

```bash
php artisan make:migration add_status_to_payments_table
```

Edit the migration file:
```php
public function up() {
    Schema::table('payments', function (Blueprint $table) {
        $table->enum('status', ['pending', 'completed'])->default('pending');
    });
}
```

Run: `php artisan migrate`

### Debugging

- **Tinker** (interactive shell): `php artisan tinker`
- **Logging**: Use `\Log::info()`, `\Log::error()` (saved in `storage/logs/`)
- **Laravel Debugbar**: Included, shows queries/views/requests
- **Browser Console**: Check for JS errors

---

## Important Files & Their Purpose

| File | Purpose |
|------|---------|
| `routes/web.php` | All web routes (define endpoints here) |
| `config/auth.php` | Authentication configuration (guards, providers) |
| `config/database.php` | Database connection settings |
| `.env` | Environment variables (API keys, DB credentials) |
| `app/Providers/AuthServiceProvider.php` | Register policies & gates |
| `app/Providers/AppServiceProvider.php` | Register service containers, boot logic |
| `app/Http/Middleware/` | Request preprocessing (auth, roles) |

---

## Payment Gateway Integration

Three payment methods are integrated:

### M-Pesa (Kenya mobile money)
- **Service**: `MpesaService` handles Safaricom API
- **Flow**: Customer initiates → STK push to phone → verify callback
- **Webhook**: POST `/mpesa/callback` (public, processes payment confirmation)

### Stripe (Credit/Debit cards)
- **Flow**: Customer → Stripe checkout page → return to success/cancel
- **Routes**: `/invoices/{invoice}/payment/stripe/success|cancel`

### PayPal
- **Flow**: Customer → PayPal page → return to success/cancel
- **Routes**: `/invoices/{invoice}/payment/paypal/success|cancel`

### Manual Payment
- **Flow**: Customer submits payment proof → Admin approves
- **Route**: `/invoices/{invoice}/payment/manual`

---

## Deployment Notes

### Production Checklist
```bash
# Before deploying to production:
php artisan optimize           # Cache routes/config
npm run build                  # Minified frontend
php artisan migrate --force    # Database schema
php artisan db:seed            # If first deploy, seed demo data
```

### Environment Configuration
Key `.env` variables:
- `APP_DEBUG=false` (never true in production)
- `APP_KEY=` (generated by `php artisan key:generate`)
- `DB_*` (database credentials)
- `MPESA_*`, `STRIPE_*`, `PAYPAL_*` (payment credentials)
- `MAIL_*` (email configuration)
- `SMS_API_KEY` (SMS service)

---

## Testing

### Unit Tests (Model logic, helpers)
```bash
php artisan test tests/Unit/Models/PaymentTest.php
```

### Feature Tests (Routes, controllers)
```bash
php artisan test tests/Feature/PaymentTest.php
```

### All Tests
```bash
php artisan test
```

---

## Common Tasks

### Add a Payment Method
1. Add enum value in `app/Enums/PaymentMethod.php`
2. Update `label()`, `icon()`, `color()` methods
3. Add controller logic in `Customer/PaymentController.php`
4. Component auto-updates everywhere it's used

### Change Authorization Rules
1. Edit `app/Policies/PaymentPolicy.php`
2. Test with `$this->authorize('action', $resource)`

### Add a Settings Field
1. Update admin settings form (add input field)
2. Settings auto-save to DB via `Setting` model
3. Retrieve with `setting('key_name')`

### Export Invoice as PDF
- Already built-in: `app/Services/InvoicePdfService`
- Called from `Customer/InvoiceController@download`

---

## Troubleshooting

**"Call to undefined function setting()"**
- Helper is in `app/Helpers/helpers.php`
- Ensure `composer dump-autoload` was run

**"RouteNotFoundException"**
- Check route is defined in `routes/web.php`
- Route name must match `@name('route.name')` in controller

**"SQLSTATE[HY000]: General error"**
- Run `php artisan migrate:fresh --seed`
- Check `.env` database credentials

**Payment webhook not working**
- Check `/mpesa/callback` route is public (no auth middleware)
- Verify API credentials in `.env`
- Check logs: `storage/logs/laravel.log`

---

## Documentation References

Detailed docs in `docs/`:
- `QUICK_START.md` - Next steps after setup
- `PROJECT_STRUCTURE.md` - Full architecture details
- `IMPLEMENTATION_GUIDE.md` - Feature implementation patterns
- `SECURITY.md` - Security practices
- `DEPLOYMENT_READY.md` - Production deployment guide
