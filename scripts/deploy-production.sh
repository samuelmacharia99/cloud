#!/usr/bin/env bash
#
# CI entry point (see .github/workflows/deploy-production.yml).
#
# Production runs the release layout managed by deploy.sh: the live path is
# a symlink to the current release and releases are exported from a repo
# clone, so this wrapper fetches the newest deploy.sh from origin/main and
# hands over to it. It never pulls into the live directory.
#
set -euo pipefail

APP_PATH="${APP_PATH:-/var/www/talksasa-cloud}"
DEPLOY_ROOT="${DEPLOY_ROOT:-${APP_PATH}.deploy}"
GIT_BRANCH="${GIT_BRANCH:-main}"

log() { echo "[deploy] $(date '+%Y-%m-%d %H:%M:%S') $*"; }

if [[ ! -L "$APP_PATH" || ! -d "$DEPLOY_ROOT/repo/.git" ]]; then
  log "ERROR: $APP_PATH is not on the release layout yet."
  log "Run once on the server, as root:  bash $APP_PATH/deploy.sh --bootstrap"
  exit 1
fi

git -C "$DEPLOY_ROOT/repo" fetch --quiet --prune origin
SCRIPT="$(mktemp /tmp/talksasa-deploy.XXXXXX.sh)"
trap 'rm -f "$SCRIPT"' EXIT
git -C "$DEPLOY_ROOT/repo" show "origin/${GIT_BRANCH}:deploy.sh" > "$SCRIPT"

log "Deploying origin/${GIT_BRANCH} with deploy.sh from that commit"
exec env APP_PATH="$APP_PATH" DEPLOY_ROOT="$DEPLOY_ROOT" GIT_BRANCH="$GIT_BRANCH" \
  PLATFORM_CRON_WORKERS="${PLATFORM_CRON_WORKERS:-2}" CONTAINER_CRON_WORKERS="${CONTAINER_CRON_WORKERS:-4}" \
  bash "$SCRIPT"
