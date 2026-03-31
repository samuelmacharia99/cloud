# Talksasa Cloud - Laravel Implementation Plan

## Project Structure

```
laravel/
├── app/
│   ├── Models/              # Eloquent models
│   ├── Http/
│   │   ├── Controllers/    # Business logic
│   │   ├── Middleware/     # Auth, validation
│   │   └── Requests/       # Form requests
│   ├── Services/           # Service classes
│   ├── Jobs/               # Queued jobs
│   └── Console/
│       └── Commands/       # Artisan commands (cron)
├── database/
│   ├── migrations/         # Schema
│   └── seeders/            # Seed data
├── resources/
│   ├── views/              # Blade templates
│   └── css/                # Styling (Tailwind)
├── routes/
│   ├── api.php             # API routes (JWT auth)
│   └── web.php             # Web routes
└── storage/                # Logs, cache
```

## Implementation Phases

### Phase 1: Core Setup (In Progress)
- [x] Laravel installation
- [x] Environment configuration
- [ ] Database migrations (all tables)
- [ ] Models with relationships
- [ ] Authentication system (JWT + 2FA)
- [ ] API middleware & authorization
- [ ] Routes (API + Web)

### Phase 2: Frontend (Blade Templates)
- [ ] Layout components (header, sidebar)
- [ ] Dashboard home page
- [ ] All CRUD pages
- [ ] Forms with validation
- [ ] Tables with pagination & filters
- [ ] Charts using Laravel integration
- [ ] Dark theme CSS (Tailwind)

### Phase 3: Features
- [ ] Product management
- [ ] Domain management (registrar API)
- [ ] Service provisioning
- [ ] Invoice & payments (Stripe, PayPal, M-Pesa)
- [ ] Support tickets
- [ ] User management
- [ ] Tenant management
- [ ] Reporting

### Phase 4: Automation
- [ ] Cron job scheduler
- [ ] CRON_SECRET endpoint (/api/cron/execute-public)
- [ ] Domain renewal background job
- [ ] Payment suspension background job
- [ ] DirectAdmin integration
- [ ] SMS/Email notifications

### Phase 5: Testing & Deployment
- [ ] Unit tests
- [ ] Integration tests
- [ ] API tests
- [ ] Deployment scripts
- [ ] Docker configuration
- [ ] GitHub Actions CI/CD

## Database Schema Summary

### Core Tables
1. **users** - User accounts (super admin, resellers, end users)
2. **tenants** - Multi-tenant: resellers and organizations
3. **products** - Service packages (Shared, VPS, Dedicated, Reseller, Domains)
4. **domains** - Domain registrations and management
5. **services** - Provisioned services for users
6. **invoices** - Billing records
7. **payments** - Payment transactions
8. **tickets** - Support tickets
9. **cron_job_logs** - Automation logging

### Supporting Tables
10. **settings** - Platform configuration
11. **sms_templates** - SMS message templates
12. **email_templates** - Email templates
13. **dns_zones** - Domain DNS zones
14. **dns_records** - DNS records
15. **registrars** - Registrar configurations
16. **nodes** - DirectAdmin nodes
17. **service_upgrades** - Service upgrade tracking
18. **two_factor_tokens** - 2FA tokens
19. **activity_logs** - Audit trail
20. **password_reset_tokens** - Password reset

## Models & Relationships

```
User (1) ──→ (M) Service
User (1) ──→ (M) Invoice
User (1) ──→ (M) Domain
User (1) ──→ (M) Ticket

Tenant (1) ──→ (M) User
Tenant (1) ──→ (M) Product

Product (1) ──→ (M) Service

Service (1) ──→ (M) Invoice
Service (1) ──→ (M) ServiceUpgrade

Invoice (1) ──→ (M) Payment

Domain (1) ──→ (M) DnsZone
DnsZone (1) ──→ (M) DnsRecord
```

## API Endpoints Summary

### Authentication
- `POST /api/auth/login` - Login
- `POST /api/auth/register` - Register
- `POST /api/auth/logout` - Logout
- `POST /api/auth/refresh` - Refresh token
- `POST /api/auth/2fa/verify` - 2FA verification

### Resources (Protected with JWT)
- `/api/products` - CRUD
- `/api/domains` - CRUD + check availability
- `/api/services` - List, show, suspend, upgrade
- `/api/invoices` - List, show, pay
- `/api/tickets` - CRUD + reply
- `/api/users` - Admin only
- `/api/tenants` - Admin only
- `/api/nodes` - Admin only
- `/api/reports` - Analytics

### Automation
- `POST /api/cron/execute-public` - External cron with CRON_SECRET header
- `POST /api/cron/execute` - Manual trigger (authenticated)
- `GET /api/cron/stats` - Job statistics
- `GET /api/cron/activity` - Activity log
- `GET /api/cron/recent` - Recent executions
- `GET /api/cron/health` - System health

## Command Structure (Cron Jobs)

```
php artisan domain:renew          # Renew expiring domains
php artisan payment:suspend       # Suspend for overdue payments
php artisan directadmin:sync      # Sync with DirectAdmin nodes
```

## Development Workflow

1. Create migrations → Run migrations
2. Create models → Define relationships
3. Create controllers → Business logic
4. Create routes → API/Web endpoints
5. Create requests → Validation
6. Create views → Blade templates
7. Create tests → Unit & integration
8. Deploy → GitHub Actions

## Next Steps
1. Complete all migrations
2. Create models and relationships
3. Set up authentication (Laravel Sanctum for API)
4. Build controllers
5. Create routes
6. Build Blade templates
