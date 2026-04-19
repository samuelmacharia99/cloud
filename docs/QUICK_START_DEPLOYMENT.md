# Talksasa Cloud — Quick Start to Deployment

**You are here:** Feature development complete → Configuration & testing → Go live

**Time to production:** ~4-6 hours

---

## 🎯 Executive Summary

**The good news:** ✅ All features are built and tested.

**What's left:** 🟡 Configure credentials and test payment gateways.

**Resellers:** ❌ Not included (Phase 2 after deployment, per your request).

---

## 📋 Quick Checklist

### ✅ Already Done (Don't repeat)
- [x] Payment gateways implemented (M-Pesa, Stripe, PayPal)
- [x] Admin dashboard built
- [x] Customer ordering workflow
- [x] Auto-provisioning on payment
- [x] 48 database migrations
- [x] All views with dark mode
- [x] Authorization policies registered

### 🔴 Do These Now (In order)

#### 1. Environment Setup (30 mins)

```bash
# If .env doesn't exist, create it
cp .env.example .env

# Generate app key
php artisan key:generate

# Your .env should have:
APP_NAME=TalksasaCloud
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com
APP_KEY=base64:... (auto-generated above)

DB_CONNECTION=mysql
DB_DATABASE=talksasa_cloud
DB_USERNAME=root
DB_PASSWORD=your_password

# Email (choose one provider)
MAIL_DRIVER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=465
MAIL_USERNAME=your_mailtrap_user
MAIL_PASSWORD=your_mailtrap_pass
```

**Providers to choose from:**
- **Testing:** Mailtrap (free, good for staging)
- **Production:** SendGrid, AWS SES, or any SMTP

#### 2. Payment Gateway Credentials (1 hour)

Get these credentials and add to `.env`:

**M-Pesa (Safaricom)**
```bash
# Go to: https://developer.safaricom.co.ke
# Create app → Get Consumer Key & Secret
# Create Passkey → Get your passkey
MPESA_CONSUMER_KEY=your_key_here
MPESA_CONSUMER_SECRET=your_secret_here
MPESA_BUSINESS_SHORT_CODE=174379  # Sandbox - change for production
MPESA_PASS_KEY=your_passkey
MPESA_PRODUCTION=false  # Set to true when live
```

**Stripe (International cards)**
```bash
# Go to: https://dashboard.stripe.com
# Developers > API Keys > Copy test keys
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_... # From Webhooks section
```

**PayPal (Online payments)**
```bash
# Go to: https://developer.paypal.com
# Create app > Get Client ID & Secret
PAYPAL_CLIENT_ID=your_client_id
PAYPAL_CLIENT_SECRET=your_client_secret
PAYPAL_PRODUCTION=false  # Set to true when live
PAYPAL_WEBHOOK_ID=your_webhook_id  # From webhook settings
```

#### 3. Database Setup (15 mins)

```bash
# Create database
mysql -u root -p -e "CREATE DATABASE talksasa_cloud;"

# Run migrations
php artisan migrate --force

# Seed essential data (templates, currencies, cron jobs)
php artisan db:seed --class=CronJobSeeder
php artisan db:seed --class=ContainerTemplateSeeder
php artisan db:seed --class=CurrencySeeder
```

#### 4. Create Admin Account (5 mins)

```bash
php artisan tinker
>>> User::create([
    'name' => 'Administrator',
    'email' => 'admin@yourdomain.com',
    'password' => bcrypt('secure_password_here'),
    'is_admin' => true,
    'email_verified_at' => now(),
]);
>>>
```

#### 5. Test Payment Gateways (2 hours)

**M-Pesa Test Flow:**
```
1. Start server: php artisan serve
2. Create order as customer
3. Initiate payment → Select M-Pesa
4. Enter test phone: 254708374149
5. Check logs: tail -f storage/logs/laravel.log
6. Payment should complete and invoice marked paid
7. Service should start auto-provisioning
```

**Stripe Test Flow:**
```
1. Create order as customer
2. Initiate payment → Select Stripe
3. Use test card: 4242 4242 4242 4242
4. Any future expiry, any 3-digit CVC
5. Payment completes
6. Check webhook in Stripe dashboard (should show 200 OK)
7. Service should auto-provision
```

**PayPal Test Flow:**
```
1. Create order as customer
2. Initiate payment → Select PayPal
3. Redirected to PayPal sandbox (login with sandbox credentials)
4. Approve payment
5. Return to success page
6. Service auto-provisions
```

#### 6. SSL Certificate (15 mins)

```bash
# Install Let's Encrypt
sudo apt-get install certbot python3-certbot-nginx

# Generate certificate (replace yourdomain.com with your actual domain)
sudo certbot certonly --nginx -d yourdomain.com

# Auto-renewal
sudo systemctl enable certbot.timer
sudo systemctl start certbot.timer

# Configure nginx to use HTTPS (see nginx config section below)
```

#### 7. Configure Web Server (30 mins)

**nginx configuration** (replace yourdomain.com):

```nginx
# /etc/nginx/sites-available/talksasa.conf

# Redirect HTTP to HTTPS
server {
    listen 80;
    server_name yourdomain.com www.yourdomain.com;
    return 301 https://$server_name$request_uri;
}

# HTTPS
server {
    listen 443 ssl http2;
    server_name yourdomain.com www.yourdomain.com;

    ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;

    # TLS security
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;

    root /var/www/talksasa-cloud/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Webhook endpoints (important for payments)
    location ~ /webhooks/ {
        try_files $uri /index.php?$query_string;
    }

    # Disable access to sensitive files
    location ~ /\. {
        deny all;
    }

    location ~ /\.env {
        deny all;
    }
}
```

Enable the site:
```bash
sudo ln -s /etc/nginx/sites-available/talksasa.conf /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

#### 8. Configure Cron Jobs (10 mins)

```bash
# Open crontab
crontab -e

# Add this line:
*/1 * * * * cd /path/to/talksasa-cloud && php artisan schedule:run >> /dev/null 2>&1
```

#### 9. Configure Webhooks (20 mins)

Update webhook URLs in each provider's dashboard:

**M-Pesa:** No dashboard change needed (callback URL is in code)

**Stripe:**
- Dashboard → Developers → Webhooks
- Add endpoint: `https://yourdomain.com/webhooks/stripe`
- Select events: `checkout.session.completed`
- Copy webhook signing secret to `.env` as `STRIPE_WEBHOOK_SECRET`

**PayPal:**
- Dashboard → Settings → Webhook Handler
- Create webhook: `https://yourdomain.com/webhooks/paypal`
- Subscribe to: `CHECKOUT.ORDER.COMPLETED`, `PAYMENT.CAPTURE.COMPLETED`
- Copy Webhook ID to `.env` as `PAYPAL_WEBHOOK_ID`

---

## 🚀 Verification Checklist

Before launching, verify everything works:

```bash
# ✅ Database connected
php artisan tinker
>>> DB::connection()->getPdo();  // Should work

# ✅ All migrations applied
php artisan migrate:status | grep "Ran"  // Should show all "Ran"

# ✅ Cron jobs registered
php artisan cron:list  // Should show 4+ jobs

# ✅ Email working
php artisan tinker
>>> Mail::raw('Test', function($m) { $m->to('you@example.com'); });

# ✅ Payment gateways configured
>>> config('payment.mpesa.production');  // Should return false (sandbox)
>>> config('payment.stripe.secret');  // Should have value
```

---

## 🔴 Final Checks Before Launch

- [ ] All `.env` variables filled in (no placeholders)
- [ ] Database created and migrations run
- [ ] Payment gateways tested with sandbox credentials
- [ ] SSL certificate installed (HTTPS working)
- [ ] Cron job registered in system crontab
- [ ] Email notifications sending
- [ ] Admin account created
- [ ] Test order → payment → provisioning completed
- [ ] Logs checked for errors: `tail storage/logs/laravel.log`
- [ ] Payment webhooks verified in provider dashboards

---

## 🎯 Production Rollout Checklist

When you're confident everything works:

```bash
# 1. Switch to production credentials
# Edit .env:
MPESA_PRODUCTION=true
PAYPAL_PRODUCTION=true
STRIPE_SECRET_KEY=sk_live_...  (use production keys)
APP_DEBUG=false
APP_ENV=production

# 2. Clear all caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 3. Create fresh production database (if not already done)
mysql -u root -p -e "CREATE DATABASE talksasa_prod;"

# 4. Run migrations on production database
php artisan migrate --env=production --force

# 5. Seed only production data (NOT test data)
php artisan db:seed --class=CronJobSeeder --env=production

# 6. Start/restart your web server
sudo systemctl restart php-fpm nginx

# 7. Monitor logs closely for first hour
tail -f storage/logs/laravel.log
```

---

## 📞 Troubleshooting

**If payments don't work:**
1. Check credentials in `.env` (no typos)
2. Check provider dashboard shows webhook delivery
3. Check logs: `grep "payment\|webhook" storage/logs/laravel.log`
4. Verify HTTPS is working: `curl -I https://yourdomain.com`

**If services don't provision:**
1. Check service status: `php artisan tinker` → `Service::find(1)->status`
2. Check invoice is marked paid
3. Run provision command manually: `php artisan service:provision 1`
4. Check logs for provisioning errors

**If cron jobs don't run:**
1. Verify crontab entry exists: `crontab -l`
2. Check permissions: `which php`
3. Test manually: `php artisan schedule:run`
4. Check logs: `grep "schedule\|cron" storage/logs/laravel.log`

---

## 📚 Reference Docs

For detailed information, see:

1. **DEPLOYMENT_CHECKLIST.md** — Complete step-by-step guide
2. **PAYMENT_SETUP.md** — Payment gateway details
3. **FEATURE_SUMMARY.md** — What's built, what's not
4. **PAYMENT_IMPLEMENTATION.md** — (in memory) Technical details

---

## ⏱️ Timeline

| Step | Time | Status |
|------|------|--------|
| 1. Environment setup | 30 mins | ⏭️ Do this |
| 2. Get credentials | 60 mins | ⏭️ Do this |
| 3. Database setup | 15 mins | ⏭️ Do this |
| 4. Create admin | 5 mins | ⏭️ Do this |
| 5. Test gateways | 120 mins | ⏭️ Do this |
| 6. SSL certificate | 15 mins | ⏭️ Do this |
| 7. Web server config | 30 mins | ⏭️ Do this |
| 8. Cron jobs | 10 mins | ⏭️ Do this |
| 9. Webhook setup | 20 mins | ⏭️ Do this |
| **TOTAL** | **4-6 hours** | |

---

## Summary

**Right now you have:** ✅ Complete feature set  
**What you need:** 🟡 Configuration & credentials  
**Resellers:** ❌ Coming Phase 2 (after deployment)  
**Timeline:** 4-6 hours to production

**Ready to get started?** Follow the checklist above in order. After completing all steps, you'll have a production-ready payment platform.

