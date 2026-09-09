# CLAUDE.md

Guidance for Claude Code (claude.ai/code) when working in this repository.

## What this system is

**Talksasa Cloud** is the control plane for a commercial hosting business. It is not only a billing
app: it sells, provisions, operates, bills, and repairs live infrastructure that customers depend on.
Changes here move real money and touch running customer workloads.

Four product lines are provisioned from one Laravel 11 codebase:

| Product line | Driver key | Backing technology |
|---|---|---|
| Application hosting (PaaS) | `container` | Docker Compose stacks on remote nodes, driven over SSH |
| Shared hosting | `directadmin` | DirectAdmin accounts and packages |
| Email hosting | `mailcow` | Mailcow mailboxes and mail DNS |
| Dedicated / VPS | `server` | Direct server provisioning |
| Domains | (none) | Registrar drivers: Cosmotown, Openprovider, manual, custom |

On top sits a **white-label reseller tier** that is a genuine second tenant: its own storefront on its
own domain, its own catalogue, pricing and margins, its own wallet and billing cycle, its own public
API with scoped tokens and CORS, and its own SSL provisioning.

**Tech stack**: Laravel 11, PHP 8.2+, MySQL 8.0+, Tailwind CSS, Alpine.js, Vite, Laravel Reverb
(container terminal websocket), Sanctum (API tokens), phpseclib (SSH/SFTP to nodes).

**Scale**: ~167k lines in `app/`, ~72k in Blade views, ~65k in tests (2,200+ tests). Over half of the
service layer is provisioning. Treat this as a large, long-lived system, not a greenfield project.

---

## Engineering bar

This is production infrastructure with paying customers. Hold to it:

- No placeholder, demo, or happy-path-only code. Handle failure, partial state, and retries explicitly.
- Business logic lives in `app/Services`, never in controllers or Blade.
- Authorization, tenancy scoping (reseller and customer), and input validation are part of "done".
- New behaviour ships with tests. The suite is large and green; keep it that way.
- Do not grow the known god classes (see *Known hazards*). Extract instead of appending.
- Never widen what the customer terminal or file manager can reach without reviewing
  `TerminalSecurityGuard` and `config/security.php`.

---

## Commands

### Setup
```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
```

### Development
```bash
php artisan serve                 # http://localhost:8000
npm run dev                       # Vite
php artisan queue:work            # REQUIRED for git pulls, provisioning, migrations, backups
php artisan container:terminal-ws      # websocket PTY bridge for the browser terminal
```

`QUEUE_CONNECTION=sync` in local `.env` runs jobs inline. Provisioning, git pulls, and DirectAdmin
migrations are long-running; use a real queue connection and a worker when testing those paths.

### Quality gates
```bash
php artisan test                          # full suite
php artisan test --filter=ContainerDeploy  # focused
./vendor/bin/pint                          # format (CI checks but does not block)
```

Larastan is installed but has no `phpstan.neon` in the repository, so static analysis is not wired
up. Add a config before relying on it.

CI (`.github/workflows/test.yml`) runs migrations, seeders, and the full suite against MySQL 8 on
push and PR to `main` / `develop`.

### Operations
```bash
php artisan cron:status            # scheduler health
php artisan security:audit
php artisan settings:audit
php artisan schedule:list
```

---

## Request lifecycle

1. **Routing** — `routes/web.php` (~960 lines, grouped by role), `routes/api.php` (Sanctum),
   `routes/auth.php`.
2. **Global middleware** (`bootstrap/app.php`) — `ResolveResellerTenant`, `SecurityHeaders`,
   `LogActivity`, plus a custom `VerifyCsrfToken` that exempts payment webhooks.
3. **Route middleware aliases** — `admin`, `customer`, `reseller`, `reseller.limits`,
   `reseller.billing`, `reseller.host`, `reseller.customer.catalog`, `reseller.public.api*`,
   `skip.verification.if.impersonating`, `registration.throttle`, `admin.attention.seen`.
4. **Policy check** — `$this->authorize('manageContainer', $service)` and friends.
5. **FormRequest validation** — `app/Http/Requests` (53 classes).
6. **Service layer** — `app/Services`.
7. **Response** — Blade views by role, or JSON.

Custom exception rendering in `bootstrap/app.php` handles 419 session expiry, oversized container
uploads (413), and Doctor treatment throttling (429). Extend there rather than in controllers.

---

## Provisioning architecture (the core)

`ProvisioningService::provision()` is the single entry point. It reads
`service.provisioning_driver_key` (falling back to the product's) and dispatches to the driver.
On failure it records to `ProvisionFailureLedger`, marks the service failed, and notifies.

For containers, `ContainerDeploymentService::deploy()` does the work over SSH:

1. Select a node with capacity (`selectLeastLoadedHost`, `assertHostHasCapacity`).
2. Assign a port from the reserved range.
3. Detect the application runtime from the repository (`ContainerApplicationRuntimeService`,
   `ContainerRuntimeInspector`, `ApplicationRuntime`).
4. Generate and patch a Docker Compose file, including database and web sidecars.
5. Push it to the node via `SSHService` (phpseclib), bring the stack up.
6. Wait for readiness: container running, HTTP health, database sidecar reachable, credentials valid.
7. Bind domains and provision SSL (`ContainerDomainBindingService`, `NginxProxyService`).

Supporting subsystems in `app/Services/Provisioning` (86 classes):

- **Runtime detection** for Laravel, WordPress, Node, Next, Expo, Vite SPAs, Python/FastAPI/Django,
  Ruby/Rails, Go, PHP legacy and CodeIgniter, static sites. This is the active frontier and a long
  tail of real-world repository shapes.
- **`ContainerDoctorService`** — diagnoses and repairs broken deployments.
- **Migration** — DirectAdmin to container, DirectAdmin to Mailcow, container to container across
  nodes, WordPress convert-in-place.
- **Node fleet** — capacity, hardware probes, evacuation, workload topology, version management.
- **Backups** — container backups to Hetzner Storage Box with retention.

### Editing rules for this area

- Compose YAML is patched through the dedicated `patchCompose*` / `mergeCompose*` helpers. Do not
  hand-roll string manipulation on compose files.
- Every remote command goes through `SSHService::exec` with an explicit timeout. Never interpolate
  unescaped user input into a shell string.
- Readiness is proven by polling a `waitFor*` helper, never by sleeping.

---

## Directory map

| Path | Contents |
|---|---|
| `app/Models` (61) | `Service`, `Node`, `ContainerDeployment`, `Invoice`, `Payment`, `Domain`, `ResellerWallet`, `CustomerProject`, … |
| `app/Http/Controllers` (120) | `Admin/`, `Customer/`, `Reseller/`, `Api/V1/`, `Auth/` |
| `app/Services` (254) | See breakdown below |
| `app/Console/Commands` (74) | Scheduled operations: invoicing, suspension, renewals, metrics, health |
| `app/Console/Scheduling` | `ApplicationSchedule` — schedule is DB-driven from `CronJob` records |
| `app/Jobs` (15) | Provisioning, git pull, backups, migrations, broadcast email, Telegram alerts |
| `app/Enums` (14) | `ServiceStatus`, `InvoiceStatus`, `PaymentStatus`, `PaymentMethod`, `RegistrarDriver`, … |
| `app/Policies` (12) | Ownership and role authorization |
| `app/Support` | Cart, currency formatting, console tabs, production command guard |
| `deploy/` | systemd units for scheduler and queue workers, nginx snippets, supervisor config |
| `scripts/` | Node bootstrap, queue worker install, Mailcow and reseller SSL setup |
| `docs/` (47) | Runbooks and roadmap; several are historical phase reports, trust the code first |

### Service layer breakdown

- `Provisioning/` (86) — everything above.
- `PaymentGateway/` — M-Pesa (STK push + reconciliation), Stripe, PayPal, bank transfer, manual,
  behind `PaymentGatewayInterface` / `PaymentGatewayFactory`.
- `Registrar/` — `RegistrarDriverInterface` with Cosmotown, Openprovider, manual and custom drivers,
  plus TLD price and inventory sync.
- `Billing/`, `Checkout/` — invoice numbering, currency, settlement, renewal pricing, and the
  per-product checkout paths (shared hosting, project hosting, email, domains).
- `Terminal/` — websocket PTY bridge into containers, with `TerminalSecurityGuard` denylisting
  privilege escalation, Docker control, and namespace escapes.
- `Hosting/`, `Dns/`, `SSH/`, `Cron/`, `Telegram/`, `Admin/`, `Customer/`.
- Top level (~100) — reseller subsystem, domains lifecycle, notifications, credit and wallet, tax,
  currency, security, two-factor.

---

## Conventions

**Enums over strings.** `$payment->status = PaymentStatus::Completed;` Models cast to enums.

**Policies before action.** `$this->authorize('view', $payment);`

**FormRequests for input.** Controllers receive validated data only.

**Reseller scoping is not optional.** Any query reachable by a reseller or their customers must be
scoped by tenant. `ResolveResellerTenant`, `ResellerScopeService` and the `reseller.*` middleware
exist for this; use them rather than ad-hoc `where` clauses.

**Blade components.** `<x-status-badge :status="..." type="payment" />`,
`<x-currency-formatter :amount="..." currency="KES" />`, `<x-data-table>`, `<x-modal>`.

**Migrations only** for schema (203 and counting). Never alter tables by hand.

---

## Payments

| Method | Flow |
|---|---|
| M-Pesa | STK push, callback at `POST /mpesa/callback` (public, CSRF-exempt), plus a reconciliation service and a cron for pending payments |
| Stripe | Hosted checkout, return to `/invoices/{invoice}/payment/stripe/success\|cancel` |
| PayPal | Same shape, plus PayPal Connect for resellers |
| Bank transfer / manual | Customer submits proof, admin approves |
| Wallet / credit | Internal balance via `CreditService`, `ResellerWalletService` |

Settlement is centralised in `Billing/InvoiceSettlementService`. Route payment completion through it
so credit, tax, currency, and reseller margin stay consistent.

---

## Known hazards

- **`ContainerDeploymentService`** (~339KB, 225 methods) and **`ContainerDoctorService`** (~344KB)
  are god classes. They are the highest-risk files in the repository. Extract cohesive units rather
  than adding methods.
- **`resources/views/admin/settings/index.blade.php`** (~217KB) should be decomposed into
  components before further growth.
- **Documentation drift.** `docs/` holds completed phase reports from earlier milestones. Verify
  against code before relying on any of them. `docs/CONTAINER_APP_HOSTING_ROADMAP.md` is the current
  one.
- **Pint is non-blocking in CI.** Run it locally before committing.

---

## Debugging

```bash
php artisan tinker
tail -f storage/logs/laravel.log
tail -f storage/logs/cron.log      # rotate with: php artisan scheduler:rotate-log
php artisan cron:status
```

Provisioning failures are recorded per service by `ProvisionFailureLedger` and classified by
`ProvisionFailureClass`. Check there before reading raw logs. Container deployment progress and
events are persisted in `container_deployment_events`.
