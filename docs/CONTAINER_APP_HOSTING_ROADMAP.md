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
| 1.1 | Console IA regroup | Group tabs: **App** / **Data** / **Network** / **Ops** / **Help** |
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

## Decisions

- Evolve existing console; no full UI rewrite.  
- One DA license retained for email/legacy.  
- Migrations = ETL wizard, not backup-format restore.  
- WordPress first for DA→container.  
- Do not expand DirectAdmin feature investment.
