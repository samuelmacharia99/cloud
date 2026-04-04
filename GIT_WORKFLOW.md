# Git Workflow Guide

## Initial Setup (One Time)

### 1. Configure Git Locally

```bash
# Set your name (used in commits)
git config --global user.name "Your Name"
git config --global user.email "your.email@talksasa.cloud"

# Optional: Verify configuration
git config --global --list
```

### 2. SSH Key Setup (for passwordless pushes)

```bash
# Check if you have SSH keys
ls ~/.ssh/id_*.pub

# If not, generate one
ssh-keygen -t ed25519 -C "your.email@talksasa.cloud"
# Press Enter to accept defaults
# Press Enter twice for empty passphrase (optional, or set one)

# Add key to SSH agent
ssh-add ~/.ssh/id_ed25519

# Display your public key
cat ~/.ssh/id_ed25519.pub

# Go to GitHub → Settings → SSH and GPG keys → New SSH key
# Paste the output and save
```

### 3. Clone Repository

```bash
# Using SSH (recommended, no password needed each time)
git clone git@github.com:samuelmacharia99/cloud.git
cd cloud

# Or using HTTPS (requires GitHub token)
git clone https://github.com/samuelmacharia99/cloud.git
cd cloud
```

### 4. Useful Git Aliases

Add these to make Git commands shorter:

```bash
# Add aliases
git config --global alias.st status
git config --global alias.co checkout
git config --global alias.br branch
git config --global alias.ci commit
git config --global alias.unstage 'reset HEAD --'
git config --global alias.last 'log -1 HEAD'
git config --global alias.visual 'log --graph --oneline --all'

# Now you can use: git st, git co, git br, etc.
```

---

## Daily Workflow

### Starting a New Feature

```bash
# 1. Switch to develop branch
git checkout develop

# 2. Update from remote
git pull origin develop

# 3. Create feature branch
git checkout -b feature/your-feature-name

# Naming convention:
# feature/m-pesa-testing
# feature/invoice-pdf
# bugfix/payment-timeout
# hotfix/critical-issue
# docs/api-documentation
```

### Making Changes

```bash
# 1. Check what changed
git status

# 2. Stage changes (add specific files, not everything)
git add app/Services/MpesaService.php
git add database/migrations/2026_04_04_000000_create_payments_table.php

# Or stage all changes (if you're sure)
git add .

# 3. Commit with clear message
git commit -m "Add M-Pesa service with STK push integration

- Implements STK push for customer payments
- Handles Safaricom callbacks
- Auto-updates invoice status on successful payment
- Includes phone normalization for Kenyan numbers"

# 4. Make more commits as you work
git add app/Http/Controllers/Customer/MpesaController.php
git commit -m "Add MpesaController for payment initiation and callbacks"

# 5. Before pushing, see what you're sending
git log origin/develop..HEAD
git diff origin/develop...HEAD
```

### Pushing to GitHub

```bash
# 1. First time pushing this branch
git push -u origin feature/your-feature-name

# 2. Subsequent pushes (shorter command)
git push

# 3. If you need to update the branch with latest develop
git fetch origin
git rebase origin/develop

# If there are conflicts, resolve them then:
git rebase --continue

# Or if it gets messy, abort and try merging instead
git rebase --abort
git merge origin/develop
```

### Creating a Pull Request

```bash
# After pushing, GitHub shows a link to create a PR
# Or go to: https://github.com/samuelmacharia99/cloud/pulls

# 1. Click "Compare & pull request"
# 2. Fill in the PR template
# 3. Set base branch to "develop" (or "main" for hotfixes)
# 4. Describe what you changed and why
# 5. Click "Create pull request"

# GitHub will run tests automatically
# Once tests pass and you get approval, click "Merge pull request"
```

---

## Common Scenarios

### You Made Changes but Need to Switch Branches

```bash
# Option 1: Stash changes (save temporarily)
git stash

# Switch branches
git checkout develop

# Bring changes back
git stash pop

# Option 2: Commit your changes first
git add .
git commit -m "WIP: Feature in progress"

# Switch branches, do your work, then come back
git checkout develop
git checkout feature/your-feature

# Continue working, then amend the commit
git add .
git commit --amend --no-edit
```

### You Made Commits but Need to Edit the Message

```bash
# Last commit
git commit --amend -m "New message"

# Push changes
git push --force-with-lease

# Multiple commits (more complex, ask for help if unsure)
git rebase -i HEAD~3
```

### You Want to See What Changed

```bash
# Changes in working directory (not staged)
git diff

# Changes in staging area (ready to commit)
git diff --staged

# Changes in your branch vs develop
git diff origin/develop...HEAD

# Commits in your branch
git log origin/develop..HEAD
git log --oneline origin/develop..HEAD

# Visual log of all branches
git log --graph --oneline --all
```

### You Messed Up and Want to Undo

```bash
# Undo unstaged changes in a file
git restore app/Services/PaymentService.php

# Undo all unstaged changes
git restore .

# Undo a commit but keep the changes
git reset HEAD~1

# Undo a commit and discard changes
git reset --hard HEAD~1

# Undo last push (only if not merged to main/develop yet)
git reset --soft HEAD~1
git push origin feature/name --force-with-lease
```

### You Want to Update Your Branch with Latest develop

```bash
# Option 1: Rebase (cleaner history)
git fetch origin
git rebase origin/develop

# If conflicts occur, resolve them and:
git add .
git rebase --continue

# Option 2: Merge (simpler, creates merge commit)
git fetch origin
git merge origin/develop

# Resolve any conflicts, then:
git add .
git commit -m "Merge develop into feature/name"
```

### You Need to Recover Deleted Work

```bash
# Find commits you lost
git reflog

# Recover a commit by its hash
git checkout abc1234

# Or create a new branch from it
git checkout -b recovered-feature abc1234
```

---

## Before Pushing to Production (main branch)

### Checklist

```bash
# 1. Update from remote
git fetch origin

# 2. Check your commits
git log origin/main..HEAD

# 3. Check for merge conflicts
git merge --no-commit --no-ff origin/main

# 4. If conflicts exist, resolve them
git merge --abort  # If you want to start over

# 5. Verify your branch is up to date
git rebase origin/main

# 6. Run tests locally (if configured)
composer test

# 7. Review all changes once more
git diff origin/main...HEAD

# 8. You're ready to create a PR to main
git push origin feature/name
# Then create PR on GitHub
```

---

## Useful Commands Reference

```bash
# Status and Log
git status              # Current state
git log                 # All commits in current branch
git log --oneline       # Compact log
git log --graph --all   # Visual tree of all branches
git show <commit>       # Details of a specific commit

# Branches
git branch              # List local branches
git branch -a           # List all branches (local + remote)
git checkout -b name    # Create and switch to new branch
git checkout name       # Switch to existing branch
git branch -d name      # Delete branch (safe)
git branch -D name      # Force delete branch

# Staging and Committing
git add file.php        # Stage specific file
git add .               # Stage all changes
git restore file.php    # Discard unstaged changes
git restore --staged    # Unstage files
git commit -m "msg"     # Commit staged changes
git commit --amend      # Edit last commit

# Pushing and Pulling
git push                # Push commits to remote
git push origin branch   # Push specific branch
git pull                # Fetch and merge remote changes
git fetch               # Fetch without merging
git rebase origin/main  # Rebase on latest main

# Comparing
git diff                # Show unstaged changes
git diff --staged       # Show staged changes
git diff branch1 branch2 # Compare branches

# Cleanup
git stash               # Save changes temporarily
git stash pop           # Restore stashed changes
git clean -fd           # Remove untracked files
```

---

## Quick Reference

### Create and Push a Feature

```bash
git checkout develop && git pull
git checkout -b feature/name
# Make changes...
git add .
git commit -m "Description"
git push -u origin feature/name
# Go to GitHub and create PR
```

### Update Feature with Latest develop

```bash
git fetch origin
git rebase origin/develop
# If conflicts: resolve, then: git rebase --continue
git push --force-with-lease
```

### Sync develop with Latest main

```bash
git checkout develop
git pull origin develop
git rebase origin/main
git push origin develop
```

### Emergency Hotfix (for production issues)

```bash
git checkout main && git pull
git checkout -b hotfix/critical-issue
# Make fix...
git add .
git commit -m "[HOTFIX] Description"
git push -u origin hotfix/critical-issue
# Create PR to main (will auto-deploy to production)
# Then create PR from main back to develop
```

---

## Getting Help

```bash
# Built-in help
git help <command>      # e.g., git help rebase
git --version           # Check your Git version

# Check config
git config --list      # View all settings
git config --list --local   # Local config only
```

## Support

- **Merge conflicts?** See "Update Feature with Latest develop" above
- **Lost commits?** Use `git reflog` and `git checkout <hash>`
- **Wrong branch?** Use `git checkout correct-branch`
- **Need to undo?** Use `git reset` (see examples above)
- **Still stuck?** Ask in the team chat with: `git reflog`, `git status`, and `git log --oneline`
