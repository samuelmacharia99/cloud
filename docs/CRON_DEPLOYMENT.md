# Cron System Deployment Guide

## Overview

Talksasa Cloud uses a production-ready cron automation system with dynamic job scheduling, health monitoring, and admin alerting. All cron jobs are configured in the database and can be enabled/disabled through the admin panel without code changes.

## Prerequisites

- Linux/Unix server with crontab access
- PHP CLI available
- Database with seeded CronJob records
- SMTP configured for admin alerts (optional but recommended)

## Installation Steps

### 1. Seed Cron Jobs (First Time Only)

```bash
php artisan db:seed --class=CronJobSeeder
```

This creates 8 cron job records in the database:
- Generate Invoices (02:00 UTC)
- Mark Invoices Overdue (03:00 UTC)
- Suspend Services (04:00 UTC)
- Terminate Services (05:00 UTC)
- Send Invoice Reminders (09:00 UTC)
- Check Domain Expiry (06:00 UTC)
- Check Node Health (every 5 min)
- Cleanup Monitoring (01:00 UTC)

### 2. View Setup Instructions

```bash
php artisan cron:show-setup
```

This displays:
- Server environment (paths, PHP binary)
- Exact cron command to add to crontab
- Step-by-step setup instructions

### 3. Add to System Crontab

The command output shows exactly what to add. It will be something like:

```bash
* * * * * /usr/bin/php /var/www/app/artisan schedule:run >> /var/www/app/storage/logs/schedule.log 2>&1
```

**Important:** Add this as the **application user** (usually `www-data`, `apache`, or your deploy user), NOT as root.

```bash
# As the application user:
crontab -e

# Paste the command, save, and exit
# Verify:
crontab -l
```

### 4. Verify Installation

Check that cron is running:

```bash
# Monitor the scheduler log in real-time
tail -f storage/logs/schedule.log

# Check job status (from app root)
php artisan cron:status

# After 1-2 minutes, you should see jobs running
```

## Configuration

### Timezone

All cron jobs respect the `cron_timezone` setting. To change it:

1. Go to Admin → Settings → Cron → Timezone
2. Select the timezone (defaults to Africa/Nairobi)
3. Save

Jobs will automatically reschedule to the new timezone.

### Execution Timeout

Maximum execution time per job (defaults to 120 seconds):

1. Admin → Settings → Cron → Maximum Execution Time
2. Set a value in seconds (10-3600)

Jobs running longer than this will be marked as failed and flagged for investigation.

### Retention Period

How long to keep cron logs (defaults to 30 days):

1. Admin → Settings → Cron → Log Retention Period
2. Logs older than this are automatically deleted

## Daily Operations

### Check System Health

View status from terminal:

```bash
php artisan cron:status
```

Or visit the admin dashboard:
```
/admin/cron
```

Both show:
- All job names and schedules
- Enabled/disabled status
- Last execution time
- Last status (Success/Failed/Running)
- Next scheduled run
- 24-hour execution history

### Manually Run a Job

From admin panel:
1. Go to Admin → Cron Jobs
2. Click a job name to see its details
3. Click "Run Now" to execute immediately

From terminal:
```bash
php artisan cron:generate-invoices
php artisan cron:send-invoice-reminders
# etc.
```

### Disable/Enable a Job

Without restarting the application:

From admin panel:
1. Go to Admin → Cron Jobs
2. Click "Toggle" on a job

From code:
```php
CronJob::where('command', 'cron:generate-invoices')->update(['enabled' => false]);
```

## Monitoring & Alerts

### Admin Email Alerts

Admins receive email alerts when:
- A cron job **fails** (exception captured)
- A job is **hung** (running > max_execution_time)
- A job **fails 3+ times in last hour** (health check detects pattern)

Alerts include:
- Job name and command
- Error/exception details
- How long the job was running (for hung jobs)
- Link to admin dashboard for details

### Health Checker

The `cron:check-health` command runs every 5 minutes automatically:
- Detects hung processes
- Identifies consecutive failures
- Marks severely hung jobs as failed
- Sends alerts to admins

### Logs

#### Application Log
```bash
tail -f storage/logs/schedule.log
```

Shows Laravel scheduler output. Can be verbose - useful for debugging.

#### Database Logs
Visit Admin → Cron Jobs → [Job Name] to see:
- All executions (paginated)
- Success/failure status
- Execution duration
- Output message or exception
- 24-hour chart of success/failure rates

#### Retention
Old logs are deleted automatically after the retention period (default 30 days) by the `cron:cleanup-monitoring` job.

## Troubleshooting

### "No jobs are running"

1. **Verify crontab is active:**
   ```bash
   crontab -l
   ```
   Should show the schedule:run command.

2. **Check file permissions:**
   ```bash
   ls -la storage/logs/schedule.log
   ```
   Should be writable by the application user.

3. **Test manually:**
   ```bash
   php artisan schedule:run
   ```
   Should execute without errors.

4. **Check cron daemon:**
   ```bash
   sudo service cron status  # or crond on some systems
   sudo service cron restart
   ```

### "Jobs are running but failing"

1. **Check the admin dashboard:**
   Go to Admin → Cron Jobs → [Job Name] to see error details.

2. **Check Laravel logs:**
   ```bash
   tail -f storage/logs/laravel.log
   ```

3. **Common issues:**
   - Database connection failure → Check `.env` database config
   - SMTP not configured → Check mail settings for notification jobs
   - DirectAdmin API unreachable → Check provisioning settings
   - Disk space full → `df -h` to check
   - Memory exhausted → Check PHP memory_limit in php.ini

### "Job is hung (stuck running)"

1. **Check from terminal:**
   ```bash
   php artisan cron:status
   # Look for jobs with status "⏳ Running"
   ```

2. **From admin dashboard:**
   Health alerts will notify admins. See job details for duration.

3. **Kill hung process manually:**
   ```bash
   # Find the PID
   ps aux | grep artisan
   
   # Kill it
   kill <PID>
   
   # Or force kill
   kill -9 <PID>
   ```

4. **Investigate why it hung:**
   - Check if the command has an infinite loop
   - Check if it's waiting for external API/database
   - Increase max_execution_time if the job is legitimately slow
   - Contact support if you can't identify the issue

### Disable problematic job

If a job is repeatedly failing and you can't fix it immediately:

1. From admin panel: Admin → Cron → [Job Name] → Toggle Disable
2. Or from terminal:
   ```bash
   php artisan tinker
   CronJob::where('command', 'cron:problem-job')->update(['enabled' => false]);
   ```

This prevents it from running on schedule. Fix the issue, then re-enable.

## Production Checklist

- [ ] Cron command added to system crontab
- [ ] `crontab -l` shows the schedule:run command
- [ ] Jobs started running (check `cron:status` or Admin → Cron)
- [ ] At least one job has completed successfully
- [ ] Admin email alerts working (trigger test by disabling a job)
- [ ] Configured correct timezone in Admin → Settings → Cron
- [ ] Verified SMTP working for notification emails
- [ ] Checked that service provisioning jobs complete without errors
- [ ] Verified invoice generation creates invoices correctly
- [ ] Checked that suspension/termination jobs work as expected
- [ ] Set up log monitoring/alerting (optional: integrate with ELK, Datadog, etc.)

## Architecture Notes

### Dynamic Scheduling

Jobs are loaded from the database on every scheduler run (every minute). This means:
- Enable/disable jobs without app restart
- Change schedules without code changes
- Modify execution order by adjusting schedules

### One-Server Protection

The `->onOneServer()` modifier prevents duplicate job runs on load-balanced deployments. It uses database locking, so:
- All servers must use the same database
- Job completes on one server, others skip it

### Health Monitoring

The health check command:
- Runs every 5 minutes (separate from main scheduler)
- Detects jobs running > max_execution_time
- Detects jobs failing 3+ times/hour
- Alerts admins via email
- Marks severely hung jobs as failed

## Performance Tuning

For high-volume systems:

1. **Increase max_execution_time** if legitimate jobs take >2 minutes:
   ```
   Admin → Settings → Cron → Max Execution Time
   ```

2. **Reduce retention period** to keep database smaller:
   ```
   Admin → Settings → Cron → Retention Period
   ```

3. **Stagger job schedules** to prevent bottlenecks (edit CronJob records):
   ```php
   // Instead of all at once, spread them:
   CronJob::where('name', 'Generate Invoices')->update(['schedule' => '0 1 * * *']); // 01:00
   CronJob::where('name', 'Mark Invoices Overdue')->update(['schedule' => '0 2 * * *']); // 02:00
   ```

4. **Monitor resource usage**:
   ```bash
   # During peak cron times, watch for:
   top -p $(pgrep -f "artisan schedule:run")
   ```

## Support

For issues not covered here:

1. Check Admin → Cron Jobs → [Job Name] for specific error details
2. Review storage/logs/laravel.log for application errors
3. Run `php artisan cron:status` to identify problematic jobs
4. Contact support with:
   - Job name and schedule
   - Error message from admin dashboard
   - Laravel logs
   - Server resources (memory, disk, CPU)
