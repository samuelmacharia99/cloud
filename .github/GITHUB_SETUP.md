# GitHub Configuration Guide

## Repository Setup

### Branch Strategy

```
main
  ├── Production deployment (protected)
  └── develop
      ├── Staging deployment
      └── feature/* branches
```

**Branch Rules:**
- `main` — Production-ready code only. Requires PR review and passing tests.
- `develop` — Integration branch for features. Auto-deploys to staging.
- `feature/*` — Feature branches. Create from `develop`, PR back to `develop`.

### Workflow

```
1. Create feature branch from develop
   git checkout develop
   git pull origin develop
   git checkout -b feature/your-feature-name

2. Make commits with clear messages
   git commit -m "Description of change"

3. Push and create PR
   git push origin feature/your-feature-name
   # Go to GitHub and create PR to develop

4. Tests run automatically
   ✓ PHP syntax check
   ✓ Code style check
   ✓ Database migrations
   ✓ PHPUnit tests

5. Merge to develop (auto-deploys to staging)
   GitHub: Click "Merge pull request"
   Staging deployment starts automatically

6. When ready, create PR: develop → main
   Requires approval + all checks pass

7. Merge to main (auto-deploys to production)
   Automatic production deployment + smoke tests
```

---

## GitHub Secrets Setup

### Required Secrets

Add these to GitHub: Settings → Secrets and variables → Actions

#### Staging Secrets
```
STAGING_HOST         = your.staging.server
STAGING_USER         = deploy user (e.g., www-data)
STAGING_SSH_KEY      = SSH private key (paste full key)
```

#### Production Secrets
```
PROD_HOST            = your.production.server
PROD_USER            = deploy user (e.g., www-data)
PROD_SSH_KEY         = SSH private key (paste full key)
```

#### Optional
```
SLACK_WEBHOOK        = https://hooks.slack.com/... (for notifications)
```

### How to Add Secrets

1. Go to GitHub repo → Settings → Secrets and variables → Actions
2. Click "New repository secret"
3. Name: `STAGING_HOST` (example)
4. Value: `your.staging.server`
5. Click "Add secret"
6. Repeat for all secrets

### SSH Key Setup

For passwordless deployment, you need SSH keys:

#### Generate SSH key (if you don't have one)
```bash
ssh-keygen -t ed25519 -C "github-actions@talksasa.cloud"
# Save as: ~/.ssh/github_deploy
# Leave passphrase empty
```

#### Add to GitHub Secret
```bash
cat ~/.ssh/github_deploy
# Copy the entire private key (starting with -----BEGIN...)
# Paste into STAGING_SSH_KEY secret
# Repeat for PROD_SSH_KEY
```

#### Add to server(s)
```bash
# SSH into staging/production server as deploy user
cat ~/.ssh/github_deploy.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

---

## Branch Protection Rules

### Set up for `main` branch

1. Go to Settings → Branches
2. Click "Add rule" for pattern `main`
3. Configure:
   - ✅ Require a pull request before merging
   - ✅ Require approvals (1+ reviews)
   - ✅ Require status checks to pass before merging
   - ✅ Require branches to be up to date before merging
   - ✅ Include administrators
   - ❌ Allow force pushes (disable)
   - ❌ Allow deletions (disable)

### Set up for `develop` branch

1. Add rule for pattern `develop`
2. Configure:
   - ✅ Require status checks to pass before merging
   - ❌ Allow direct pushes (optional - only branch that allows this)
   - ❌ Require PR (optional - allows direct pushes for urgent fixes)

---

## CI/CD Workflows

### Test Workflow (`.github/workflows/test.yml`)

Runs on every push and PR to main/develop:
- PHP syntax check
- Composer dependency install
- Database migration test
- Database seeding test
- PHPUnit tests
- Code style check (Pint)

**Status**: Automatically reported in PR

### Staging Deployment (`.github/workflows/deploy-staging.yml`)

Runs automatically on push to `develop`:
1. Connect to staging server via SSH
2. Pull latest code
3. Install dependencies
4. Run migrations
5. Clear caches
6. Restart queue/scheduler
7. Notify Slack

**Status**: Check workflow tab for details

### Production Deployment (`.github/workflows/deploy-production.yml`)

Runs automatically on push to `main`:
1. Run all tests (must pass)
2. Create automatic backup on production
3. Pull latest code
4. Install dependencies
5. Run migrations
6. Clear caches
7. Run CronJobSeeder (safe, uses updateOrCreate)
8. Restart services
9. Verify health check
10. Rollback if failure detected

**Status**: Check workflow tab + Slack notification

---

## Common Tasks

### Create a Feature

```bash
# Start from develop
git checkout develop
git pull origin develop

# Create feature branch
git checkout -b feature/m-pesa-sandbox-testing

# Make changes
git add .
git commit -m "Test M-Pesa integration in sandbox mode"

# Push to GitHub
git push -u origin feature/m-pesa-sandbox-testing

# Go to GitHub and create PR to develop
# - Add description
# - Link any issues
# - Wait for tests to pass
# - Ask for review
# - Merge
```

### Deploy to Production

```bash
# From develop, create PR to main
git checkout develop
git pull origin develop
git checkout -b release/2026-04-01

# Make any last-minute fixes
git commit -m "Version bump for production release"
git push -u origin release/2026-04-01

# Go to GitHub and create PR to main
# - Add release notes
# - Get approval
# - Merge (deployment happens automatically)
```

### Emergency Hotfix

```bash
# If production is broken and you need an urgent fix:

git checkout main
git pull origin main
git checkout -b hotfix/critical-payment-bug

# Fix the issue
git commit -m "HOTFIX: Payment callback validation missing"

# Create PR to main
git push -u origin hotfix/critical-payment-bug

# Go to GitHub and create PR to main
# - Mark as [HOTFIX] in title
# - Explain urgency
# - Get quick review
# - Merge
# - Production deployment happens automatically
# - Production deployment sends Slack alert
```

### Rollback Production (if auto-rollback didn't work)

```bash
# SSH into production server
ssh deploy@prod.server

cd /var/www
ls -t talksasa-cloud.backup.* | head -5
# Choose the backup you want

# Rollback manually
rm -rf talksasa-cloud.old
mv talksasa-cloud talksasa-cloud.failed
mv talksasa-cloud.backup.YYYYMMDD_HHMMSS talksasa-cloud

cd talksasa-cloud
sudo systemctl restart talksasa-queue
sudo systemctl restart talksasa-scheduler

# Notify team
# Then create a post-mortem issue
```

---

## Monitoring & Alerts

### Check Workflow Status

```bash
# From command line
gh run list
gh run view <RUN_ID>

# From GitHub web
Repo → Actions → [Workflow name]
```

### Slack Integration

All deployments notify Slack:
- ✅ Test passed → Deploy started
- ✅ Deployment successful → Notification with version
- ❌ Deployment failed → Alert with error
- 🔄 Rollback triggered → Notification + manual review needed

### Email Notifications

GitHub sends emails for:
- PR review requests
- Branch protection rule violations
- Workflow failures
- Action failures

---

## Troubleshooting

### "Workflow failed: SSH connection denied"

**Cause**: SSH key not authorized on server or key mismatch

**Fix**:
```bash
# On production server
cat ~/.ssh/authorized_keys | grep "github_deploy"

# If not there:
cat ~/.ssh/github_deploy.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys

# Verify key format in GitHub secret matches
```

### "Workflow failed: Git permission denied"

**Cause**: GitHub token doesn't have push access (rare with default setup)

**Fix**: Workflows use `actions/checkout@v3` which auto-handles auth. If it fails:
```bash
# Go to Settings → Developer settings → Personal access tokens
# Create new token with `repo` scope
# Add as GITHUB_TOKEN secret
```

### "Deployment successful but site is broken"

**Check**:
1. SSH into server and check logs:
   ```bash
   tail -f storage/logs/laravel.log
   tail -f storage/logs/schedule.log
   ```

2. Check queue status:
   ```bash
   ps aux | grep artisan
   php artisan queue:failed
   ```

3. Verify services:
   ```bash
   sudo systemctl status talksasa-queue
   sudo systemctl status talksasa-scheduler
   ```

4. If broken, rollback:
   ```bash
   # Follow "Rollback Production" section above
   ```

### "Tests are failing but I didn't change anything"

**Cause**: Usually database state issue

**Check**:
1. Verify migrations are up to date
2. Verify seeders run correctly
3. Check if test database exists
4. View workflow logs for detailed error

**Fix**: Manually trigger workflow or push dummy commit

---

## Best Practices

### Commit Messages

```
Bad:  "fix bugs"
Good: "Fix payment webhook timeout in M-Pesa callback handler"

Bad:  "update"
Good: "Update invoice PDF template to include tax breakdown"

Bad:  "asdf"
Good: "Seed CronJob table with 8 billing automation jobs"
```

**Format**:
```
[TYPE] Brief description (50 chars max)

Longer explanation if needed (optional)
- Bullet points for changes
- Line per change

Fixes #123  (if fixing an issue)
Closes #456 (if closing a PR)
```

**Types**:
- `feat:` New feature
- `fix:` Bug fix
- `docs:` Documentation
- `style:` Code formatting
- `refactor:` Code restructuring
- `perf:` Performance improvement
- `test:` Test changes
- `ci:` CI/CD changes
- `chore:` Maintenance

### Pull Request Guidelines

1. **Title**: Clear, concise, references issue if applicable
2. **Description**: 
   - What changed and why
   - How to test
   - Screenshots if UI change
3. **Tests**: All tests passing
4. **Review**: Request review from CODEOWNERS
5. **Merge**: Use "Squash and merge" for feature branches, "Create merge commit" for release branches

### Before Pushing

```bash
# Update from remote
git pull origin develop

# Run tests locally (if set up)
composer test

# Check git status
git status

# Review changes before push
git diff origin/develop...HEAD

# Push when ready
git push origin feature/name
```

---

## Support

- **Workflow issues?** Check `.github/workflows/` files for syntax
- **Deployment failed?** Check Actions tab for detailed logs
- **Can't push to main?** Check branch protection rules
- **Need a secret?** Settings → Secrets → Add new

All workflow files are documented inline with comments.
