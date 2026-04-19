# Talksasa Cloud - Modern Hosting Billing Platform

A clean, modern, and scalable web hosting billing and provisioning platform built with Laravel 12, Livewire, and Tailwind CSS.

## Features

- **Admin Dashboard** - Overview of customers, services, invoices, and support tickets
- **Customer Dashboard** - Manage services, view invoices, domains, and support tickets
- **Product Management** - Create and manage hosting products with flexible pricing
- **Service Management** - Provision and manage customer services
- **Billing System** - Invoice generation, payment tracking, and outstanding balance management
- **Domain Management** - Track domains with DNS zone management
- **Support Ticketing** - Customer support with ticket tracking
- **Modern UI** - Premium SaaS-style interface with dark mode support
- **Responsive Design** - Works seamlessly on desktop, tablet, and mobile
- **Reseller-Ready Architecture** - Foundation for multi-tier reseller support

## Tech Stack

- **Backend**: Laravel 12, PHP 8.3+
- **Frontend**: Blade, Livewire, Tailwind CSS, Alpine.js
- **Database**: MySQL / MariaDB
- **Build Tool**: Vite
- **Authentication**: Laravel Breeze

## Quick Start

### Prerequisites

- PHP 8.3 or higher
- Composer
- Node.js 18+
- MySQL 8.0+ or MariaDB 10.4+

### Installation

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd talksasa-cloud
   ```

2. **Install PHP dependencies**
   ```bash
   composer install
   ```

3. **Install Node dependencies**
   ```bash
   npm install
   ```

4. **Create environment file**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

5. **Configure database**
   Update `.env` with your database credentials:
   ```
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=talksasa_cloud
   DB_USERNAME=root
   DB_PASSWORD=
   ```

6. **Run migrations**
   ```bash
   php artisan migrate
   ```

7. **Seed sample data (optional)**
   ```bash
   php artisan db:seed
   ```

8. **Build frontend assets**
   ```bash
   npm run build
   ```

### Development

1. **Start development server**
   ```bash
   php artisan serve
   ```

2. **Start Vite dev server (in another terminal)**
   ```bash
   npm run dev
   ```

Visit `http://localhost:8000` to access the application.

## Project Structure

```
talksasa-cloud/
├── app/
│   ├── Models/              # Eloquent models
│   ├── Http/
│   │   ├── Controllers/     # Route controllers
│   │   └── Middleware/      # HTTP middleware
│   ├── Policies/            # Authorization policies
│   ├── Actions/             # Service classes
│   └── Providers/           # Service providers
├── database/
│   ├── migrations/          # Database migrations
│   ├── seeders/             # Database seeders
│   └── factories/           # Model factories
├── resources/
│   ├── views/               # Blade templates
│   │   ├── layouts/         # Layout templates
│   │   ├── dashboard/       # Dashboard views
│   │   ├── products/        # Product views
│   │   ├── services/        # Service views
│   │   ├── invoices/        # Invoice views
│   │   └── tickets/         # Ticket views
│   ├── css/                 # Tailwind CSS
│   └── js/                  # JavaScript
├── routes/
│   ├── web.php              # Web routes
│   ├── api.php              # API routes
│   ├── auth.php             # Auth routes
│   └── console.php          # Console commands
├── config/                  # Configuration files
└── public/                  # Public assets
```

## Models & Relationships

### Core Models

- **User** - Customer/Admin accounts
- **Product** - Hosting products (plans)
- **Service** - Customer service subscriptions
- **Invoice** - Billing invoices
- **InvoiceItem** - Individual invoice line items
- **Payment** - Payment records
- **Domain** - Customer domains
- **DnsZone** - DNS management
- **DnsRecord** - Individual DNS records
- **Ticket** - Support tickets
- **TicketReply** - Support ticket responses
- **Setting** - Application settings

### Key Relationships

- User → Services (1:N)
- User → Invoices (1:N)
- User → Domains (1:N)
- User → Tickets (1:N)
- Service → Product (N:1)
- Invoice → InvoiceItems (1:N)
- Invoice → Payments (1:N)
- Domain → DnsZones (1:N)
- DnsZone → DnsRecords (1:N)
- Ticket → TicketReplies (1:N)

## Routes

### Public Routes
- `/` - Redirect to dashboard
- `/register` - User registration (Breeze)
- `/login` - User login (Breeze)

### Authenticated Routes
- `/dashboard` - Main dashboard (role-based)
- `/products` - Product catalog
- `/services` - Service management
- `/invoices` - Invoice management
- `/tickets` - Support tickets

### Admin Routes
- `/products/create` - Create new product
- `/services/create` - Create new service
- `/invoices/create` - Create new invoice

## Database Schema

All tables include proper:
- ✅ Timestamps (created_at, updated_at)
- ✅ Foreign key constraints
- ✅ Indexes on frequently queried columns
- ✅ Proper data types and constraints
- ✅ Soft deletes support (can be added)

## Authentication

Uses Laravel Breeze for authentication. Register new accounts or login with:
- Email
- Password

Admin accounts are flagged with `is_admin` boolean.

## Configuration

Key configuration files:

- `config/app.php` - Application settings
- `config/database.php` - Database configuration
- `config/auth.php` - Authentication settings
- `.env` - Environment variables

## Development Guidelines

### Code Style
- Follow Laravel conventions
- Use meaningful variable names
- Add docblock comments for complex logic
- Organize code into service classes

### Database
- Always write migrations in order
- Use foreign keys with appropriate constraints
- Add indexes to frequently queried columns
- Use soft deletes for audit trails

### Frontend
- Use Blade for templating
- Leverage Tailwind for styling
- Implement dark mode support
- Ensure responsive design

## Future Enhancements

- [ ] Livewire components for real-time updates
- [ ] Payment gateway integration
- [ ] DirectAdmin API integration
- [ ] Multi-tenant reseller support
- [ ] Advanced reporting and analytics
- [ ] Email notifications
- [ ] API rate limiting
- [ ] Two-factor authentication

## Contributing

1. Create a feature branch
2. Make your changes
3. Submit a pull request

## License

Proprietary - All rights reserved

## Support

For issues and questions, use the support ticketing system or contact the development team.
