# Container / App Hosting Roadmap

**Status:** Active major initiative  
**Last updated:** 2026-07-11  
**Goal:** Grow on container (application) hosting, keep **one** DirectAdmin license for email + legacy shared hosting leftovers, and eventually make DA optional.

---

## Strategic context

Classic shared hosting (DirectAdmin / cPanel) margins are shrinking. Talksasa already has a production-shaped container console at:

`/my/services/{service}/container`

We will **evolve** that console into a first-class app hosting product (not rebuild from scratch), and add a **DA → container migration pipeline** so existing customers can move without pain.

### Product split

| Product | Role |
|---------|------|
| **Container / app hosting** | Growth SKU — Laravel, Node, PHP, WordPress templates, modern apps |
| **One DirectAdmin license** | Email + residual shared / Softaculous-style leftovers until mail is a separate product |

---

## Current console inventory (baseline)

Already solid: lifecycle, domains/SSL, files, terminal, git (Laravel/Node), cron, PHP extensions, Laravel setup, metrics/health.

Partial / weak: auto-deploy, streaming logs, WordPress polish, phpMyAdmin-class DB UI.

Not covered by containers: email, Softaculous catalog, classic multi-site shared accounts.

**Verdict:** evolve Blade/Alpine console; do not rewrite controllers/services.

---

## Phases

### Phase 1 — Make containers the default product *(complete)*

**Goal:** New customers choose containers; DA stops growing feature investment.

| # | Deliverable | Notes |
|---|-------------|--------|
| 1.1 | Console IA regroup | Real tabs only (no fake App/Data/… labels); mobile optgroups OK |
| 1.2 | Environment / Secrets panel | CRUD env vars; restart/apply; platform DB keys protected |
| 1.3 | Backup UX | Downtime warning, scheduled backup status, clearer restore copy |
| 1.4 | Harden Laravel + Node path | Force rebuild, runtime skip fix, docs → Environment |
| 1.5 | Positioning | Checkout/marketing: app hosting vs shared email/legacy |

**Success:** Customers manage apps without opening Files for `.env`; console feels like PaaS, not 11 flat tabs.

---

### Phase 2 — PaaS parity *(MVP complete)*

**Goal:** Compete with Render / Railway for modern apps.

| # | Deliverable | Notes |
|---|-------------|--------|
| 2.1 | GitHub/GitLab webhook auto-deploy | `ContainerAutoDeployService` + public webhook + Git tab UI |
| 2.2 | Streaming deploy/build logs | Faster git poll (1.5s); Logs tab live follow (2s) |
| 2.3 | Richer DB UI or embedded Adminer | Table browser + existing read-only SQL console |
| 2.4 | Self-serve plan resize | `CustomerContainerPlanChangeService` + upgrade UI |
| 2.5 | Container→container **data-aware** migrate | Tar/SFTP volume copy in `ContainerMigrationService` |

---

### Phase 3 — Shared-hosting escape hatch (DA → container) *(WordPress MVP)*

**Goal:** Seamless site migrations off DirectAdmin. Keep one DA box for mail.

> **Important:** This is **not** “restore a DA backup into a container.”  
> DA/cPanel backup formats ≠ container `.tar.gz`. We build an **ETL migration wizard**.

| # | Deliverable | Notes |
|---|-------------|--------|
| 3.1 | WordPress migrator MVP | Inventory + queue job + customer/admin wizards |
| 3.2 | Laravel / plain PHP migrator | Next |
| 3.3 | Static / custom PHP | |
| 3.4 | Admin/customer wizard UI | Dry-run inventory + progress on target overview |
| 3.5 | cPanel path | Only if needed — zero cPanel code today |

**Email reality:** Site + DB + domain can be seamless. Mailboxes stay on DA until a separate mail product exists.

---

### Phase 4 — Platform differentiation *(foundations)*

**Goal:** Own regional app hosting; DA becomes optional.

| # | Deliverable | Notes |
|---|-------------|--------|
| 4.1 | Staging / preview environments | Link sibling container + sync env (MVP) |
| 4.2 | Curated app templates | Softaculous replacement (apps only) — existing stacks + docs polish |
| 4.3 | Mail as separate product | Unlocks dropping DA entirely — **not started** |
| 4.4 | Team access / BYO Dockerfile | Up-market — **not started** |

---

## Recommended build order

1. **Phase 1** — console polish + secrets ✅  
2. **Phase 2** — auto-deploy + logs + resize + data migrate ✅ MVP  
3. **Phase 3.1** — WordPress DA→container MVP ✅  
4. Expand migrator (Laravel/PHP) + polish staging  
5. **Phase 4.3** — mail product → DA optional  

---

## Key code references

| Area | Location |
|------|----------|
| Customer console | `resources/views/customer/services/container.blade.php` |
| Container controller | `app/Http/Controllers/Customer/ContainerController.php` |
| Auto-deploy | `app/Services/Provisioning/ContainerAutoDeployService.php` |
| Plan resize | `app/Services/Customer/CustomerContainerPlanChangeService.php` |
| Data-aware node migrate | `app/Services/Provisioning/ContainerMigrationService.php` |
| DA→WP migrator | `app/Services/Provisioning/DirectAdminToContainerMigrationService.php` |
| Staging link | `app/Services/Provisioning/ContainerStagingService.php` |
| Routes | `routes/web.php` (~container + migrate-to-app) |

---

## Progress log

| Date | Update |
|------|--------|
| 2026-07-11 | Roadmap created. Phase 1 started. |
| 2026-07-11 | Phase 1.1–1.3: console IA, Environment & Secrets panel, backup UX. |
| 2026-07-11 | **Phase 1 complete:** Node `force_rebuild`, Laravel double-runtime skip removed, DB sync soft messaging, product labels + techstack/checkout/cart copy, docs → Environment. |
| 2026-07-11 | **Phase 2 MVP:** auto-deploy webhook + Git UI, live logs, DB table browser, container plan resize, data-aware node migrate. |
| 2026-07-11 | **Phase 3.1 MVP:** WordPress DA→container ETL (inventory, job, customer/admin wizards, progress on target). |
| 2026-07-11 | **Phase 4.1 foundation:** staging sibling link + env sync. |
| 2026-07-11 | Added QA testing checklist for Phases 1–4 MVP. |
| 2026-07-11 | Fixed console tab IA: removed fake App/Data/… labels; Docs tab always mounts for deep links. |

### Phase checklists

**Phase 1:** Done  

**Phase 2:**
- [x] 2.1 Auto-deploy webhooks
- [x] 2.2 Live / faster deploy logs
- [x] 2.3 DB table browser
- [x] 2.4 Self-serve plan resize
- [x] 2.5 Data-aware C→C migrate

**Phase 3:**
- [x] 3.1 WordPress migrator MVP
- [ ] 3.2 Laravel / PHP migrator
- [x] 3.4 Wizard UI (dry-run + queue)

**Phase 4:**
- [x] 4.1 Staging link MVP
- [ ] 4.3 Mail product
- [ ] 4.4 Team / BYO Dockerfile

---

## QA testing checklist

Use this on a staging/production-like environment with a real container host (and a DA node for migration tests). Check off as you go. Prefer one Laravel, one Node, and one WordPress service where noted.

### Preconditions

- [ ] Queue worker running (`php artisan queue:work` or Horizon) — git pulls, auto-deploy, and DA migrator jobs need it
- [ ] At least one active `container_host` node with SSH
- [ ] Customer account with an active App Hosting service (Laravel or Node for git; WordPress for migrator target)
- [ ] (Migration only) Same customer has an active DirectAdmin shared hosting WordPress site
- [ ] (Plan resize only) Two+ active products sharing the same `container_template_id` with different CPU/RAM

### Phase 1 — Console & positioning

- [ ] `/my/services` and checkout/techstack show **App Hosting** / **Shared (email & legacy)** labels (not raw `container_hosting`)
- [ ] Container console shows only real tabs (Overview, Environment, … Docs) — not App/Data/Network/Ops/Help as clickable items
- [ ] `?tab=documentation` opens Docs with stack-specific guide content
- [ ] **Environment** tab: add a custom env var → Save & apply → container restarts → value persists after refresh
- [ ] **Environment** tab: delete a custom key → applied and gone after refresh
- [ ] Platform DB keys still present after edits; changing them does not leave the stack broken (or Repair Credentials recovers)
- [ ] **Backups**: create backup shows downtime warning; scheduled “next due ~24h” messaging appears when a prior backup exists
- [ ] Laravel: Git pull does not incorrectly skip a completed runtime step on a second pull
- [ ] Node: **Force clean rebuild** runs a full rebuild path when checked
- [ ] Docs / Laravel setup / terminal copy points at **Environment** (and Git) where relevant

### Phase 2 — PaaS parity

#### Auto-deploy (Git tab)

- [ ] Save a public (or PAT-backed private) repo + branch
- [ ] **Enable auto-deploy** → one-time secret shown once; copy URL + secret
- [ ] Webhook rejects bad token (`401` / invalid token)
- [ ] Push (or `curl`) to connected branch with `X-Talksasa-Token` → pull queued (`202`); pipeline UI updates
- [ ] Push to a **different** branch → ignored message, no new pull
- [ ] **Disable auto-deploy** → webhook no longer queues
- [ ] **Rotate secret** → old secret fails; new secret works
- [ ] GitLab-style: token via `X-Gitlab-Token` or `?token=` works

#### Logs & git progress

- [ ] During an active git pull, steps + terminal output refresh ~1.5s
- [ ] **Logs** tab: Refresh loads last ~200 lines
- [ ] **Logs** tab: **Live follow** updates ~every 2s and scrolls while new lines appear
- [ ] Overview log preview still loads

#### Database tab

- [ ] Credentials / test connection / (Laravel) repair credentials work
- [ ] **Table browser**: Refresh tables lists tables; click table previews ≤25 rows
- [ ] Read-only SQL console: `SELECT` / `SHOW` succeed; write query rejected
- [ ] SQL import (small dump) succeeds when console enabled

#### Plan resize

- [ ] Container overview **Change plan** opens upgrade UI with CPU/RAM/disk options (same template only)
- [ ] Upgrade with prorated charge → unpaid invoice → pay → limits applied (CPU/memory on deployment + stack)
- [ ] Downgrade / lateral (KES 0) applies without payment friction (or paid £0 invoice settles)
- [ ] Wrong-template products do **not** appear in the list
- [ ] Shared hosting **Change plan** still works (regression)

#### Data-aware node migrate (admin)

- [ ] Admin → container service → **Migrate Node**
- [ ] Warning copy mentions data/volumes are copied (not “fresh redeploy only”)
- [ ] After migrate: app files + DB data still present; service runs on target node
- [ ] Source node cleaned up (or cleanup warning only if best-effort fail)

### Phase 3 — DA → WordPress container

#### Customer wizard

- [ ] Shared hosting service shows **Move to App Hosting**
- [ ] `/my/services/{id}/migrate-to-app` shows dry-run inventory (user, domain, stack, docroot, DBs, warnings)
- [ ] Without a WordPress container target: CTA to deploy App Hosting
- [ ] With target: must accept “email stays on DA” checkbox
- [ ] Queue migration → redirects to **target** container overview with status banner (`queued` → `running` → `completed` / `failed`)
- [ ] On success: WP files in container, DB imported, site loads; mailboxes still on DA
- [ ] DNS caveat understood: site may need DNS/SSL update to container URL/domain

#### Admin wizard

- [ ] Admin shared hosting → **Migrate to App Hosting** inventory + queue works
- [ ] Admin container → **Migrate Node** still available for C→C moves

### Phase 4 — Staging foundation

- [ ] Overview **Staging environment**: link a same-stack sibling container
- [ ] **Sync env to staging** copies non–platform-managed vars; staging restarts
- [ ] **Unlink** clears the relationship
- [ ] Different-template sibling does not appear (or link is rejected)

### Smoke / regression

- [ ] Start / stop / restart / Visit service
- [ ] Domains bind + SSL path unchanged
- [ ] Files + terminal still open
- [ ] Cron list/add/delete (if used)
- [ ] Customer cannot open another user’s container routes (`403`)
- [ ] CSRF: browser forms work; webhook path is CSRF-exempt

### Sign-off

| Area | Tester | Date | Pass? | Notes |
|------|--------|------|-------|-------|
| Phase 1 | | | | |
| Auto-deploy | | | | |
| Logs / DB / resize | | | | |
| Node migrate | | | | |
| DA→WP migrator | | | | |
| Staging | | | | |
| Regression smoke | | | | |

---

## Decisions

- Evolve existing console; no full UI rewrite.  
- One DA license retained for email/legacy.  
- Migrations = ETL wizard, not backup-format restore.  
- WordPress first for DA→container.  
- Do not expand DirectAdmin feature investment.
