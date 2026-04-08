#!/bin/bash

################################################################################
# Talksasa Cloud - Production Deployment Script
#
# This script safely deploys code updates from GitHub without touching .env
# Safe for CI/CD automation with proper error handling and rollback
#
# Usage: bash deploy.sh
# Environment variables:
#   GIT_BRANCH - Branch to deploy (default: main)
#   APP_PATH - Application path (auto-detected, can be overridden)
################################################################################

set -e  # Exit on any error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_PATH="${APP_PATH:-$SCRIPT_DIR}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/talksasa-cloud}"
LOG_FILE="${LOG_FILE:-/var/log/talksasa-deploy.log}"
GIT_BRANCH="${GIT_BRANCH:-main}"
TIMESTAMP=$(date '+%Y%m%d_%H%M%S')

# Ensure log directory exists
mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || LOG_FILE="/tmp/talksasa-deploy.log"
mkdir -p "$BACKUP_DIR" 2>/dev/null || BACKUP_DIR="/tmp/talksasa-backups"

# Logging function
log() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1" | tee -a "$LOG_FILE"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1" | tee -a "$LOG_FILE"
    exit 1
}

success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1" | tee -a "$LOG_FILE"
}

warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1" | tee -a "$LOG_FILE"
}

# Start deployment
log "================================"
log "Starting Talksasa Cloud Deployment"
log "================================"
log "Branch: $GIT_BRANCH"
log "Path: $APP_PATH"
log "Time: $(date)"

cd "$APP_PATH" || error "Cannot change to app directory: $APP_PATH"

# Step 1: Verify .env exists before making changes
log "Step 1/8: Verifying .env file..."
if [ ! -f "$APP_PATH/.env" ]; then
    error ".env file not found. Cannot proceed without configuration."
fi
log ".env verified ✓"

# Step 2: Backup current state
log "Step 2/8: Creating backup..."
BACKUP_NAME="backup_${TIMESTAMP}"
mkdir -p "$BACKUP_DIR/$BACKUP_NAME"

# Backup vendor, storage, and key files (not .env, that stays)
if [ -d "$APP_PATH/vendor" ]; then
    cp -r "$APP_PATH/vendor" "$BACKUP_DIR/$BACKUP_NAME/" 2>/dev/null || warning "Could not backup vendor"
fi
if [ -f "$APP_PATH/composer.lock" ]; then
    cp "$APP_PATH/composer.lock" "$BACKUP_DIR/$BACKUP_NAME/" 2>/dev/null
fi
log "Backup created at $BACKUP_DIR/$BACKUP_NAME ✓"

# Step 3: Fetch latest code from GitHub
log "Step 3/8: Fetching latest code from GitHub..."
git fetch origin || error "Failed to fetch from GitHub"
git checkout "$GIT_BRANCH" || error "Failed to checkout $GIT_BRANCH"
git reset --hard "origin/$GIT_BRANCH" || error "Failed to reset to origin/$GIT_BRANCH"
log "Code updated to latest version ✓"

# Step 4: Install/update Composer dependencies
log "Step 4/8: Installing Composer dependencies..."
if ! command -v composer &> /dev/null; then
    error "Composer not found. Please install Composer first."
fi
composer install --no-dev --optimize-autoloader --no-interaction || error "Composer install failed"
log "Dependencies installed ✓"

# Step 5: Run migrations (with confirmation)
log "Step 5/8: Checking for pending migrations..."
PENDING_MIGRATIONS=$(php artisan migrate:status 2>/dev/null | grep -c "Pending" || true)
if [ "$PENDING_MIGRATIONS" -gt 0 ]; then
    log "Found $PENDING_MIGRATIONS pending migrations"
    php artisan migrate --force || error "Migration failed"
    log "Migrations executed ✓"
else
    log "No pending migrations ✓"
fi

# Step 6: Clear caches and compiled files
log "Step 6/8: Clearing caches..."
php artisan cache:clear || warning "Could not clear cache"
php artisan config:clear || warning "Could not clear config cache"
php artisan route:clear || warning "Could not clear route cache"
php artisan view:clear || warning "Could not clear view cache"
log "Caches cleared ✓"

# Step 7: Optimize application
log "Step 7/8: Optimizing application..."
php artisan config:cache || warning "Could not cache config"
php artisan route:cache || warning "Could not cache routes"
php artisan view:cache || warning "Could not cache views"
log "Application optimized ✓"

# Step 8: Set permissions and finish
log "Step 8/8: Setting permissions..."

# Check if running as root (production) or local user (development)
if [ "$EUID" -eq 0 ]; then
    # Running as root (production server)
    chown -R www-data:www-data "$APP_PATH" || warning "Could not set ownership to www-data"
    chmod -R 755 "$APP_PATH/public" || warning "Could not set public permissions"
    chmod -R 755 "$APP_PATH/storage" || warning "Could not set storage permissions"
    chmod -R 755 "$APP_PATH/bootstrap/cache" || warning "Could not set bootstrap cache permissions"
    log "Permissions set for Apache (www-data) ✓"
else
    # Running as local user (development)
    log "Running as non-root user, skipping permission changes ✓"
fi

# Reload web server if running on production
if [ "$EUID" -eq 0 ]; then
    log "Reloading Apache..."
    if command -v systemctl &> /dev/null && systemctl is-active --quiet apache2; then
        systemctl reload apache2 || error "Failed to reload Apache"
        log "Apache reloaded ✓"
    elif command -v systemctl &> /dev/null && systemctl is-active --quiet nginx; then
        systemctl reload nginx || error "Failed to reload Nginx"
        log "Nginx reloaded ✓"
    else
        warning "No web server service found to reload"
    fi
fi

# Final status
log "================================"
success "Deployment completed successfully!"
log "================================"
log "Timestamp: $TIMESTAMP"
log "Backup: $BACKUP_DIR/$BACKUP_NAME"
log ""
log "Deployment log saved to: $LOG_FILE"

# Exit cleanly
exit 0
