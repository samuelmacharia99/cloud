#!/usr/bin/env bash
#
# Commit and push local changes to GitHub.
#
# Usage:
#   ./scripts/git-ship.sh "Fix container file manager rename"
#   ./scripts/git-ship.sh -m "Your message here"
#   ./scripts/git-ship.sh                    # prompts for message
#   ./scripts/git-ship.sh --dry-run -m "…"   # preview only
#   ./scripts/git-ship.sh --all -m "…"       # include usually-ignored local files
#   ./scripts/git-ship.sh --no-push -m "…"   # commit only
#   ./scripts/git-ship.sh -m "…" app/ routes/web.php   # stage specific paths only
#
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || {
  echo "error: not inside a git repository" >&2
  exit 1
}
cd "$ROOT"

# Local files we normally do NOT ship (handoff notes, sqlite, runtime storage, etc.)
DEFAULT_EXCLUDES=(
  database/database.sqlite
  database/database.sqlite-journal
  scripts/important.md
  storage/framework/sessions
  storage/logs
)

DRY_RUN=0
STAGE_ALL=0
NO_PUSH=0
MESSAGE=""
PATHS=()

usage() {
  sed -n '2,14p' "$0" | sed 's/^# \{0,1\}//'
  exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    -h|--help)
      usage 0
      ;;
    -n|--dry-run)
      DRY_RUN=1
      shift
      ;;
    -a|--all)
      STAGE_ALL=1
      shift
      ;;
    --no-push)
      NO_PUSH=1
      shift
      ;;
    -m|--message)
      [[ $# -ge 2 ]] || { echo "error: $1 requires a value" >&2; exit 1; }
      MESSAGE="$2"
      shift 2
      ;;
    --)
      shift
      PATHS+=("$@")
      break
      ;;
    -*)
      echo "error: unknown option: $1" >&2
      usage 1
      ;;
    *)
      if [[ -z "$MESSAGE" ]]; then
        MESSAGE="$1"
        shift
      else
        PATHS+=("$1")
        shift
      fi
      ;;
  esac
done

branch="$(git branch --show-current)"
upstream="$(git rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null || true)"

echo "==> Repository: $ROOT"
echo "==> Branch:     $branch${upstream:+ (tracks $upstream)}"
echo

git status --short
echo

if [[ -z "$MESSAGE" ]]; then
  read -r -p "Commit message: " MESSAGE
  if [[ -z "${MESSAGE// }" ]]; then
    echo "error: commit message is required" >&2
    exit 1
  fi
fi

stage_changes() {
  if [[ ${#PATHS[@]} -gt 0 ]]; then
    echo "==> Staging paths: ${PATHS[*]}"
    git add -- "${PATHS[@]}"
    return
  fi

  echo "==> Staging changes"
  git add -A

  if [[ "$STAGE_ALL" -eq 0 ]]; then
    for path in "${DEFAULT_EXCLUDES[@]}"; do
      if [[ -e "$path" ]] || git ls-files --error-unmatch "$path" >/dev/null 2>&1; then
        git reset -q HEAD -- "$path" 2>/dev/null || true
      fi
    done
  fi
}

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "==> Dry run (no commit/push)"
  stage_changes
  echo
  echo "Staged diff:"
  git diff --cached --stat
  if [[ -z "$(git diff --cached --name-only)" ]]; then
    echo "Nothing staged."
  fi
  exit 0
fi

if [[ -z "$(git status --porcelain)" ]]; then
  echo "Nothing to commit."
  exit 0
fi

stage_changes

if [[ -z "$(git diff --cached --name-only)" ]]; then
  echo "Nothing staged after excludes. Use --all or pass explicit paths."
  exit 1
fi

echo
echo "Staged files:"
git diff --cached --stat
echo

read -r -p "Create commit? [y/N] " confirm
if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
  echo "Aborted. Staged changes kept — run 'git reset' to unstage."
  exit 1
fi

git commit -m "$MESSAGE"

if [[ "$NO_PUSH" -eq 1 ]]; then
  echo "==> Committed. Skipping push (--no-push)."
  git status --short
  exit 0
fi

if [[ -z "$upstream" ]]; then
  read -r -p "No upstream set. Push to origin/$branch? [y/N] " push_confirm
  if [[ ! "$push_confirm" =~ ^[Yy]$ ]]; then
    echo "Committed locally. Push later with: git push -u origin $branch"
    exit 0
  fi
  git push -u origin HEAD
else
  git push
fi

echo
git status
echo "Done."
