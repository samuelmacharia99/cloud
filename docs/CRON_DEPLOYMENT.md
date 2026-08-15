# Cron and Queue Production Runtime

Talksasa Cloud uses Laravel's scheduler for short orchestration work and supervised
queue workers for long-running or remote work. Production must run all five unit types:

- `talksasa-scheduler.timer` — starts `schedule:run` every minute.
- `talksasa-queue.service` — default notifications, Git jobs, and general work.
- `talksasa-platform-cron-queue@.service` — database-configured platform commands
  (two workers by default).
- `talksasa-container-cron-queue@.service` — customer container cron commands
  (four workers by default).
- `talksasa-backup-queue.service` — long-running container backups.

Do not add a second crontab entry when the systemd timer is installed. Two scheduler
triggers increase lock contention and make operational diagnosis ambiguous.

## Required production configuration

```dotenv
SCHEDULER_ENABLED=true
SCHEDULER_ON_ONE_SERVER=true
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=7800
```

`SCHEDULER_ON_ONE_SERVER` requires a shared lock-capable cache. Use `database` or
`redis`; a local file cache is not valid on a multi-node application deployment.
The queue retry interval must exceed the 7200-second backup worker timeout.

## Installation

The normal production deployment runs both installers and fails if a unit is not
healthy:

```bash
sudo APP_PATH=/var/www/talksasa-cloud bash scripts/install-scheduler.sh
sudo APP_PATH=/var/www/talksasa-cloud bash scripts/install-queue-workers.sh
```

For a manual deployment, run migrations and seed platform cron definitions first:

```bash
php artisan migrate --force
php artisan db:seed --class=CronJobSeeder --force
php artisan cron:refresh-schedules
```

Re-running `CronJobSeeder` updates canonical names and schedules but preserves an
administrator's enabled/disabled choice for existing jobs.

## Verification

Run one scheduler tick, then require application and supervisor health:

```bash
sudo systemctl start talksasa-scheduler.service
sudo systemctl is-active --quiet talksasa-scheduler.timer
sudo systemctl is-active --quiet talksasa-queue.service
sudo systemctl is-active --quiet talksasa-platform-cron-queue@1.service
sudo systemctl is-active --quiet talksasa-container-cron-queue@1.service
sudo systemctl is-active --quiet talksasa-backup-queue.service
php artisan cron:verify-runtime
```

`cron:verify-runtime` fails when the scheduler heartbeat is stale, scheduling is
disabled, no platform jobs are enabled, the queue is synchronous, required queue
tables are missing, or the one-server lock cache is not shared.

Use the journal for process logs:

```bash
journalctl -u talksasa-scheduler.service -n 100 --no-pager
journalctl -u talksasa-queue.service -n 100 --no-pager
journalctl -u 'talksasa-platform-cron-queue@*.service' -n 100 --no-pager
journalctl -u 'talksasa-container-cron-queue@*.service' -n 100 --no-pager
journalctl -u talksasa-backup-queue.service -n 100 --no-pager
```

Application execution history remains available in Admin → Cron Jobs. Customer
container cron attempts are recorded in `container_cron_job_runs`.

Set `PLATFORM_CRON_WORKERS` or `CONTAINER_CRON_WORKERS` when running the installer
or deployment script to change worker counts. Both values must be positive integers.

## Execution model

- The heartbeat is written only by an executed scheduled heartbeat event. Running
  another Artisan command does not make a dead scheduler look healthy.
- Database-configured platform commands are uniquely dispatched to a dedicated
  worker, so remote metrics, reconciliation, and billing work cannot block later
  scheduler ticks.
- Scheduled backups create records and dispatch `CreateContainerBackupJob`; backup
  archives and uploads never run inside `schedule:run`.
- Due customer cron rows are atomically advanced and dispatched to the dedicated
  container-cron queue. Only eligible active/running services enter the batch.
- PHP and WordPress commands run as `www-data`; Laravel + Next.js commands target
  the `backend` Compose service and use persisted Laravel project roots.
- Non-idempotent customer commands disable SSH replay. A lost SSH response is
  reported as a failure instead of executing the command a second time.
- WordPress's platform cron is marked `is_system`, excluded from customer quota,
  and cannot be edited or deleted through customer routes.
- Suspending a service pauses only currently enabled jobs. Unsuspension restores
  only jobs paused by the platform; manually disabled jobs remain disabled.

All platform and customer schedules use the Admin → Settings → Cron timezone.
Changing it recalculates future execution times.

## Recovery

`cron:check-health` marks stale platform runs and stale queued/running customer
cron attempts as failed and alerts administrators. Inspect the worker journal and
`failed_jobs` before retrying:

```bash
php artisan queue:failed
php artisan queue:retry <uuid>
```

Avoid retrying a customer cron attempt when the remote command may still be
running. Confirm container state first.

## Development fallback

For local development only, `php artisan cron:show-setup` prints a valid
application-user crontab entry. Production uses the systemd units above.
