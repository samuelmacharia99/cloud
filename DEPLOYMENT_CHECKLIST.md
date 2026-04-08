# Talksasa Cloud — Pre-Deployment Checklist

**Status:** Phase 1 Complete (Admin Billing & Operations)  
**Next Phase:** Resellers (scheduled after deployment)  
**Last Updated:** April 8, 2026

---

## ✅ What's Ready for Deployment

### 1. Core Platform Features
- ✅ User Authentication (login, register, password reset, email verification)
- ✅ Customer & Admin Dashboards (role-based routing)
- ✅ Product Management (with pricing, billing cycles, container templates)
- ✅ Service Management (lifecycle: pending → provisioning → active → suspended → terminated)
- ✅ Order & Invoicing System (complete workflow: order → invoice → payment → provisioning)
- ✅ Payment Gateway Integration (M-Pesa, Stripe, PayPal)
- ✅ Auto-Provisioning (triggered on payment, integrates with Docker/DirectAdmin)
- ✅ Admin Controls (complete CRUD, status management, analytics)

### 2. Infrastructure & Deployment
- ✅ 48 Database Migrations (all applied and tested)
- ✅ Container Deployment (Docker Compose, SSH orchestration, 5 templates)
- ✅ Node Management (container hosts, DirectAdmin hosts, monitoring)
- ✅ Cron Jobs (metrics collection, invoice generation, SSL renewal, health checks)
- ✅ Currency Management (14 currencies, exchange rates API, automatic updates)
- ✅ Dark Mode Support (full UI support across all views)

### 3. Code Quality
- ✅ Request Validation (form validation, enum-based payment methods)
- ✅ Authorization Policies (registered in AuthServiceProvider)
- ✅ Error Logging (comprehensive logging for payment, provisioning, infrastructure)
- ✅ Blade Components (reusable UI components: badges, icons, dialogs)

---

## 🚨 Critical Tasks Before Going Live

### Step 1: Environment Configuration (1 hour)

**1a. Create `.env` file** (copy from `.env.example` or below):

```bash
cp .env.example .env
```

**1b. Payment Gateway Credentials** — Get from provider dashboards:

```env
# M-Pesa (Safaricom Daraja API v2)
MPESA_CONSUMER_KEY=your_consumer_key
MPESA_CONSUMER_SECRET=your_consumer_secret
MPESA_BUSINESS_SHORT_CODE=174379  # For sandbox; use live Paybill for production
MPESA_PASS_KEY=your_passkey
MPESA_PRODUCTION=false  # Set to true when going live

# Stripe
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...

# PayPal
PAYPAL_CLIENT_ID=your_client_id
PAYPAL_CLIENT_SECRET=your_client_secret
PAYPAL_PRODUCTION=false  # Set to true when going live
PAYPAL_WEBHOOK_ID=your_webhook_id

# Application
APP_NAME=TalksasaCloud
APP_KEY=base64:...  # Run: php artisan key:generate
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=talksasa_cloud
DB_USERNAME=root
DB_PASSWORD=

# Email (for notifications)
MAIL_DRIVER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=465
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=noreply@talksasa.cloud
MAIL_FROM_NAME="Talksasa Cloud"

# Cron Job Scheduling
CRON_ENABLED=true
```

**1c. Run migrations:**

```bash
php artisan migrate --force
php artisan db:seed --class=CronJobSeeder
```

---

### Step 2: Payment Gateway Testing (2 hours)

**Test with sandbox credentials first:**

#### M-Pesa (Sandbox)
1. Create test account at https://developer.safaricom.co.ke
2. Get Consumer Key/Secret from OAuth 2.0 section
3. Create test Paybill (Business Short Code): Use 174379
4. Set test phone: 254708374149 or 254723456789
5. **Test Flow:**
   ```
   Order → Pay Invoice → Select M-Pesa
   → Enter test phone → STK shows on test device
   → Enter test PIN (1234 in sandbox) → Payment confirmed
   → Invoice marked paid → Service auto-provisions
   ```

#### Stripe (Test Mode)
1. Create account at https://dashboard.stripe.com
2. Get test keys from Developers > API Keys
3. Configure webhook endpoint: `https://yourdomain.com/webhooks/stripe`
4. **Test Flow:**
   ```
   Order → Pay Invoice → Select Stripe
   → Redirected to checkout → Use card: 4242 4242 4242 4242
   → Expiry: any future date, CVC: any 3 digits → Checkout succeeds
   → Invoice marked paid → Service auto-provisions
   ```

#### PayPal (Sandbox)
1. Create account at https://developer.paypal.com
2. Create REST API signature app
3. Get Client ID/Secret
4. Create webhook for `https://yourdomain.com/webhooks/paypal`
5. **Test Flow:**
   ```
   Order → Pay Invoice → Select PayPal
   → Redirected to sandbox approval
   → Login with sandbox buyer account → Approve payment
   → Invoice marked paid → Service auto-provisions
   ```

---

### Step 3: Container Deployment Validation (1 hour)

**Verify container provisioning system:**

```bash
# Check container templates are seeded
php artisan tinker
>>> App\Models\ContainerTemplate::count()  // Should return 5

# Check nodes are configured
>>> App\Models\Node::where('type', 'container_host')->count()  // Should return ≥1

# Test provisioning command
php artisan service:provision 1  // Returns exit code 0 on success
```

**Check provisioning logs:**
```bash
tail -f storage/logs/laravel.log | grep -i "provisioning\|service"
```

---

### Step 4: Email & Notifications (30 mins)

**Configure SMTP:**

Choose one provider (recommended: Mailtrap for staging, SendGrid/AWS SES for production):

```env
# Mailtrap (testing)
MAIL_DRIVER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=465
MAIL_USERNAME=<inbox_id>
MAIL_PASSWORD=<inbox_password>

# OR SendGrid (production)
MAIL_DRIVER=sendgrid
SENDGRID_API_KEY=your_api_key
```

**Test email sending:**
```bash
php artisan tinker
>>> Mail::send('emails.payment-received', ['payment' => $payment], function($msg) {
    $msg->to('test@example.com');
});
```

---

### Step 5: Cron Jobs & Scheduling (30 mins)

**Register cron jobs** (already seeded, just verify):

```bash
# List all registered cron jobs
php artisan cron:list

# Should show:
# - cron:collect-container-metrics (*/5 * * * *)
# - cron:renew-ssl-certificates (0 2 * * *)
# - cron:generate-invoices (0 0 * * *)
# - cron:check-node-health (*/5 * * * *)
```

**Linux cron setup:**
```bash
# Add to crontab
*/1 * * * * cd /path/to/talksasa-cloud && php artisan schedule:run >> /dev/null 2>&1
```

---

### Step 6: SSL & HTTPS Configuration (30 mins)

**Required for payment gateways:**

```bash
# Install SSL certificate (Let's Encrypt recommended)
sudo apt-get install certbot python3-certbot-nginx

# Generate certificate
sudo certbot certonly --nginx -d yourdomain.com

# Configure nginx to use SSL (see nginx config example below)

# Auto-renewal
sudo systemctl enable certbot.timer
sudo systemctl start certbot.timer
```

**nginx configuration example:**
```nginx
server {
    listen 443 ssl http2;
    server_name yourdomain.com;

    ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;

    location / {
        proxy_pass http://localhost:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}

# Redirect HTTP to HTTPS
server {
    listen 80;
    server_name yourdomain.com;
    return 301 https://$server_name$request_uri;
}
```

---

### Step 7: Admin Account Setup (15 mins)

```bash
# Create admin user
php artisan tinker
>>> User::create([
    'name' => 'Admin',
    'email' => 'admin@talksasa.cloud',
    'password' => bcrypt('secure_password'),
    'is_admin' => true,
    'email_verified_at' => now(),
]);
```

---

### Step 8: Database Backups & Monitoring (30 mins)

**Configure automated backups:**

```bash
# Option 1: Laravel Backup package
composer require spatie/laravel-backup

# Option 2: mysqldump script
#!/bin/bash
mysqldump -u root talksasa_cloud | gzip > /backups/db-$(date +%Y%m%d).sql.gz
```

**Set up monitoring alerts:**
- Error rate exceeds threshold
- Payment webhook failures
- Container provisioning failures
- Node offline alerts

---

## 📋 Deployment Verification Checklist

Before marking as "ready to deploy," verify:

- [ ] All `.env` variables configured (APP_KEY generated)
- [ ] Database migrations run successfully
- [ ] CronJobSeeder executed
- [ ] Payment gateway credentials entered
- [ ] Webhook URLs configured in provider dashboards
- [ ] Email SMTP configured and tested
- [ ] SSL certificate installed and auto-renewal enabled
- [ ] Nginx/Apache configured for HTTPS
- [ ] Admin account created
- [ ] Cron jobs registered in system crontab
- [ ] Backups configured
- [ ] Logs monitored (check `storage/logs/laravel.log`)
- [ ] Test payment flow with M-Pesa (if available)
- [ ] Test payment flow with Stripe test card
- [ ] Verify services auto-provision after payment
- [ ] Admin dashboard loads without errors
- [ ] Customer dashboard loads without errors
- [ ] Invoice PDF generation works
- [ ] Email notifications sending successfully

---

## 🚀 Go-Live Steps

### 1. Switch Payment Gateways to Production

```env
MPESA_PRODUCTION=true
PAYPAL_PRODUCTION=true
STRIPE_SECRET_KEY=sk_live_...  # Use production keys
STRIPE_PUBLISHABLE_KEY=pk_live_...
APP_DEBUG=false
```

### 2. Update Webhook URLs

Update callback URLs in provider dashboards:
- **M-Pesa:** Callback URL in code (no dashboard change needed)
- **Stripe:** https://yourdomain.com/webhooks/stripe
- **PayPal:** https://yourdomain.com/webhooks/paypal

### 3. Production Database

```bash
# Create fresh production database
mysql -u root -p -e "CREATE DATABASE talksasa_cloud_prod;"

# Run migrations
php artisan migrate --env=production

# Seed essential data (NOT test data)
php artisan db:seed --class=CurrencySeeder
php artisan db:seed --class=ContainerTemplateSeeder
php artisan db:seed --class=NodeSeeder
php artisan db:seed --class=CronJobSeeder
```

### 4. Verify Payment Processing

Test with **real small transactions** (e.g., KES 1 payment) before full launch.

### 5. Monitor First 24 Hours

Watch logs closely:
```bash
tail -f storage/logs/laravel.log

# Look for:
# - No database connection errors
# - No payment webhook failures
# - No provisioning exceptions
# - Normal cron job execution
```

---

## 🔄 Post-Deployment Support

### Critical Runbooks

**If payment webhooks stop:**
1. Check payment gateway dashboard for delivery logs
2. Verify webhook URLs are correct
3. Verify HTTPS certificate is valid
4. Check Laravel logs: `grep "webhook" storage/logs/laravel.log`
5. Test webhook manually: Use provider dashboard's "Resend" feature

**If services don't provision:**
1. Check service status: `Service::find($id)->status`
2. Check invoice payment status: paid?
3. Run provisioning manually: `php artisan service:provision $service_id`
4. Check provisioning logs: `grep "provisioning" storage/logs/laravel.log`

**If cron jobs don't run:**
1. Verify cron job is in system crontab: `crontab -l`
2. Check permissions: `/usr/bin/php` exists and is executable
3. Verify Laravel logs show cron execution
4. Restart cron: `sudo systemctl restart cron`

---

## 📞 Support Resources

- **M-Pesa API Docs**: https://developer.safaricom.co.ke
- **Stripe API Docs**: https://stripe.com/docs/api
- **PayPal API Docs**: https://developer.paypal.com/docs/api/overview
- **Laravel Docs**: https://laravel.com/docs
- **Docker Docs**: https://docs.docker.com

---

## Summary: What's Not Yet Required

These features are **planned for Phase 2 (Resellers)** and should NOT be developed before launch:

- ❌ Reseller account management
- ❌ Reseller commission tracking
- ❌ Reseller analytics dashboard
- ❌ Reseller custom branding

**Focus:** Get admin billing & operations (current Phase 1) fully deployed and stable. Then add reseller features in Phase 2.

