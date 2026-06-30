sudo scripts/reseller-ssl/provision.sh \
  --domain YOUR-BRANDING-DOMAIN.example.com \
  --webroot /var/www/talksasa-cloud/public \
  --email info@talksasa.com \
  --logs-dir /var/www/talksasa-cloud/storage/app/ssl-provisioning/manual/logs

# One-time host setup (if not done):
# sudo bash scripts/reseller-ssl/install-host.sh

# Queue SSL for all eligible reseller domains (cron also runs this):
# php artisan cron:provision-reseller-ssl

---

## Session handoff (2026-06-28) — resume here

**Latest commit on `main`:** `9d0df929` — synced with `origin/main`.

### Shipped this session (commits on main, newest first)

| Commit | Summary |
|--------|---------|
| `9d0df929` | Block product deletion when billing history or system dependencies exist (`Product::canBeDeleted()`, friendly admin error) |
| `0852fc2c` | Fix admin customer show 500 — missing `UserCurrencyService` import in `CustomerController` |
| `0d19b1bc` | Fix reseller VPS renewal invoicing — wholesale pricing via `ServiceRenewalPricingService` + `custom_price` on server orders |
| `191a80bf` / `08d2dadf` | Platform registration requires phone → SMS + email verification (not on reseller invite/custom domain) |
| `07b876db` | Register page layout overflow fix on small screens |
| `57768ad1` | Rebuild register form as shared partial with split first/last name |
| `66b1cd41` | Auto-submit paid domain orders to Openprovider after wholesale payment (`RegistrarFulfillmentService`) |

### Business rules to remember

- **Platform `/register`** → phone required → verify via email **and** SMS. Reseller signed URL / white-label domain → phone **not** required.
- **Monthly service renewals** → invoice **10 days** before `next_due_date` (`service_monthly_invoice_advance_days`). Annual → 30 days.
- **Reseller platform package** → `cron:generate-reseller-invoices` (10-day window).
- **Reseller-owned VPS/dedicated** (user is reseller, `user.reseller_id` null) → included in `cron:generate-invoices`; bill wholesale via `custom_price` / `ServiceRenewalPricingService`.
- **Reseller customers' services** → excluded from platform `cron:generate-invoices` (`whereNull('reseller_id')` on user).
- **System product** `platform-reseller-directadmin-hosting` — cannot delete; deactivate instead (`Product::deletionBlockers()`).

### Register / verification

- Canonical form: `resources/views/auth/partials/register-form.blade.php`
- Wrappers: `auth/register.blade.php`, `auth/register-premium.blade.php`
- Layout: `layouts/auth-premium.blade.php` (scrollable form area)
- Backend: `RegistrationContextService::requiresPhoneCapture()`, `ValidKenyanMobilePhone`, `PhoneHelper`, `EmailVerificationService`
- **Bug fixed:** `verify-code.blade.php` — nested `@if/@endif` on same line caused 500; split to separate lines.

### Reseller server billing

- `Reseller/ServerController` stores `custom_price` on order
- `ServiceRenewalPricingService` — wholesale pricing for reseller-owned VPS/dedicated renewals
- Wired into `GenerateInvoicesCommand` and `GenerateInvoicesByDateCommand`

### Product deletion guard

- `Product::canBeDeleted()`, `deletionBlockers()`, `deletionBlockedMessage()`
- `Admin/ProductController::destroy()` returns friendly error instead of FK 500
- Tests: `tests/Unit/Models/ProductDeletionTest.php`

### DirectAdmin sync (resolved)

- **Symptom:** `DirectAdmin admin API call failed` — cURL error 28 on `CMD_API_SHOW_USER_CONFIG` via `cron:sync-service-live-status`
- **Cause:** App server could not reach DA port 2222 (firewall/DA down/wrong URL)
- **Status:** User fixed infrastructure — no code change needed
- **Optional future:** mark node offline on repeated timeouts; downgrade connection errors to WARNING in background polls

### Domain auto-fulfill (`66b1cd41`)

- `RegistrarFulfillmentService` auto-submits to Openprovider after wholesale payment
- Wired in `DomainPushService` (all payment paths)
- Tests for auto-fulfill and no-refund on registrar failure

### Production deploy

```bash
cd /var/www/talksasa-cloud
git pull
php artisan optimize:clear
php artisan view:clear
sudo bash deploy.sh   # if used on servers.talksasa.com
```

### Verify on production when back

1. `/register` — split name fields, phone on platform URL, layout on mobile
2. Email/SMS verification flow after platform signup
3. `/admin/customers/{id}` — no 500 (UserCurrencyService)
4. Delete product #32 (shell product) — friendly block message, not 500
5. Reseller VPS renewal invoices — wholesale amount, 10-day advance window
6. DirectAdmin live status sync — no repeated timeout 28 errors

### Key files (this session)

| Area | Files |
|------|-------|
| Register UI | `resources/views/auth/partials/register-form.blade.php`, `layouts/auth-premium.blade.php` |
| Register backend | `RegisteredUserController`, `RegisterUserRequest`, `RegistrationContextService` |
| Verification | `EmailVerificationCodeController`, `auth/verify-code.blade.php` |
| Invoice schedule | `InvoiceGenerationScheduleService`, `GenerateInvoicesCommand`, `GenerateResellerInvoicesCommand` |
| Reseller billing | `Reseller/ServerController`, `ServiceRenewalPricingService` |
| Product delete | `app/Models/Product.php`, `Admin/ProductController.php` |
| Admin customer | `Admin/CustomerController.php` |
| DirectAdmin | `DirectAdminService`, `ServiceStatusSyncService`, `SyncServiceLiveStatusCommand` |
| Domain fulfill | `RegistrarFulfillmentService`, `DomainPushService` |

### Not done / optional next

- Guest checkout (`public/checkout.blade.php`) still uses single name field — platform phone not wired there if desired
- Admin report: reseller VPS services in renewal window but missing invoices
- DirectAdmin resilience (node offline flag, log level downgrade on poll failures)
- Run: `php artisan test --filter=Registration`, `PlatformRegistrationPhone`, `ResellerServerRenewal`, `ProductDeletion`

---

## Session handoff (2026-06-17) — older

**Latest commit on `main`:** `2d4d59c4` — pushed.

### Shipped this session (commits on main, newest first)

| Commit | Summary |
|--------|---------|
| `2d4d59c4` | White-label reseller customer emails: no "Reseller"/"platform" wording; branded support team on staff ticket replies; welcome mail uses reseller portal URL; password-changed subject uses company name |
| `94fb4239` | Fix admin 403 on `/admin/tickets/{id}` — `handled_by` enum vs string bug in `TicketRoutingService::isVisibleToAdmin()`; admin-created tickets for reseller customers are platform-handled |
| `8d724bae` | Shared `invoice-payment-modal` for customer + reseller invoice pay flows |
| `80d9cac5` | Reseller wallet M-Pesa: treat Safaricom "still processing" as pending; failure Telegram alerts; polling UX |
| `fd7063dd` | M-Pesa settlement centralization; domain transfer failure enum fix; unique reseller mark-paid payment refs |
| `1f1f1ff3` | Admin can edit domain transfer details (EPP, etc.) before registrar push |

### Admin ticket 403 fix (`94fb4239`)

- **Symptom:** Ticket listed on admin dashboard/index but `/admin/tickets/2` returned 403; attachment images also 403.
- **Cause:** `isVisibleToAdmin()` compared cast enum to string (`enum === 'platform'` → always false).
- **Fix:** Use `$ticket->isHandledByPlatform()` / `isHandledByReseller()`; admin `TicketController::store` uses `attributesForAdminCreator()`.
- **Deploy:** `git pull && php artisan optimize:clear`

### White-label customer emails (`2d4d59c4`)

- Helpers: `email_support_team_label()`, `email_reply_author_name()`, `email_is_white_label()`
- Templates: `account-welcome`, `ticket-created`, `ticket-replied`, `ticket-escalated-customer`
- Mailables: `AccountWelcomeMail`, `PasswordChangedMail`, `TicketEscalatedCustomerMail`
- **Deploy:** include `php artisan view:clear` (Blade email templates changed)
- Tests: `tests/Unit/Services/ResellerCustomerEmailBrandingTest.php` (5 tests, green)

### Reseller branding domain SSL

```bash
# Manual (same as Settings → Branding → Provision SSL):
sudo /var/www/talksasa-cloud/scripts/reseller-ssl/provision.sh \
  --domain reseller-branding.example.com \
  --webroot /var/www/talksasa-cloud/public \
  --email info@talksasa.com \
  --logs-dir /var/www/talksasa-cloud/storage/app/ssl-provisioning/manual/logs

# Renew:
# ... same command with --renew
```

Docs: `docs/RESELLER_SSL.md`

### Invoice payment modal (`8d724bae`)

- Component: `resources/views/components/invoice-payment-modal.blade.php`
- Used on: `customer/invoices/show`, `reseller/invoices/show`
- `Customer\PaymentController::selectMethod()` returns JSON when `Accept: application/json`
- **Not done:** auto-open modal with `?openPayment=1` after domain checkout

### M-Pesa / Telegram (from prior sessions, still relevant)

- Success → `notifyPaymentReceived()` → Telegram Payments category
- Webhook failures + wallet polling false-failures → `notifyPaymentFailed()`
- Requires `telegram_monitor_enabled`, `telegram_monitor_payments`, bot token/chat id

### Production deploy

```bash
cd /var/www/talksasa-cloud
git pull
php artisan optimize:clear
php artisan view:clear
```

### Open / verify on production

1. **Admin tickets** — open ticket from dashboard; attachments load (`94fb4239`)
2. **Reseller customer emails** — welcome, ticket reply, escalation: no "Reseller"/"Talksasa"/"platform" in body (`2d4d59c4`)
3. **Invoice pay modal** — customer + reseller invoice show pages (`8d724bae`)
4. **Reseller wallet M-Pesa top-up** — STK success should not show false failure (`80d9cac5`)
5. **bonumdigital.com (order #17)** — Openprovider `.com` contract, then Push to registrar
6. **DirectAdmin credentials** — resend for services with old server-hostname panel URLs
7. **SQLite local tests** — full PHPUnit may fail on `sms_templates` ENUM migration (MySQL-only ALTER)

### Key files (this session)

| Area | Files |
|------|-------|
| Ticket 403 | `app/Services/TicketRoutingService.php`, `app/Policies/TicketPolicy.php` (via service), `tests/Feature/TicketRoutingTest.php` |
| White-label email | `app/Helpers/helpers.php`, `app/Mail/*`, `resources/views/emails/*`, `app/Services/TicketNotificationService.php` |
| Payment modal | `resources/views/components/invoice-payment-modal.blade.php`, `app/Http/Controllers/Customer/PaymentController.php` |
| M-Pesa | `app/Services/PaymentGateway/MpesaService.php`, `app/Http/Controllers/PaymentWebhookController.php`, `app/Http/Controllers/Reseller/WalletController.php` |

### Local uncommitted (ignore)

- `database/database.sqlite`
- `docs/PLATFORM_WEBSITE_COPY.md` — marketing copy draft (not committed)
- `storage/logs/`, `storage/framework/sessions/*`
- This file (`scripts/important.md`) — handoff notes only

### Not done / next when back

- Verify production deploy after `2d4d59c4`
- Optional: `?openPayment=1` auto-open invoice payment modal after checkout
- Resend DirectAdmin credentials where panel URL still uses server hostname
- bonumdigital.com order #17 registrar push after Openprovider contract
- Optional: commit `docs/PLATFORM_WEBSITE_COPY.md` if marketing copy is approved
