# Reseller Operations Runbook

This guide covers onboarding and day-two operations for **Talksasa Cloud** resellers.

## Admin onboarding checklist

Before a reseller goes live:

1. **Create reseller account** (Admin → Resellers) with package assignment and DirectAdmin node/username binding.
2. **Fund wallet** (Admin → Reseller Wallets) for wholesale domain registration float.
3. **Verify cron jobs** (Admin → Cron Jobs) — especially reseller billing, disk quotas, and wallet domain queue jobs.
4. **Confirm enforcement settings** (Admin → Settings → Provisioning):
   - Suspend service on overdue invoice
   - Suspend DirectAdmin hosting when disk quota exceeded
   - Reseller suspend / cascade / package limit enforcement
5. **Branding SSL host setup** — see [RESELLER_SSL.md](./RESELLER_SSL.md) if using a custom domain.

## Reseller self-service setup

Resellers should complete:

| Area | Location | Notes |
|------|----------|-------|
| M-Pesa | Settings → M-Pesa | Customer payments use reseller credentials |
| SMTP | Settings → Email | Required for welcome emails and branded mail |
| SMS | Settings → SMS | Optional alerts and transfer notifications |
| Branding | Settings → Branding | Logo, company name, custom domain |
| Catalog | My Catalog | Map shared hosting to DirectAdmin packages |
| Domain pricing | Domain Pricing | Set retail prices per TLD |

## Critical cron jobs

| Command | Purpose |
|---------|---------|
| `cron:generate-reseller-invoices` | Package renewal invoices |
| `cron:suspend-resellers` / `cron:unsuspend-resellers` | Package billing enforcement |
| `cron:enforce-reseller-package-limits` | Service slot limits |
| `cron:enforce-disk-quotas` | DirectAdmin disk overquota suspend |
| `cron:suspend-services` / `cron:suspend-on-due` | Customer invoice enforcement |
| `cron:unsuspend-paid-services` | Restore after payment |
| `cron:process-queued-domain-orders` | Auto-push domains when wallet funded |
| `cron:expire-queued-domain-orders` | Expire stale queue entries |
| `cron:wallet-low-balance-alerts` | Low float notifications |
| `cron:provision-reseller-ssl` | Branding custom domain SSL |

Run `php artisan db:seed --class=CronJobSeeder` on new installs to register all jobs.

## Reseller capabilities

### Customers
- CRUD, impersonation, welcome email (requires reseller SMTP)
- Enforcement alerts on customer profile

### Domains
- Register (wholesale/retail), renew, pricing overrides
- **Domain detail page**: nameservers, DNS records, inter-customer transfer
- Delete removes local record only (not registry cancellation)

### Hosting
- Order shared hosting (DirectAdmin) or containers via catalog
- Suspend / unsuspend / terminate managed services
- Enforcement panel shows suspension reason and disk usage

### Billing
- Customer invoices, payments, PDF
- Wallet for wholesale domain float
- Package subscription (own account)

## Inter-customer domain transfer

1. Reseller opens **Domains → Manage domain → Transfer to another customer**.
2. Recipient receives SMS with approval link (if SMS configured).
3. Recipient logs in and approves at `/my/domains/transfer/approval/{token}`.

## Troubleshooting

| Symptom | Check |
|---------|-------|
| Customer not suspended for non-payment | `suspend_on_overdue` enabled; crons running |
| Disk suspend not working | `suspend_on_disk_overquota`; `cron:enforce-disk-quotas`; DA API access |
| Domain queue stuck | Wallet balance; `cron:process-queued-domain-orders` |
| Welcome email fails | Reseller SMTP enabled in Settings |
| Catalog DA packages empty | Admin DA binding on reseller account |
