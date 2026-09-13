#!/usr/bin/env bash
#
# Talksasa Cloud - zero-downtime production deploy
#
# Each deploy builds a complete release in its own directory (code, vendor,
# built assets, cached config/routes/views), proves it boots and answers
# its health route, runs migrations, and only then switches the live path
# to it with one atomic symlink swap. PHP-FPM and the web server are
# reloaded gracefully, queue workers finish their current job before
# picking up the new code, and if the live health check fails afterwards
# the previous release is put back automatically.
#
# Layout (DEPLOY_ROOT defaults to <APP_PATH>.deploy):
#   <APP_PATH>                 symlink -> releases/<timestamp>-<sha>   (the live release)
#   <DEPLOY_ROOT>/repo         git clone used only to export releases
#   <DEPLOY_ROOT>/releases/    one directory per release, last KEEP_RELEASES kept
#   <DEPLOY_ROOT>/shared/      .env, storage/, well-known/  (persist across releases)
#
# Usage:
#   bash deploy.sh                 deploy origin/<GIT_BRANCH> (default main)
#   bash deploy.sh --rollback      switch back to the previous release
#   bash deploy.sh --status        show live and previous release
#   bash deploy.sh --bootstrap     one-time conversion of an in-place checkout
#
# Environment:
#   APP_PATH          live path the web server and systemd units point at (default: /var/www/talksasa-cloud)
#   GIT_BRANCH        branch to deploy (default: main)
#   GIT_REMOTE        remote URL for the first clone (default: taken from the existing checkout)
#   DEPLOY_ROOT       where releases and shared data live (default: <APP_PATH>.deploy)
#   SERVICE_USER      owner of releases and storage (default: www-data)
#   HEALTH_URL        URL checked before the switch is kept (default: http://127.0.0.1:8000/up)
#   KEEP_RELEASES     releases to keep on disk (default: 5)
#   DEPLOY_FORCE=1    allow APP_ENV other than production, or redeploying the live sha
#   SKIP_ASSETS=1     skip npm ci / npm run build (frontend unchanged)
#   SKIP_SERVICES=1   do not touch systemd/supervisor/php-fpm (local rehearsals only)
#
set -Eeuo pipefail

APP_PATH="${APP_PATH:-/var/www/talksasa-cloud}"
GIT_BRANCH="${GIT_BRANCH:-main}"
DEPLOY_ROOT="${DEPLOY_ROOT:-${APP_PATH}.deploy}"
SERVICE_USER="${SERVICE_USER:-www-data}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1:8000/up}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
LOG_FILE="${LOG_FILE:-/var/log/talksasa-deploy.log}"
LOCK_FILE="${LOCK_FILE:-/var/lock/talksasa-deploy.lock}"
PLATFORM_CRON_WORKERS="${PLATFORM_CRON_WORKERS:-2}"
CONTAINER_CRON_WORKERS="${CONTAINER_CRON_WORKERS:-4}"

REPO_DIR="${DEPLOY_ROOT}/repo"
RELEASES_DIR="${DEPLOY_ROOT}/releases"
SHARED_DIR="${DEPLOY_ROOT}/shared"
PREVIOUS_FILE="${DEPLOY_ROOT}/previous_release"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || LOG_FILE="/tmp/talksasa-deploy.log"

log()     { echo -e "${BLUE}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $*" | tee -a "$LOG_FILE"; }
warn()    { echo -e "${YELLOW}[WARNING]${NC} $*" | tee -a "$LOG_FILE"; }
success() { echo -e "${GREEN}[SUCCESS]${NC} $*" | tee -a "$LOG_FILE"; }
fail()    { echo -e "${RED}[ERROR]${NC} $*" | tee -a "$LOG_FILE"; exit 1; }

as_root() { if [[ $EUID -eq 0 ]]; then "$@"; else sudo -n "$@"; fi; }
have()    { command -v "$1" >/dev/null 2>&1; }

# Runs one build step with its full output captured to the build log. On
# failure the tail of that log is printed, so a broken composer or npm run is
# never hidden behind a one-line error.
run_build_step() {
    local label="$1" dir="$2"; shift 2
    log "$label"
    if ! (cd "$dir" && "$@") >>"$BUILD_LOG" 2>&1; then
        warn "$label failed. Last lines of $BUILD_LOG:"
        tail -n 40 "$BUILD_LOG" | tee -a "$LOG_FILE"
        return 1
    fi
}

# Until the symlink is switched, a failed deploy removes the half-built
# release and leaves the live one untouched. BUILD_RELEASE is cleared right
# after the switch so a later failure keeps the release for rollback and
# inspection.
BUILD_RELEASE=""
on_exit() {
    local code=$?
    if [[ $code -ne 0 && -n "$BUILD_RELEASE" && -d "$BUILD_RELEASE" ]]; then
        warn "Deploy failed before the switch; live release untouched. Removing $BUILD_RELEASE"
        rm -rf "$BUILD_RELEASE"
    fi
}
trap on_exit EXIT

# Runs artisan inside a release as the service user when we are root, so the
# caches it writes are owned by the user that serves them.
artisan() {
    local release="$1"; shift
    if [[ $EUID -eq 0 ]]; then
        sudo -u "$SERVICE_USER" -H php "$release/artisan" "$@"
    else
        php "$release/artisan" "$@"
    fi
}

current_release() { [[ -L "$APP_PATH" ]] && readlink -f "$APP_PATH" || true; }
previous_release() { [[ -f "$PREVIOUS_FILE" ]] && cat "$PREVIOUS_FILE" || true; }
release_sha() { [[ -f "$1/.release-sha" ]] && cat "$1/.release-sha" || echo "unknown"; }

# ---------------------------------------------------------------------------
# Live switching and rollback
# ---------------------------------------------------------------------------

switch_to() {
    local release="$1"
    # A symlink is replaced with rename(2): every request sees either the old
    # or the new release, never a half-written path.
    ln -sfn "$release" "${APP_PATH}.switching"
    mv -Tf "${APP_PATH}.switching" "$APP_PATH"
}

reload_php_and_web() {
    [[ "${SKIP_SERVICES:-0}" == "1" ]] && { warn "SKIP_SERVICES=1: not reloading PHP-FPM or the web server"; return 0; }
    # New PHP-FPM workers start with an empty realpath cache and opcode cache,
    # so the swapped symlink is honoured immediately; the reload is graceful.
    local unit
    for unit in $(systemctl list-units --type=service --state=running --no-legend 'php*-fpm*' 2>/dev/null | awk '{print $1}'); do
        as_root systemctl reload "$unit" && log "Reloaded $unit"
    done
    if systemctl is-active --quiet apache2 2>/dev/null; then
        as_root systemctl reload apache2 && log "Reloaded Apache"
    fi
    if systemctl is-active --quiet nginx 2>/dev/null; then
        as_root systemctl reload nginx && log "Reloaded nginx"
    fi
}

restart_workers() {
    local release="$1"
    # queue:restart lets every worker finish its current job before exiting;
    # systemd then brings it back on the release the symlink now points at.
    artisan "$release" queue:restart || warn "queue:restart failed"
    [[ "${SKIP_SERVICES:-0}" == "1" ]] && { warn "SKIP_SERVICES=1: not restarting workers"; return 0; }
    local units=(talksasa-queue.service talksasa-backup-queue.service)
    local i
    for ((i = 1; i <= PLATFORM_CRON_WORKERS; i++)); do units+=("talksasa-platform-cron-queue@${i}.service"); done
    for ((i = 1; i <= CONTAINER_CRON_WORKERS; i++)); do units+=("talksasa-container-cron-queue@${i}.service"); done
    local unit
    for unit in "${units[@]}"; do
        if systemctl cat "$unit" >/dev/null 2>&1; then
            as_root systemctl restart "$unit" || warn "Could not restart $unit"
        fi
    done
    if have supervisorctl && as_root supervisorctl status container-terminal-ws >/dev/null 2>&1; then
        as_root supervisorctl restart container-terminal-ws >/dev/null || warn "Could not restart container-terminal-ws"
    fi
    if systemctl cat talksasa-scheduler.timer >/dev/null 2>&1; then
        as_root systemctl start talksasa-scheduler.timer || warn "Could not start the scheduler timer"
    fi
}

health_check() {
    # Retries cover the moment between the symlink swap and the last old
    # FPM worker exiting.
    local attempt
    for attempt in 1 2 3 4 5 6; do
        if curl -fsS --max-time 10 "$HEALTH_URL" >/dev/null 2>&1; then
            return 0
        fi
        sleep 3
    done
    return 1
}

# rollback [to] [previous-after]
# Switches back to a release. Afterwards the "previous" pointer names the
# release we came from (so a second --rollback rolls forward again), unless the
# caller passes what it should be: a failed deploy restores the pointer it
# overwrote, so --rollback never ends up pointing at the live release.
rollback() {
    local to="${1:-$(previous_release)}"
    [[ -n "$to" && -d "$to" ]] || fail "No previous release to roll back to."
    local from
    from="$(current_release)"
    local previous_after="${2-$from}"
    log "Rolling back: $(basename "$from") -> $(basename "$to")"
    switch_to "$to"
    if [[ -n "$previous_after" && "$previous_after" != "$to" ]]; then
        echo "$previous_after" > "$PREVIOUS_FILE"
    else
        rm -f "$PREVIOUS_FILE"
    fi
    reload_php_and_web
    restart_workers "$to"
    if health_check; then
        success "Rolled back to $(basename "$to") ($(release_sha "$to"))"
    else
        fail "Rolled back to $(basename "$to") but the health check still fails at $HEALTH_URL. Investigate now."
    fi
}

# ---------------------------------------------------------------------------
# Shared data (persists across releases)
# ---------------------------------------------------------------------------

ensure_shared_layout() {
    mkdir -p "$SHARED_DIR/storage/app/public" "$SHARED_DIR/storage/framework/cache/data" \
        "$SHARED_DIR/storage/framework/sessions" "$SHARED_DIR/storage/framework/views" \
        "$SHARED_DIR/storage/logs" "$SHARED_DIR/storage/backups" "$SHARED_DIR/well-known/acme-challenge" \
        "$RELEASES_DIR"
    [[ -f "$SHARED_DIR/.env" ]] || fail "Missing $SHARED_DIR/.env. Run --bootstrap once, or copy the production .env there."
    if [[ $EUID -eq 0 ]]; then
        chown -R "$SERVICE_USER:$SERVICE_USER" "$SHARED_DIR/storage" "$SHARED_DIR/well-known"
        chown "$SERVICE_USER:$SERVICE_USER" "$SHARED_DIR/.env"
        chmod 640 "$SHARED_DIR/.env"
    fi
}

link_shared_into() {
    local release="$1"
    rm -rf "$release/storage" "$release/public/storage" "$release/public/.well-known"
    ln -s "$SHARED_DIR/.env" "$release/.env"
    ln -s "$SHARED_DIR/storage" "$release/storage"
    ln -s "$SHARED_DIR/storage/app/public" "$release/public/storage"
    # ACME HTTP challenges are written under public_path(); they must survive a switch.
    ln -s "$SHARED_DIR/well-known" "$release/public/.well-known"
    mkdir -p "$release/bootstrap/cache"
}

# ---------------------------------------------------------------------------
# One-time conversion of an in-place git checkout at APP_PATH
# ---------------------------------------------------------------------------

bootstrap() {
    [[ -L "$APP_PATH" ]] && fail "$APP_PATH is already a release symlink; nothing to bootstrap."
    [[ -d "$APP_PATH/.git" ]] || fail "$APP_PATH is not a git checkout; cannot bootstrap from it."
    [[ -f "$APP_PATH/.env" ]] || fail "$APP_PATH/.env not found."

    local remote
    remote="${GIT_REMOTE:-$(git -C "$APP_PATH" remote get-url origin)}"
    local sha
    sha="$(git -C "$APP_PATH" rev-parse HEAD)"
    local first="$RELEASES_DIR/$(date +%Y%m%d%H%M%S)-${sha:0:12}"

    log "Bootstrapping release layout under $DEPLOY_ROOT from $APP_PATH ($sha)"
    mkdir -p "$SHARED_DIR" "$RELEASES_DIR"

    if [[ ! -d "$REPO_DIR/.git" ]]; then
        log "Cloning $remote into $REPO_DIR"
        git clone --quiet "$remote" "$REPO_DIR"
    fi

    # Move, not copy: the live storage tree (sessions, uploads, logs, backups)
    # keeps its inode and its permissions.
    [[ -f "$SHARED_DIR/.env" ]] || cp -p "$APP_PATH/.env" "$SHARED_DIR/.env"
    if [[ -d "$APP_PATH/storage" && ! -L "$APP_PATH/storage" && ! -d "$SHARED_DIR/storage" ]]; then
        mv "$APP_PATH/storage" "$SHARED_DIR/storage"
    fi
    if [[ -d "$APP_PATH/public/.well-known" && ! -L "$APP_PATH/public/.well-known" && ! -d "$SHARED_DIR/well-known" ]]; then
        mv "$APP_PATH/public/.well-known" "$SHARED_DIR/well-known"
    fi
    ensure_shared_layout

    # The existing checkout becomes the first release, exactly as it runs
    # now, so the switch changes nothing but the path.
    log "Moving the current checkout to $first (a sub-second window where $APP_PATH does not exist)"
    mv "$APP_PATH" "$first"
    ln -sfn "$first" "$APP_PATH"
    rm -f "$first/.env"
    link_shared_into "$first"
    echo "$sha" > "$first/.release-sha"
    [[ $EUID -eq 0 ]] && chown -h "$SERVICE_USER:$SERVICE_USER" "$APP_PATH" "$first/.env" "$first/storage" "$first/public/storage" "$first/public/.well-known"

    reload_php_and_web
    restart_workers "$first"
    health_check || warn "Health check at $HEALTH_URL did not pass after bootstrap; check the web server root points at $APP_PATH/public"
    success "Bootstrapped. Live release: $first. Future deploys: bash deploy.sh"
}

# ---------------------------------------------------------------------------
# Deploy
# ---------------------------------------------------------------------------

preflight() {
    local tool
    for tool in git php composer curl; do have "$tool" || fail "$tool is not installed."; done
    [[ "${SKIP_ASSETS:-0}" == "1" ]] || have npm || fail "npm is not installed (set SKIP_ASSETS=1 to deploy without a frontend build)."
    [[ -L "$APP_PATH" ]] || fail "$APP_PATH is not a release symlink. Run: bash deploy.sh --bootstrap"
    [[ -d "$REPO_DIR/.git" ]] || fail "Missing $REPO_DIR. Run: bash deploy.sh --bootstrap"
    ensure_shared_layout

    local app_env
    app_env="$(grep -E '^APP_ENV=' "$SHARED_DIR/.env" | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
    if [[ "$app_env" != "production" && "${DEPLOY_FORCE:-}" != "1" ]]; then
        fail "APP_ENV is '${app_env:-unset}'. Set DEPLOY_FORCE=1 to deploy anyway."
    fi

    local free_kb
    free_kb="$(df -Pk "$DEPLOY_ROOT" | awk 'NR==2 {print $4}')"
    [[ "$free_kb" -ge 2097152 ]] || fail "Less than 2 GB free under $DEPLOY_ROOT; refusing to build a release."

    if [[ "${SKIP_SERVICES:-0}" != "1" && $EUID -ne 0 ]] && ! sudo -n true 2>/dev/null; then
        fail "Run as root, or as a user with passwordless sudo for systemctl."
    fi
}

deploy() {
    preflight

    log "Fetching origin/$GIT_BRANCH"
    git -C "$REPO_DIR" fetch --quiet --prune origin
    local sha
    sha="$(git -C "$REPO_DIR" rev-parse "origin/$GIT_BRANCH")" || fail "Branch origin/$GIT_BRANCH not found."
    local live
    live="$(current_release)"
    if [[ "$(release_sha "$live")" == "$sha" && "${DEPLOY_FORCE:-}" != "1" ]]; then
        success "Live release already runs $sha (origin/$GIT_BRANCH). Nothing to deploy. Set DEPLOY_FORCE=1 to rebuild."
        exit 0
    fi

    local release="$RELEASES_DIR/$(date +%Y%m%d%H%M%S)-${sha:0:12}"
    log "Building release $(basename "$release") from $sha"
    mkdir -p "$release"
    git -C "$REPO_DIR" archive "$sha" | tar -x -C "$release"
    echo "$sha" > "$release/.release-sha"
    link_shared_into "$release"
    [[ $EUID -eq 0 ]] && chown -R "$SERVICE_USER:$SERVICE_USER" "$release"
    BUILD_RELEASE="$release"
    BUILD_LOG="$(dirname "$LOG_FILE")/talksasa-deploy-build-$(basename "$release").log"
    : > "$BUILD_LOG"

    run_build_step "Installing Composer dependencies" "$release" \
        composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader

    if [[ "${SKIP_ASSETS:-0}" != "1" ]]; then
        run_build_step "Installing frontend dependencies" "$release" npm ci --no-audit --no-fund
        run_build_step "Building frontend assets" "$release" npm run build
    else
        log "SKIP_ASSETS=1: copying built assets from the live release"
        [[ -d "$live/public/build" ]] || fail "SKIP_ASSETS=1 but the live release has no public/build."
        cp -a "$live/public/build" "$release/public/build"
    fi

    [[ $EUID -eq 0 ]] && chown -R "$SERVICE_USER:$SERVICE_USER" "$release"

    log "Caching config, routes, views and events"
    artisan "$release" config:cache --quiet
    artisan "$release" route:cache --quiet
    artisan "$release" view:cache --quiet
    artisan "$release" event:cache --quiet

    log "Proving the release boots and answers its health route (in-process, before anything is switched)"
    artisan "$release" migrate:status >/dev/null || fail "The new release cannot reach the database."
    (cd "$release" && php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle(Illuminate\Http\Request::create("/up", "GET"));
        exit($response->getStatusCode() === 200 ? 0 : 1);
    ') || fail "The new release does not answer /up with 200."

    log "Backing up settings, then running migrations"
    artisan "$release" settings:backup || warn "settings:backup failed (table may not exist yet)"
    local pending
    pending="$(artisan "$release" migrate:status 2>/dev/null | grep -c 'Pending' || true)"
    if [[ "$pending" -gt 0 ]]; then
        log "$pending pending migration(s). They run while the previous release still serves traffic, so they must be additive."
        artisan "$release" migrate --force || fail "Migration failed; live release untouched."
    else
        log "No pending migrations"
    fi

    log "Switching $APP_PATH -> $(basename "$release")"
    local previous_before_switch
    previous_before_switch="$(previous_release)"
    echo "$live" > "$PREVIOUS_FILE"
    switch_to "$release"
    BUILD_RELEASE=""
    [[ $EUID -eq 0 ]] && chown -h "$SERVICE_USER:$SERVICE_USER" "$APP_PATH"
    reload_php_and_web
    restart_workers "$release"

    if ! health_check; then
        warn "Health check failed at $HEALTH_URL after the switch. Rolling back."
        rollback "$live" "$previous_before_switch"
        fail "Deploy of $sha rolled back. The failed release is kept at $release for inspection."
    fi
    success "Live on $(basename "$release") ($sha)"

    log "Post-deploy: seeders, schedules, mail DNS (non-fatal)"
    artisan "$release" db:seed --class=CronJobSeeder --force --quiet || warn "CronJobSeeder failed"
    artisan "$release" cron:refresh-schedules || warn "cron:refresh-schedules failed"
    artisan "$release" db:seed --class=SettingSeeder --force --quiet || warn "SettingSeeder failed"
    artisan "$release" db:seed --class=EmailTemplateSeeder --force --quiet || warn "EmailTemplateSeeder failed"
    artisan "$release" db:seed --class=SmsTemplateSeeder --force --quiet || warn "SmsTemplateSeeder failed"
    artisan "$release" db:seed --class=CurrencySeeder --force --quiet || warn "CurrencySeeder failed"
    artisan "$release" cron:verify-runtime || warn "cron:verify-runtime reported problems"
    artisan "$release" mailcow:sync-cloudflare-dns || warn "mailcow:sync-cloudflare-dns reported failures"

    prune_releases "$release" "$live"

    log "Previous release kept for rollback: $(basename "$live")  (bash deploy.sh --rollback)"
}

prune_releases() {
    local keep_live="$1" keep_prev="$2"
    local dir
    # Oldest first; never the live or previous release.
    for dir in $(ls -1d "$RELEASES_DIR"/*/ 2>/dev/null | sort | head -n -"$KEEP_RELEASES"); do
        dir="${dir%/}"
        [[ "$dir" == "$keep_live" || "$dir" == "$keep_prev" ]] && continue
        log "Pruning old release $(basename "$dir")"
        rm -rf "$dir"
    done
}

status() {
    local live prev
    live="$(current_release)"; prev="$(previous_release)"
    echo "Live:     ${live:-none} ($(release_sha "${live:-/nonexistent}"))"
    echo "Previous: ${prev:-none} ($(release_sha "${prev:-/nonexistent}"))"
    echo "Releases on disk:"
    ls -1d "$RELEASES_DIR"/*/ 2>/dev/null | sed 's#/$##; s#^#  #' || true
}

# ---------------------------------------------------------------------------

main() {
    exec 9>"$LOCK_FILE" 2>/dev/null || exec 9>"/tmp/talksasa-deploy.lock"
    flock -n 9 || fail "Another deploy is running (lock: $LOCK_FILE)."

    case "${1:-}" in
        --rollback) rollback ;;
        --status) status ;;
        --bootstrap) bootstrap ;;
        --help|-h) sed -n '2,35p' "$0" ;;
        "")
            log "================================"
            log "Talksasa Cloud deploy: origin/$GIT_BRANCH -> $APP_PATH"
            log "================================"
            deploy
            ;;
        *) fail "Unknown option: $1 (use --rollback, --status, --bootstrap)" ;;
    esac
}

main "$@"
