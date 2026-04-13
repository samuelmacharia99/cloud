# Cron Setup Guide - Talksasa Cloud

## 🎯 Overview

Your system now has an **environment-aware dynamic cron command generator** that intelligently adapts to different hosting environments and automatically validates the setup. The cron command displayed in the admin settings automatically adjusts based on:

- The server environment (development, production, Docker, etc.)
- PHP executable location (auto-detected from common paths)
- Custom environment variables
- Current application base path

## 🚀 Quick Start

### Step 1: Visit Settings Page
```
http://localhost:8000/admin/settings?group=cron
```

### Step 2: Copy Recommended Command
The page displays the recommended cron command with a **one-click copy button**. For this environment, it should show:

```bash
* * * * * cd /home/zumi/php/road-map/talksasa-cloud && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

### Step 3: Add to Crontab
SSH to your server and run:
```bash
crontab -e
```

Paste the command and save (`:wq` in vim).

### Step 4: Verify
```bash
crontab -l
```

You should see your cron command listed.

## 📊 What You'll See in Settings

### Status Card
Shows whether cron is properly configured:
- **Green checkmark**: All systems operational ✅
- **Red warning**: Issues found that need attention ❌

Displays:
- Number of enabled jobs
- Runs in the last 24 hours
- Any failures (if applicable)

### Cron Command Options
Four different command variants for different use cases:

#### 1. **Default (Recommended)** 
Best for most cases. Suppresses cron output.
```bash
* * * * * cd /home/zumi/php/road-map/talksasa-cloud && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

#### 2. **With Logging**
Logs all output to `storage/logs/cron.log` for debugging.
```bash
* * * * * cd /home/zumi/php/road-map/talksasa-cloud && /usr/bin/php artisan schedule:run >> /var/log/cron.log 2>&1
```

#### 3. **With Email Alerts**
Sends email if cron job fails (requires sendmail).
```bash
* * * * * cd /home/zumi/php/road-map/talksasa-cloud && /usr/bin/php artisan schedule:run >> /var/log/cron.log 2>&1 || mail -s 'Cron failed' admin@example.com
```

#### 4. **Verbose (Development)**
Outputs detailed logs via syslog. For development only.
```bash
* * * * * cd /home/zumi/php/road-map/talksasa-cloud && /usr/bin/php artisan schedule:run -v 2>&1 | logger -t talksasa-cron
```

### Configuration Settings
Three settings you can customize:

- **Cron Timezone**: Which timezone to use for scheduling (e.g., `Africa/Nairobi`, `UTC`)
- **Log Retention**: How many days to keep cron job logs (older logs auto-deleted)
- **Max Execution Time**: Maximum seconds a cron job can run before timeout

## 🔍 How Environment Detection Works

### PHP Path Auto-Detection
The system checks these locations in order:
1. `PHP_PATH` environment variable (if set)
2. Docker container (if running in /.dockerenv)
3. `/usr/bin/php` (Linux standard)
4. `/usr/local/bin/php` (Linux alternative)
5. `/opt/homebrew/bin/php` (macOS with homebrew)
6. System `which php` command
7. Default to `php` (uses system PATH)

### Docker Support
If running in Docker:
- Automatically detects container environment
- Uses `php` from container PATH
- Simplifies the command (no full path needed)

### Custom PHP Path
To use a specific PHP version:
```bash
# Set environment variable
export PHP_PATH=/opt/custom-php/bin/php8.2

# Restart your application
```

## 🛡️ Validation & Health Checks

The system validates:

✅ **Artisan file exists** at Laravel app root
✅ **PHP executable is accessible** and executable
✅ **Storage/logs directory is writable** for cron logs
✅ **At least one cron job is enabled** in the database

## 📈 Cron Jobs in Your System

Your system has **11 cron jobs** configured:

| Job | Schedule | Purpose |
|-----|----------|---------|
| Generate Invoices | 2:00 AM | Create monthly invoices for services |
| Mark Invoices Overdue | 3:00 AM | Update invoice status when payment due |
| Suspend Services | 4:00 AM | Pause services for overdue accounts |
| Terminate Services | 5:00 AM | Stop services permanently |
| Send Invoice Reminders | 9:00 AM | Email reminders to customers |
| Check Domain Expiry | 6:00 AM | Monitor domain expiration dates |
| Check Node Health | Every 5 min | Monitor server health |
| Cleanup Monitoring Data | 1:00 AM | Remove old monitoring records |
| Collect Container Metrics | Every 5 min | Gather container resource usage |
| Renew SSL Certificates | 2:00 AM | Auto-renew Let's Encrypt certs |
| Update Exchange Rates | Midnight | Fetch latest currency rates |

## 🧪 Testing

### Test 1: Verify Cron Works
Wait 1-2 minutes, then check the database:
```bash
php artisan tinker
> \App\Models\CronJobLog::latest()->first();
```

You should see a recent log entry from one of the cron jobs.

### Test 2: Run Manually
Test an individual cron job:
```bash
php artisan cron:generate-invoices
php artisan cron:check-node-health
php artisan cron:update-exchange-rates
```

### Test 3: List All Scheduled Tasks
```bash
php artisan schedule:list
```

Shows all cron jobs with next run times.

## 🐛 Troubleshooting

### Issue: Cron not running
1. **Check crontab is set**: `crontab -l`
2. **Verify PHP path**: `which php` (should match the path in your cron command)
3. **Check permissions**: Ensure the user running crontab can execute PHP
4. **Check logs**: `tail -f storage/logs/cron.log`

### Issue: "Cron setup not configured" in admin panel
1. Go to `/admin/settings?group=cron`
2. Check the red error box - it lists specific issues
3. Fix each issue (e.g., make logs directory writable)
4. Refresh the page to verify fix

### Issue: Jobs not executing at scheduled times
1. **Verify timezone**: Check `cron_timezone` setting matches your server timezone
2. **Verify schedule format**: Each job has a cron expression (e.g., `0 2 * * *` = 2:00 AM daily)
3. **Check max execution time**: If a job takes too long, increase `max_execution_time`

### Issue: High CPU usage from cron
1. Check `php artisan schedule:list` - see if any jobs have weird schedules
2. Look at recent cron logs for errors
3. Increase `max_execution_time` if jobs are timing out and retrying

## 📝 Best Practices

### For Production Servers
1. Use "**With Logging**" variant to capture output
2. Set reasonable `max_execution_time` (300-600 seconds)
3. Monitor cron logs regularly: `tail -f /var/log/cron.log`
4. Keep `cron_retention_days` at 30 for archiving

### For Development
1. Use "**Verbose**" variant to debug job output
2. Run `php artisan schedule:run -v` manually to test
3. Check `storage/logs/laravel.log` for errors
4. Use shorter `cron_retention_days` (7-14 days)

### For Docker
1. Cron commands in containers run under the container user (usually `root`)
2. Use volume mounts for persistent logs: `-v logs:/app/storage/logs`
3. Consider using a dedicated cron container if running multiple instances
4. Monitor with `docker logs container-name`

## 🔐 Security Notes

- **Never store sensitive data** in cron job output
- **Log files should not be web-accessible** (they're in `storage/logs/`)
- **Restrict crontab access**: Only authorized users should edit crontab
- **Monitor failed jobs**: Check admin panel regularly for failures
- **Email alerts**: If using email notifications, verify email addresses are correct

## 📞 Support

If cron jobs aren't working:
1. Visit `/admin/settings?group=cron`
2. Check the status card for specific issues
3. Review the validation errors listed
4. Copy the recommended command and re-add to crontab
5. Wait 2 minutes and check `/admin/cron` dashboard for job logs

---

**Last Updated**: 2026-04-14  
**System**: Talksasa Cloud  
**Cron Helper Version**: 1.0
