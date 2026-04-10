# Talksasa Cloud - Implementation Guide

## Project Overview

A modern, scalable web hosting billing and provisioning platform built with Laravel 12, featuring:
- Clean separation of concerns
- Premium SaaS-style UI with dark mode
- Complete billing system
- Service provisioning foundation
- Support ticketing system
- Reseller-ready architecture

## Architecture Overview

### Technology Stack

**Backend**
- Laravel 12
- PHP 8.3+
- MySQL/MariaDB
- Eloquent ORM
- Blade templating engine
Landing Page: http://localhost:8000
  - Login: http://localhost:8000/login                                                                                                                    
  - Admin Dashboard: After login (automatic for admin)
  - Customer Dashboard: After login (automatic for customer)                                                                                              
                     Role   │        Email         │ Password │                                                                                                          
  ├──────────┼──────────────────────┼──────────┤
  │ Admin    │ admin@talksasa.cloud │ password │                                                                                                          
  ├──────────┼──────────────────────┼──────────┤                                                                                                        
  │ Customer │ john@example.com     │ password │                                                                                                          
  └──────────┴──────────────────────┴──────────┘                                            

**Frontend**
- Blade (server-side templating)
- Tailwind CSS (utility-first CSS)
- Alpine.js (optional, for reactive components)
- Livewire (optional, for interactive components)

**Build Tools**
- Vite (for asset bundling)
- Node.js (for frontend dependencies)

**Authentication**
- Laravel Breeze (lightweight auth scaffolding)

## Directory Structure

```
talksasa-cloud/
├── app/
│   ├── Models/                    # Eloquent models (11 core models)
│   ├── Http/
│   │   ├── Controllers/           # Route handlers
│   │   └── Middleware/            # HTTP middleware
│   ├── Policies/                  # Authorization policies
│   ├── Actions/                   # Business logic (service classes)
│   └── Providers/
│       ├── AppServiceProvider.php
│       └── AuthServiceProvider.php
├── database/
│   ├── migrations/                # Schema definitions (11 migrations)
│   ├── seeders/                   # Data seeding
│   └── factories/                 # Model factories for testing
├── resources/
│   ├── views/
│   │   ├── layouts/               # Master layouts
│   │   │   ├── app.blade.php      # Main app layout
│   │   │   ├── sidebar.blade.php  # Navigation sidebar
│   │   │   └── navbar.blade.php   # Top navigation
│   │   ├── dashboard/             # Role-based dashboards
│   │   ├── products/              # Product management views
│   │   ├── services/              # Service management views
│   │   ├── invoices/              # Billing views
│   │   └── tickets/               # Support ticketing views
│   ├── css/
│   │   └── app.css                # Tailwind CSS imports
│   └── js/
│       ├── app.js                 # Main JS entry point
│       └── bootstrap.js           # Axios configuration
├── routes/
│   ├── web.php                    # Web routes
│   ├── api.php                    # API routes
│   ├── auth.php                   # Auth routes
│   └── console.php                # Console commands
├── config/                        # Configuration files
│   ├── app.php
│   ├── auth.php
│   └── database.php
├── public/
│   └── index.php                  # Application entry point
├── bootstrap/
│   ├── app.php                    # Laravel kernel configuration
│   └── providers.php              # Service provider registration
├── .env.example                   # Environment template
├── composer.json                  # PHP dependencies
├── package.json                   # Node.js dependencies
├── tailwind.config.js             # Tailwind configuration
├── vite.config.js                 # Vite bundler configuration
└── README.md                      # Quick start guide
```

## Core Models & Relationships

### 1. User
- **Purpose**: Customer/Admin accounts
- **Key Fields**: name, email, password, is_admin, is_reseller, status
- **Relations**:
  - `services()` - 1:N with Service
  - `invoices()` - 1:N with Invoice
  - `payments()` - 1:N with Payment
  - `tickets()` - 1:N with Ticket
  - `domains()` - 1:N with Domain

**Key Methods**:
- `isAdmin()` - Check if user is administrator
- `isReseller()` - Check if user is reseller
- `getOutstandingBalance()` - Calculate unpaid amount
- `getActiveServicesCount()` - Count active services

### 2. Product
- **Purpose**: Hosting plans/products offered
- **Key Fields**: name, slug, category, price, billing_cycle, features, setup_fee, is_active
- **Relations**:
  - `services()` - 1:N with Service
  - `invoiceItems()` - 1:N with InvoiceItem
- **Pricing**: Flexible billing cycles (monthly, quarterly, semi-annual, annual)

### 3. Service
- **Purpose**: Customer subscriptions to products
- **Key Fields**: user_id, product_id, name, status, billing_cycle, next_due_date, termination_date
- **Relations**:
  - `user()` - N:1 with User
  - `product()` - N:1 with Product
  - `invoiceItems()` - 1:N with InvoiceItem
- **Status**: active, suspended, terminated, cancelled

**Key Methods**:
- `isActive()`, `isSuspended()`, `isTerminated()` - Status checks

### 4. Invoice
- **Purpose**: Customer billing documents
- **Key Fields**: user_id, invoice_number, status, due_date, paid_date, subtotal, tax, total
- **Relations**:
  - `user()` - N:1 with User
  - `items()` - 1:N with InvoiceItem
  - `payments()` - 1:N with Payment
- **Status**: unpaid, paid, overdue, cancelled

**Key Methods**:
- `isPaid()` - Check if fully paid
- `isOverdue()` - Check if past due
- `getAmountPaid()` - Sum of completed payments
- `getAmountRemaining()` - Balance remaining

### 5. InvoiceItem
- **Purpose**: Line items on invoices
- **Key Fields**: invoice_id, service_id, product_id, description, quantity, unit_price, amount
- **Relations**:
  - `invoice()` - N:1 with Invoice
  - `service()` - N:1 with Service (nullable)
  - `product()` - N:1 with Product

### 6. Payment
- **Purpose**: Payment records
- **Key Fields**: user_id, invoice_id, amount, gateway, transaction_id, status
- **Relations**:
  - `user()` - N:1 with User
  - `invoice()` - N:1 with Invoice
- **Status**: pending, completed, failed, refunded
- **Gateways**: Stripe, PayPal, bank transfer, etc.

### 7. Domain
- **Purpose**: Customer domain management
- **Key Fields**: user_id, name, registrar, status, expires_at, auto_renew, nameserver_1, nameserver_2
- **Relations**:
  - `user()` - N:1 with User
  - `dnsZones()` - 1:N with DnsZone
- **Status**: active, expired, suspended

**Key Methods**:
- `isActive()` - Check if active and not expired
- `isExpired()` - Check if expired
- `daysUntilExpiry()` - Days until expiration

### 8. DnsZone
- **Purpose**: DNS zone management
- **Key Fields**: domain_id, name, status
- **Relations**:
  - `domain()` - N:1 with Domain
  - `records()` - 1:N with DnsRecord

### 9. DnsRecord
- **Purpose**: Individual DNS records
- **Key Fields**: dns_zone_id, name, type, content, priority, ttl
- **Types**: A, AAAA, CNAME, MX, TXT, NS, SOA
- **Relations**:
  - `dnsZone()` - N:1 with DnsZone

### 10. Ticket
- **Purpose**: Support ticket system
- **Key Fields**: user_id, title, description, status, priority, assigned_to, resolved_at
- **Relations**:
  - `user()` - N:1 with User
  - `assignee()` - N:1 with User
  - `replies()` - 1:N with TicketReply
- **Status**: open, in_progress, on_hold, closed
- **Priority**: low, medium, high, urgent

### 11. TicketReply
- **Purpose**: Responses to support tickets
- **Key Fields**: ticket_id, user_id, message, is_staff_reply
- **Relations**:
  - `ticket()` - N:1 with Ticket
  - `user()` - N:1 with User

### 12. Setting
- **Purpose**: Application configuration
- **Key Fields**: key (primary), value, description
- **No Timestamps**: Configuration rarely needs audit trail

**Static Methods**:
- `getValue($key, $default)` - Get setting value
- `setValue($key, $value)` - Set/update setting

## Controllers & Routes

### Route Structure

```
GET/POST /dashboard              → DashboardController (role-based)
GET      /products              → ProductController@index
GET      /products/{id}         → ProductController@show
POST     /products              → ProductController@store (admin)
GET      /products/{id}/edit    → ProductController@edit (admin)
PUT      /products/{id}         → ProductController@update (admin)
DELETE   /products/{id}         → ProductController@destroy (admin)

GET      /services              → ServiceController@index
GET      /services/{id}         → ServiceController@show
POST     /services              → ServiceController@store (admin)
PUT      /services/{id}         → ServiceController@update (admin)
DELETE   /services/{id}         → ServiceController@destroy (admin)

GET      /invoices              → InvoiceController@index
GET      /invoices/{id}         → InvoiceController@show
POST     /invoices              → InvoiceController@store (admin)
PUT      /invoices/{id}         → InvoiceController@update
DELETE   /invoices/{id}         → InvoiceController@destroy (admin)

GET      /tickets               → TicketController@index
GET      /tickets/{id}          → TicketController@show
POST     /tickets               → TicketController@store
POST     /tickets/{id}/reply    → TicketController@reply
POST     /tickets/{id}/close    → TicketController@close
```

### Controller Implementation Details

**DashboardController**
- Routes to admin or customer dashboard based on user role
- Admin sees: metrics, revenue, open tickets, recent invoices
- Customer sees: active services, outstanding balance, upcoming invoices, tickets

**ProductController**
- CRUD operations for products
- Admin-only creation/editing
- Features dynamic pricing with flexible billing cycles
- Supports featured description and feature list storage as JSON

**ServiceController**
- Provision/manage customer services
- Track service status and renewal dates
- Support for custom fields via JSON

**InvoiceController**
- Full billing lifecycle management
- Invoice generation and tracking
- Payment status tracking
- Overdue detection

**TicketController**
- Customer support request tracking
- Staff reply capability
- Priority and status management
- Automatic ticket closure

## Views & UI

### Layout System

**Base Layout** (`layouts/app.blade.php`)
- Responsive sidebar + main content area
- Centered padding (p-8) for comfortable reading
- Dark mode support via Tailwind

**Sidebar** (`layouts/sidebar.blade.php`)
- Branding logo/name
- Role-based navigation
- Active route highlighting
- Logout button

**Navbar** (`layouts/navbar.blade.php`)
- User information
- Responsive hamburger for mobile
- Dark mode indicator (placeholder for toggling)

### Page Designs

**Dashboard**
- Admin: 4 KPI cards, recent invoices table, open tickets count
- Customer: 3 stats (services, balance, domains), active services list, upcoming invoices, open tickets

**Products**
- Grid layout (responsive 1/2/3 columns)
- Product cards with pricing, features, and action buttons
- Detail page with full features and pricing breakdown

**Services**
- Table view with filtering capability
- Status badges (active/suspended/terminated)
- Quick access to invoice history

**Invoices**
- Comprehensive table view
- Status-based styling
- Full invoice detail page with printable layout
- Line item breakdown

**Tickets**
- Thread-style conversation view
- Priority and status indicators
- Staff/customer message differentiation
- Reply form for ongoing tickets

## UI Design Language

### Color Palette
- **Primary**: Blue (600/500 for buttons, 100/950 for backgrounds)
- **Success**: Emerald (for active/paid states)
- **Warning**: Amber (for unpaid/pending states)
- **Danger**: Red (for urgent/high priority)
- **Neutral**: Slate (50-950 spectrum)

### Components & Patterns

**Cards**
```html
<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
```

**Buttons**
- Primary: Blue background with white text
- Secondary: Border with slate text
- Danger: Red background for destructive actions

**Tables**
- Hover effects on rows
- Status badges with color coding
- Action links aligned right

**Forms**
- Full-width inputs with consistent padding
- Error messages in red
- Clear labeling with uppercased labels

**Typography**
- Headings: Xl-3xl with bold weight
- Body: Sm text with gray/slate colors
- Status/metadata: Xs uppercase labels

## Database Migrations

All migrations are ordered by timestamp and include:

1. **0001** - Users table (foundation)
2. **0001** - Products table
3. **0002** - Services table
4. **0003** - Invoices table
5. **0004** - Invoice items table
6. **0005** - Payments table
7. **0006** - Domains table
8. **0007** - DNS zones table
9. **0008** - DNS records table
10. **0009** - Tickets table
11. **0010** - Ticket replies table
12. **0011** - Settings table

### Key Database Features

- **Foreign Keys**: Enforced with appropriate cascade rules
- **Indexes**: On frequently queried columns (user_id, status, dates)
- **Constraints**: Unique invoices, email verification, proper enums
- **Timestamps**: automatic created_at/updated_at tracking

## Getting Started

### 1. Clone and Setup
```bash
git clone <repo>
cd talksasa-cloud
cp .env.example .env
php artisan key:generate
```

### 2. Configure Database
```bash
# Update .env with database credentials
nano .env
```

### 3. Install Dependencies
```bash
composer install
npm install
```

### 4. Run Migrations
```bash
php artisan migrate
```

### 5. Build Assets
```bash
npm run build    # Production
npm run dev      # Development with watch
```

### 6. Serve Application
```bash
php artisan serve    # Runs on http://localhost:8000
```

## Next Steps & Future Enhancements

### Immediate Tasks
1. **Authentication Setup**
   - Run `php artisan breeze:install blade`
   - Create admin/customer test accounts

2. **Admin Middleware**
   - Create `app/Http/Middleware/AdminMiddleware.php`
   - Register in bootstrap/app.php

3. **Authorization Policies**
   - Create policies for User, Service, Invoice, Ticket
   - Implement authorization checks in controllers

4. **Form Requests**
   - Create form request validation classes
   - Add to controllers for cleaner validation

### Short-term Features
- [ ] Payment gateway integration (Stripe, PayPal)
- [ ] Email notifications (invoice due, service expiring)
- [ ] PDF invoice generation
- [ ] Advanced search/filtering
- [ ] Bulk actions (services, invoices)
- [ ] CSV export functionality

### Medium-term Features
- [ ] DirectAdmin API integration
- [ ] Real-time notifications (Livewire)
- [ ] Email templates customization
- [ ] Automated renewal billing
- [ ] Advanced reporting dashboards
- [ ] SMS notifications

### Long-term Features
- [ ] Multi-tenant reseller system
- [ ] Affiliate program management
- [ ] Custom branding for resellers
- [ ] API for third-party integrations
- [ ] Mobile app (Flutter/React Native)
- [ ] Advanced analytics and BI

## Code Standards

### Models
- Use Eloquent relationships
- Implement accessor/mutator methods
- Add query scopes for common filters
- Use enums for status fields

### Controllers
- Single responsibility principle
- Use form requests for validation
- Leverage authorization policies
- Keep business logic in models/services

### Views
- DRY principle with components
- Consistent error messaging
- Accessible form elements
- Responsive by default

### Database
- Use migrations for schema changes
- Always include timestamps
- Add appropriate indexes
- Use foreign keys

## Performance Considerations

1. **Eager Loading**: Use `with()` to avoid N+1 queries
2. **Pagination**: Implement for large result sets
3. **Caching**: Consider caching products and settings
4. **Indexing**: Indexed status, user_id, and date columns
5. **Queue**: Consider async processing for emails/reports

## Security Considerations

1. **Authorization**: Use policies for resource access
2. **Input Validation**: Form requests validate all input
3. **CSRF Protection**: Enabled in middleware
4. **Password Security**: Laravel's hashing
5. **Data Sensitivity**: Hide sensitive fields in listings
6. **Rate Limiting**: Implement for API endpoints

## Development Workflow

### Local Development
```bash
npm run dev              # Watch assets
php artisan serve       # Start server
php artisan tinker      # REPL for testing
```

### Testing
```bash
php artisan test                    # Run tests
php artisan test --filter=UserTest  # Specific test
```

### Database Management
```bash
php artisan migrate:refresh         # Reset DB
php artisan migrate:rollback        # Revert changes
php artisan tinker                  # Query builder
```

## Deployment Considerations

1. Update `.env` for production
2. Set `APP_DEBUG=false` in production
3. Use database backups
4. Configure proper file permissions
5. Set up SSL/HTTPS
6. Configure mail service
7. Enable application caching
8. Set up automated backups

## Support & Resources

- **Laravel Docs**: https://laravel.com/docs
- **Tailwind CSS**: https://tailwindcss.com/docs
- **Laravel Breeze**: https://laravel.com/docs/breeze
- **Eloquent ORM**: https://laravel.com/docs/eloquent

---

**Built with ❤️ for modern hosting platforms**
