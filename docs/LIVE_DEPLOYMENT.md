# Live Deployment Guide - Talksasa Cloud

**Deployment Date:** April 8, 2026  
**Environment:** Ubuntu 24.04 LTS  
**Installation Path:** `/root/talksasa-cloud`  
**Application:** Laravel 11 Multi-tenant SaaS  

---

## Current Status

✅ Git repository cloned  
⏳ PHP dependencies - In progress (missing ext-dom)  
⏳ Node dependencies - Not installed  
⏳ Database - Not configured  
⏳ Web server - Not configured  

---

## Step 1: Install Required PHP Extensions

Missing extensions causing Composer install to fail:

```bash
# Install PHP DOM extension and other required extensions
apt-get update
apt-get install -y \
    php8.3-dom \
    php8.3-xml \
    php8.3-xmlwriter \
    php8.3-simplexml \
    php8.3-pdo \
    php8.3-mysql \
    php8.3-sqlite3 \
    php8.3-gd \
    php8.3-redis \
    php8.3-memcached \
    php8.3-bcmath
```

---

## Step 2: Install Node.js and npm

```bash
# Install Node.js v20 (LTS)
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt-get install -y nodejs

# Verify installation
node --version
npm --version
```

---

## Step 3: Complete PHP Dependencies

```bash
cd /root/talksasa-cloud

# Install Composer dependencies
composer install --no-dev --optimize-autoloader

# Answer "yes" if prompted about running as root
```

---

## Step 4: Install Frontend Dependencies

```bash
# Install and build frontend assets
npm install
npm run build

# This creates public/build/ with compiled CSS/JS
```

---

## Step 5: Create Required Directories

```bash
# Create cache directory
mkdir -p bootstrap/cache

# Set proper permissions
chmod -R 755 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Create additional required directories
mkdir -p storage/logs
mkdir -p storage/app/uploads
mkdir -p storage/framework/sessions
mkdir -p storage/framework/cache
```

---

## Step 6: Database Setup

### Option A: MySQL (Recommended)

```bash
# Install MySQL server
apt-get install -y mysql-server

# Create database and user
mysql -u root -p << 'EOF'
CREATE DATABASE talksasa_cloud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'talksasa'@'localhost' IDENTIFIED BY 'Trizah@@254';
GRANT ALL PRIVILEGES ON talksasa_cloud.* TO 'talksasa'@'localhost';
FLUSH PRIVILEGES;
exit;
EOF
```

Or run individually:

```bash
mysql -u root -p
```

Then in MySQL:
```sql
CREATE DATABASE talksasa_cloud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'talksasa'@'localhost' IDENTIFIED BY 'secure_password_here';
GRANT ALL PRIVILEGES ON talksasa_cloud.* TO 'talksasa'@'localhost';
FLUSH PRIVILEGES;
exit;
```

### Option B: PostgreSQL

```bash
# Install PostgreSQL
apt-get install -y postgresql postgresql-contrib php8.3-pgsql

# Create database and user
sudo -u postgres psql << EOF
CREATE DATABASE talksasa_cloud;
CREATE USER talksasa WITH ENCRYPTED PASSWORD 'secure_password_here';
GRANT ALL PRIVILEGES ON DATABASE talksasa_cloud TO talksasa;
\q
EOF
```

---

## Step 7: Configure Environment

```bash
# Copy environment file
cp .env.example .env

# Edit .env with your settings
nano .env
```

**Key .env variables to update:**

```env
APP_NAME="Talksasa Cloud"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=talksasa_cloud
DB_USERNAME=talksasa
DB_PASSWORD=secure_password_here

MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=465
MAIL_USERNAME=your_mailtrap_username
MAIL_PASSWORD=your_mailtrap_password
MAIL_FROM_ADDRESS=noreply@servers.talksasa.com
MAIL_FROM_NAME="Talksasa Cloud"

# Payment Gateways
MPESA_CONSUMER_KEY=your_mpesa_key
MPESA_CONSUMER_SECRET=your_mpesa_secret
STRIPE_PUBLIC_KEY=your_stripe_public_key
STRIPE_SECRET_KEY=your_stripe_secret_key
PAYPAL_MODE=live
PAYPAL_CLIENT_ID=your_paypal_client_id
PAYPAL_CLIENT_SECRET=your_paypal_secret

SESSION_DRIVER=file
CACHE_DRIVER=file
QUEUE_CONNECTION=sync
```

---

## Step 8: Generate Application Key

```bash
php artisan key:generate
```

---

## Step 9: Run Database Migrations

```bash
# Run all migrations
php artisan migrate --force

# Seed initial data (optional)
php artisan db:seed --class=
```

---

## Step 10: Create Initial Admin User

```bash
# Create admin user via Tinker
php artisan tinker

# In Tinker, run:
User::create([
    'name' => 'Admin Name',
    'email' => 'info@talksasa.com',
    'password' => bcrypt('!talk!2022@sasa'),
    'is_admin' => true,
    'email_verified_at' => now(),
]);

exit
```

Or create via:
```bash
php artisan user:create-admin
```

---

## Step 11: Web Server Setup

### Option A: Nginx (Recommended)

```bash
# Install Nginx
apt-get install -y nginx

# Create Nginx configuration
nano /etc/nginx/sites-available/talksasa-cloud
```

**Paste this configuration:**

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name servers.talksasa.com www.servers.talksasa.com;

    # Redirect HTTP to HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name servers.talksasa.com www.servers.talksasa.com;

    # SSL Certificates (Let's Encrypt)
    ssl_certificate /etc/letsencrypt/live/servers.talksasa.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/servers.talksasa.com/privkey.pem;

    # SSL Configuration
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;

    root /root/talksasa-cloud/public;
    index index.php index.html;

    # Logging
    access_log /var/log/nginx/talksasa-access.log;
    error_log /var/log/nginx/talksasa-error.log;

    # Gzip compression
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "no-referrer-when-downgrade" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_buffer_size 128k;
        fastcgi_buffers 4 256k;
    }

    # Deny access to hidden files
    location ~ /\. {
        deny all;
    }

    # Deny access to sensitive files
    location ~ ~$ {
        deny all;
    }
}
```

**Enable the site:**

```bash
ln -s /etc/nginx/sites-available/talksasa-cloud /etc/nginx/sites-enabled/talksasa-cloud

# Test configuration
nginx -t

# Restart Nginx
systemctl restart nginx
```

### Option B: Apache

```bash
# Install Apache
apt-get install -y apache2 libapache2-mod-php8.3

# Enable required modules
a2enmod rewrite
a2enmod ssl
a2enmod http2

# Create VirtualHost
nano /etc/apache2/sites-available/talksasa-cloud.conf
```

**Paste this configuration:**

```apache
<VirtualHost *:80>
    ServerName servers.talksasa.com
    ServerAlias www.servers.talksasa.com
    Redirect permanent / https://servers.talksasa.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName servers.talksasa.com
    ServerAlias www.servers.talksasa.com
    DocumentRoot /root/talksasa-cloud/public

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/servers.talksasa.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/servers.talksasa.com/privkey.pem

    <Directory /root/talksasa-cloud/public>
        AllowOverride All
        Require all granted
        <IfModule mod_rewrite.c>
            RewriteEngine On
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteRule ^ index.php [QSA,L]
        </IfModule>
    </Directory>

    <Directory /root/talksasa-cloud>
        Options -Indexes
    </Directory>

    ErrorLog /var/log/apache2/talksasa-error.log
    CustomLog /var/log/apache2/talksasa-access.log combined
</VirtualHost>
```

**Enable the site:**

```bash
a2ensite talksasa-cloud
a2dissite 000-default
apache2ctl configtest
systemctl restart apache2
```

---

## Step 12: SSL Certificate (Let's Encrypt)

```bash
# Install Certbot
apt-get install -y certbot python3-certbot-nginx python3-certbot-apache

# Get certificate (choose your web server)
# For Nginx:
certbot certonly --nginx -d servers.talksasa.com -d www.servers.talksasa.com

# For Apache:
certbot certonly --apache -d servers.talksasa.com -d www.servers.talksasa.com

# Auto-renewal (runs automatically)
certbot renew --dry-run
```

---

## Step 13: Install PHP-FPM (for Nginx)

```bash
# Install PHP-FPM
apt-get install -y php8.3-fpm

# Start and enable
systemctl start php8.3-fpm
systemctl enable php8.3-fpm

# Verify socket
ls -la /var/run/php/php8.3-fpm.sock
```

---

## Step 14: Setup Cron Jobs

```bash
# Edit crontab
crontab -e

# Add this line for Laravel scheduler:
* * * * * cd /root/talksasa-cloud && php artisan schedule:run >> /dev/null 2>&1

# Add this line for queue processing (if using database queue):
*/5 * * * * cd /root/talksasa-cloud && php artisan queue:work --stop-when-empty
```

---

## Step 15: Cache and Optimization

```bash
# Generate optimized autoloader
composer install --no-dev --optimize-autoloader

# Cache configuration
php artisan config:cache

# Cache routes
php artisan route:cache

# Cache views
php artisan view:cache

# Create storage symlink (for file uploads)
php artisan storage:link
```

---

## Step 16: Payment Gateway Configuration

### M-Pesa Setup

1. Get credentials from Safaricom
2. Update `.env`:
   ```env
   MPESA_CONSUMER_KEY=your_key
   MPESA_CONSUMER_SECRET=your_secret
   MPESA_PASSKEY=your_passkey
   ```

### Stripe Setup

1. Get API keys from Stripe Dashboard
2. Update `.env`:
   ```env
   STRIPE_PUBLIC_KEY=pk_live_xxxxx
   STRIPE_SECRET_KEY=sk_live_xxxxx
   ```

### PayPal Setup

1. Get credentials from PayPal Developer
2. Update `.env`:
   ```env
   PAYPAL_MODE=live
   PAYPAL_CLIENT_ID=xxxxx
   PAYPAL_CLIENT_SECRET=xxxxx
   ```

---

## Step 17: Email Configuration

### Using Mailtrap (Testing)

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
MAIL_FROM_ADDRESS=noreply@servers.talksasa.com
MAIL_FROM_NAME="Talksasa Cloud"
```

### Using SendGrid (Production)

```env
MAIL_MAILER=sendgrid
SENDGRID_API_KEY=your_sendgrid_api_key
MAIL_FROM_ADDRESS=noreply@servers.talksasa.com
MAIL_FROM_NAME="Talksasa Cloud"
```

### Using SES (AWS)

```env
MAIL_MAILER=ses
AWS_ACCESS_KEY_ID=your_key
AWS_SECRET_ACCESS_KEY=your_secret
AWS_DEFAULT_REGION=us-east-1
```

---

## Step 18: Verify Installation

```bash
# Check Laravel installation
php artisan about

# Check database connection
php artisan tinker
DB::connection()->getPdo();
exit

# Test email
php artisan tinker
Mail::raw('Test email', function($m) { $m->to('test@example.com'); });
exit

# Check file permissions
ls -la storage/
ls -la bootstrap/cache/

# Check application logs
tail -f storage/logs/laravel.log
```

---

## Step 19: Setup Monitoring

### Create monitoring script

```bash
nano /root/monitoring.sh
```

```bash
#!/bin/bash

echo "=== Talksasa Cloud Status Check ==="
echo "Time: $(date)"
echo ""

echo "Web Server: $(systemctl is-active nginx || systemctl is-active apache2)"
echo "PHP-FPM: $(systemctl is-active php8.3-fpm)"
echo "MySQL: $(systemctl is-active mysql)"
echo "Disk Usage: $(df -h / | tail -1 | awk '{print $5}')"
echo "Memory Usage: $(free | grep Mem | awk '{printf("%.2f%%\n", $3/$2 * 100)}')"
echo "Laravel Queue: $(ps aux | grep 'queue:work' | grep -v grep | wc -l) worker(s)"
echo "Cron Status: $(cat /var/spool/cron/crontabs/root | grep 'schedule:run' | wc -l) active"
echo ""

echo "Recent Errors:"
tail -5 /root/talksasa-cloud/storage/logs/laravel.log | grep -i error || echo "No recent errors"
```

Make executable:
```bash
chmod +x /root/monitoring.sh
./root/monitoring.sh
```

---

## Step 20: Backup Strategy

```bash
# Create backup script
nano /root/backup.sh
```

```bash
#!/bin/bash

BACKUP_DIR="/backups/talksasa-cloud"
APP_DIR="/root/talksasa-cloud"
DB_NAME="talksasa_cloud"
DB_USER="talksasa"

mkdir -p $BACKUP_DIR

# Database backup
mysqldump -u $DB_USER -p $DB_NAME | gzip > $BACKUP_DIR/db-$(date +%Y%m%d-%H%M%S).sql.gz

# Application backup
tar -czf $BACKUP_DIR/app-$(date +%Y%m%d-%H%M%S).tar.gz $APP_DIR/storage $APP_DIR/config $APP_DIR/.env

# Keep only last 7 days
find $BACKUP_DIR -name "*.gz" -mtime +7 -delete

echo "Backup completed at $(date)" >> $BACKUP_DIR/backup.log
```

Make executable and schedule:
```bash
chmod +x /root/backup.sh

# Add to crontab (daily at 2 AM)
crontab -e
0 2 * * * /root/backup.sh
```

---

## Deployment Checklist

- [ ] PHP 8.3 with all required extensions installed
- [ ] Node.js and npm installed
- [ ] Composer dependencies installed
- [ ] Frontend assets built (npm run build)
- [ ] Directories created and permissions set
- [ ] Database created and configured
- [ ] `.env` file configured with all settings
- [ ] Application key generated
- [ ] Database migrations run
- [ ] Initial admin user created
- [ ] Web server (Nginx/Apache) configured
- [ ] SSL certificate installed and working
- [ ] PHP-FPM installed and running (for Nginx)
- [ ] Cron jobs configured
- [ ] Payment gateways configured
- [ ] Email service configured and tested
- [ ] Storage symlink created
- [ ] Cache optimization commands run
- [ ] Application logs verified
- [ ] Backups automated

---

## Post-Deployment Testing

```bash
# Test homepage
curl -L https://servers.talksasa.com

# Test login page
curl -L https://servers.talksasa.com/login

# Check Nginx/Apache status
systemctl status nginx
# or
systemctl status apache2

# Monitor application
tail -f storage/logs/laravel.log

# Test email sending
php artisan tinker
Mail::raw('Test', fn($m) => $m->to('admin@servers.talksasa.com')->subject('Test Email'));
```

---

## Troubleshooting

### 500 Error
```bash
# Check application logs
tail -f storage/logs/laravel.log

# Check permissions
chmod -R 755 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Clear cache
php artisan cache:clear
php artisan config:clear
php artisan view:clear
```

### Database Connection Error
```bash
# Test database connection
php artisan tinker
DB::connection()->getPdo();

# Check credentials in .env
cat .env | grep DB_
```

### File Upload Issues
```bash
# Check storage symlink
ls -la public/storage

# Recreate symlink
php artisan storage:link

# Check permissions
chmod -R 775 storage/app
```

### Cron Not Running
```bash
# Check cron status
systemctl status cron

# Verify crontab entry
crontab -l

# Test manually
php artisan schedule:run
```

---

## Production Checklist Before Going Live

1. **Security**
   - [ ] APP_DEBUG=false in .env
   - [ ] All payment gateway credentials are production keys
   - [ ] HTTPS enforced
   - [ ] Firewall configured
   - [ ] SSH keys configured instead of passwords

2. **Performance**
   - [ ] Caching configured (Redis/Memcached)
   - [ ] Database indexes verified
   - [ ] CDN configured for static assets
   - [ ] Gzip compression enabled

3. **Reliability**
   - [ ] Automated backups running
   - [ ] Error monitoring configured
   - [ ] Uptime monitoring configured
   - [ ] Logs rotated

4. **Compliance**
   - [ ] Privacy policy set
   - [ ] Terms of service set
   - [ ] GDPR compliance reviewed
   - [ ] PCI-DSS compliance verified

5. **Support**
   - [ ] Support email configured
   - [ ] Error notification emails working
   - [ ] Backup notifications working

---

## Support

For issues during deployment:
1. Check `/root/talksasa-cloud/storage/logs/laravel.log`
2. Review error output and search documentation
3. Contact support with logs and error details

---

**Deployment Status:** In Progress  
**Last Updated:** April 8, 2026  
**Next Step:** Complete database setup
