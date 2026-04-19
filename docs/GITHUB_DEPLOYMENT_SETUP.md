# GitHub & CI/CD Deployment Setup

## ✅ What's Ready

All code has been pushed to GitHub at: **https://github.com/samuelmacharia99/cloud**

### Repository Contents
- ✅ Full Phase 1 implementation (billing, payments, notifications, cron)
- ✅ Production documentation (PRODUCTION_CHECKLIST.md, CRON_DEPLOYMENT.md)
- ✅ GitHub Actions CI/CD pipelines
- ✅ Branch protection configuration
- ✅ Issue templates and PR templates
- ✅ Git workflow guides

---

## 🚀 GitHub Actions Workflows

### 1. **Test Workflow** (`.github/workflows/test.yml`)
Runs on: Every push to `main` and `develop`

What it does:
- ✅ PHP syntax validation
- ✅ Composer dependency check
- ✅ Database migration test
- ✅ Database seeding test
- ✅ PHPUnit tests
- ✅ Code style check (Pint)

**Status**: Shown as ✓ or ✗ on every commit/PR

### 2. **Staging Deployment** (`.github/workflows/deploy-staging.yml`)
Runs on: Push to `develop` branch

What it does:
1. SSH into staging server
2. Pull latest code
3. Install dependencies
4. Run migrations
5. Clear caches
6. Restart services
7. Notify Slack

**Access**: Go to Actions tab to monitor

### 3. **Production Deployment** (`.github/workflows/deploy-production.yml`)
Runs on: Push to `main` branch (after PR approval)

What it does:
1. Run all tests (must pass)
2. Create automatic backup
3. SSH into production server
4. Pull latest code
5. Install dependencies
6. Run migrations
7. Run CronJobSeeder
8. Clear caches
9. Restart services
10. Verify health (curl /health)
11. Verify cron status
12. **Auto-rollback if any step fails**

**Status**: Slack notification + detailed logs

---

## 🔑 Required Setup on GitHub

### Step 1: Add Secrets

Go to: **Settings → Secrets and variables → Actions**

Add these secrets (for staging):
```
STAGING_HOST         your.staging.server
STAGING_USER         deploy_user
STAGING_SSH_KEY      [your SSH private key]
```

Add these secrets (for production):
```
PROD_HOST            your.production.server
PROD_USER            deploy_user
PROD_SSH_KEY         [your SSH private key]
```

Optional:
```
SLACK_WEBHOOK        https://hooks.slack.com/... (for notifications)
```

### Step 2: Generate SSH Keys (if you don't have them)

```bash
ssh-keygen -t ed25519 -C "github-deploy@talksasa.cloud"
# Save as: /home/username/.ssh/talksasa_deploy
# Press Enter for passphrase (leave empty for automation)

# Display the key
cat ~/.ssh/talksasa_deploy
# Copy this entire key (-----BEGIN... to ...END-----)
# Paste into STAGING_SSH_KEY and PROD_SSH_KEY secrets
```

### Step 3: Add SSH Keys to Servers

On each server (staging & production):
```bash
# As deploy user
ssh deploy@server

# Add key to authorized_keys
echo "your-public-key-content" >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

### Step 4: Configure Branch Protection (optional but recommended)

Go to: **Settings → Branches → Add rule**

For `main` branch:
- ✅ Require pull request before merging
- ✅ Require 1 approval
- ✅ Require status checks to pass (tests)
- ✅ Require branches up to date
- ❌ Allow force pushes (disable)
- ❌ Allow deletions (disable)

---

## 📋 Workflow: How Code Gets to Production

### Daily Workflow (Feature Development)

```
1. Create feature branch from develop
   git checkout develop
   git pull origin develop
   git checkout -b feature/my-feature

2. Make commits with clear messages
   git add .
   git commit -m "Description of change"

3. Push to GitHub
   git push -u origin feature/my-feature

4. Create Pull Request on GitHub
   - Describe what changed and why
   - Link any related issues
   - GitHub Actions runs tests automatically

5. Tests pass ✓
   - All tests green
   - Code review approved

6. Merge PR to develop
   - Click "Merge pull request"
   - Workflow auto-deploys to STAGING

7. Staging deployment starts
   - Code pulled to staging server
   - Migrations run
   - Services restart
   - Slack notification sent
```

### Production Release (Every Friday, for example)

```
1. Create Release PR: develop → main
   git checkout develop
   git pull origin develop
   git checkout -b release/version-number

2. Describe release in PR
   - What features/fixes included
   - Any migrations or config needed
   - Testing done

3. Get approval from maintainer

4. Merge PR to main
   - Click "Merge pull request"
   - Workflow auto-deploys to PRODUCTION

5. Production deployment starts
   - Tests run first (must pass)
   - Automatic backup created
   - Code pulled to production
   - Migrations run
   - Health check performed
   - Slack notification sent

6. If anything fails
   - Automatic rollback to previous backup
   - Slack alert sent
   - Manual investigation needed
```

### Emergency Hotfix (Production Issue)

```
1. Create hotfix branch from main
   git checkout main
   git pull origin main
   git checkout -b hotfix/critical-issue

2. Fix the issue
   git commit -m "[HOTFIX] Description"
   git push -u origin hotfix/critical-issue

3. Create PR to main with [HOTFIX] prefix
   - Explain urgency
   - Get quick review
   - Merge to deploy immediately
```

---

## 🛠 Manual Deployment (if needed)

If workflows fail and you need to deploy manually:

### To Staging
```bash
ssh deploy@staging.server
cd /var/www/talksasa-cloud
git fetch origin
git checkout develop
git pull origin develop
composer install --no-dev -q
php artisan migrate --force
php artisan cache:clear
sudo systemctl restart talksasa-queue
echo "✅ Staging deployed manually"
```

### To Production (with backup)
```bash
ssh deploy@prod.server
cd /var/www
BACKUP_TIME=$(date +%Y%m%d_%H%M%S)
cp -r talksasa-cloud talksasa-cloud.backup.$BACKUP_TIME
echo "📦 Backup: talksasa-cloud.backup.$BACKUP_TIME"

cd talksasa-cloud
git fetch origin
git checkout main
git pull origin main
composer install --no-dev -q
php artisan migrate --force
php artisan db:seed --class=CronJobSeeder
php artisan cache:clear
sudo systemctl restart talksasa-queue
sudo systemctl restart talksasa-scheduler

# Verify
php artisan cron:status | head -10
curl -f http://localhost:8000/health
echo "✅ Production deployed manually"
```

### Rollback
```bash
ssh deploy@prod.server
cd /var/www
LATEST_BACKUP=$(ls -t talksasa-cloud.backup.* | head -1)
rm -rf talksasa-cloud.old
mv talksasa-cloud talksasa-cloud.old
mv $LATEST_BACKUP talksasa-cloud
cd talksasa-cloud
sudo systemctl restart talksasa-queue
sudo systemctl restart talksasa-scheduler
echo "✅ Rolled back to $LATEST_BACKUP"
```

---

## 📊 Monitoring Deployments

### View Workflow Status

```bash
# Command line
gh run list
gh run view <RUN_ID>
gh run view --log <RUN_ID>  # See detailed logs

# Web
Go to: https://github.com/samuelmacharia99/cloud/actions
```

### Slack Notifications

All deployments notify Slack automatically:
- ✅ Tests passed
- ✅ Deployment started
- ✅ Deployment completed
- ❌ Deployment failed
- 🔄 Rollback triggered

### Email Notifications

GitHub sends emails for:
- PR reviews requested
- PR approvals
- Workflow failures
- Branch protection violations

---

## 🔍 Troubleshooting

### "Workflow failed: SSH connection denied"

**Problem**: GitHub Actions can't SSH to server

**Fix**:
1. Verify SSH key is in server's `~/.ssh/authorized_keys`
2. Check secret name matches workflow (STAGING_SSH_KEY, PROD_SSH_KEY)
3. Verify key format is correct (should start with -----BEGIN)
4. Test manually: `ssh deploy@server "echo OK"`

### "Tests failed"

**Problem**: PHPUnit or code style checks failed

**Options**:
1. Fix the issue locally and commit again
2. Push a new commit to the same PR
3. Tests run automatically again
4. Once tests pass, PR can be merged

### "Deployment successful but site is broken"

**Immediate Action**:
1. Go to Actions tab → Find failed workflow
2. Check logs for error details
3. Rollback manually (see section above)
4. Create issue to investigate

**Investigation**:
```bash
ssh deploy@server
tail -f /var/www/talksasa-cloud/storage/logs/laravel.log
php artisan cron:status
sudo systemctl status talksasa-queue
```

---

## 📚 Documentation Files

In the repository you'll find:

1. **GITHUB_SETUP.md** — Complete GitHub configuration guide
   - Branch strategy
   - Secrets setup
   - SSH key generation
   - Common tasks
   - Troubleshooting

2. **GIT_WORKFLOW.md** — Local git commands guide
   - Initial setup
   - Daily workflow
   - Common scenarios
   - Useful commands
   - Quick reference

3. **PRODUCTION_CHECKLIST.md** — Pre-production verification
   - System status overview
   - Configuration required
   - Testing checklist
   - Deployment steps
   - Monitoring guidelines

4. **CRON_DEPLOYMENT.md** — Cron automation guide
   - Cron setup steps
   - Configuration options
   - Daily operations
   - Monitoring & alerts
   - Troubleshooting

---

## ✅ Next Steps

### 1. Configure GitHub Secrets (Required)

```
Go to: https://github.com/samuelmacharia99/cloud/settings/secrets/actions
Add: STAGING_HOST, STAGING_USER, STAGING_SSH_KEY
Add: PROD_HOST, PROD_USER, PROD_SSH_KEY
Optional: SLACK_WEBHOOK
```

### 2. Set Up Branch Protection (Recommended)

```
Go to: https://github.com/samuelmacharia99/cloud/settings/branches
Add rule for: main
Check: "Require pull request" + "Require status checks to pass"
```

### 3. Configure Servers

```
On staging server:
  - Add SSH public key to authorized_keys
  - Verify directory /var/www/talksasa-cloud exists
  - Verify deploy user can write to it

On production server:
  - Same as staging
  - Verify sudo systemctl restart commands work without password
    (add to sudoers: deploy ALL=(ALL) NOPASSWD: /bin/systemctl)
```

### 4. Test a Deployment

```
1. Create a test branch
   git checkout develop
   git checkout -b test/deployment
   echo "# Test" >> README.md
   git add README.md
   git commit -m "Test deployment"
   git push -u origin test/deployment

2. Create PR to develop
   Wait for tests to pass
   Merge to develop
   Watch staging deployment in Actions tab

3. Delete test branch
   git push origin --delete test/deployment
```

### 5. Read the Guides

- Developers: Read `GIT_WORKFLOW.md`
- DevOps/Maintainer: Read `GITHUB_SETUP.md`
- Pre-launch: Read `PRODUCTION_CHECKLIST.md`

---

## 🎯 Summary

✅ **Repository**: https://github.com/samuelmacharia99/cloud
✅ **Code**: Fully pushed with 15+ commits
✅ **Workflows**: 3 GitHub Actions workflows ready
✅ **Documentation**: Comprehensive guides included
✅ **Templates**: PR and issue templates configured

**What's left**: Configure 3 secrets on GitHub, then you're ready to deploy! 🚀
