# Deploying Talksasa Cloud

`deploy.sh` deploys without downtime. Every deploy builds a complete release
next to the live one, proves it works, and then switches the live path to it
with a single atomic symlink swap. If the health check fails after the switch
the previous release is put back automatically.

## Layout on the server

```
/var/www/talksasa-cloud            -> /var/www/talksasa-cloud.deploy/releases/<stamp>-<sha>
/var/www/talksasa-cloud.deploy/
  repo/                            git clone, only used to export releases
  releases/<stamp>-<sha>/          code + vendor + built assets + cached config, one per deploy
  shared/.env                      the production environment file
  shared/storage/                  sessions, logs, uploads, backups (shared by all releases)
  shared/well-known/               ACME HTTP challenges (public/.well-known)
  previous_release                 path of the release a rollback returns to
```

The web server root, the systemd worker units and supervisor keep pointing at
`/var/www/talksasa-cloud`; they never learn about releases.

## One-time conversion

The first time, as root on the server, with the current in-place checkout at
`/var/www/talksasa-cloud`:

```bash
bash /var/www/talksasa-cloud/deploy.sh --bootstrap
```

This moves `storage/` and `.env` into `shared/`, turns the current checkout into
the first release (so nothing changes in what is served), clones the repo for
future exports, and reloads PHP. There is a sub-second window during the rename
in which the path does not exist; do it in a quiet minute.

## Every deploy

```bash
bash /var/www/talksasa-cloud/deploy.sh                       # origin/main
GIT_BRANCH=feature/x bash /var/www/talksasa-cloud/deploy.sh  # a branch
```

What happens, in order:

1. Preflight: tools present, live path is a release symlink, `APP_ENV=production`, 2 GB free, sudo for systemd.
2. `origin/<branch>` is fetched; if the live release already runs that commit nothing happens.
3. The commit is exported into a new release directory; `.env`, `storage`, `public/storage` and `public/.well-known` are linked to `shared/`.
4. `composer install --no-dev`, `npm ci`, `npm run build`, then config, route, view and event caches, all inside the new release. A failure here deletes the half-built release and leaves the live one untouched.
5. The release is booted in-process and must answer `/up` with 200 before anything is switched.
6. Settings are backed up, then pending migrations run. They run while the previous release still serves traffic, so **migrations must be additive**: add columns and tables, backfill, never drop or rename something the previous release still reads in the same deploy. Drop in a later deploy.
7. The symlink is swapped (one `rename`). PHP-FPM and the web server are reloaded gracefully. `queue:restart` lets workers finish their job, then the systemd worker units, the terminal websocket and the scheduler timer are restarted on the new release.
8. `HEALTH_URL` (default: `APP_URL` from the live `.env` plus `/up`) is checked with retries. On failure the previous release is switched back, reloaded, and the deploy exits non-zero with the failed release kept for inspection.
9. Allowlisted seeders, `cron:refresh-schedules`, `cron:verify-runtime` and the Mailcow DNS sync run; old releases are pruned to the last `KEEP_RELEASES` (5), never the live or previous one.

## Rollback and status

```bash
bash /var/www/talksasa-cloud/deploy.sh --rollback   # back to the previous release
bash /var/www/talksasa-cloud/deploy.sh --status     # what is live, what is previous
```

A rollback switches code only. Migrations already applied stay applied, which
is why they must be additive.

## Options

| Variable | Default | Purpose |
|---|---|---|
| `APP_PATH` | `/var/www/talksasa-cloud` | live path |
| `GIT_BRANCH` | `main` | branch to deploy |
| `DEPLOY_ROOT` | `<APP_PATH>.deploy` | releases and shared data |
| `SERVICE_USER` | `www-data` | owner of releases and storage |
| `HEALTH_URL` | `<APP_URL>/up` | checked after the switch; derived from the live `.env`. Override per run or persist it in `/etc/default/talksasa-deploy` |
| `KEEP_RELEASES` | `5` | releases kept on disk |
| `DEPLOY_FORCE=1` | | deploy on a non-production `APP_ENV`, or rebuild the live commit |
| `SKIP_ASSETS=1` | | reuse the live release's `public/build` (no npm) |
| `SKIP_SERVICES=1` | | local rehearsals only: no systemd, supervisor or PHP-FPM calls |

## CI

The GitHub workflow fetches `scripts/deploy-production.sh` from `origin/main`,
which in turn fetches `deploy.sh` from the same commit and hands over to it, so
the deploy always runs the scripts of the commit being shipped. It refuses to
run until the server has been bootstrapped.

## After a deploy of this release train

- Fill in the platform apps zone, its Cloudflare zone id and DNS token under
  Admin → Settings, or new stacks have no platform hostname until a domain is
  bound.
- Existing stacks stay on the shared network until you run
  `php artisan containers:apply-isolation --dry-run`, then the real run, per node.
