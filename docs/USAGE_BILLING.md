# Usage billing (application hosting)

Platform customers deploy without picking RAM/CPU packages. Flow: tech stack → database → primary domain → checkout.

## Commercial model

- **Checkout hosting**: KES 0 for the first free period (once per account). Domain registration (if any) is still charged.
- **After free period**: renewals bill the monthly floor (`custom_price`) plus measured overage (CPU / RAM / disk / mailboxes).
- **Domain lock**: primary domain is locked while the app is active or suspended; after terminate, a cool-down blocks redeploy on the same FQDN.
- **Anti-abuse**: concurrent app caps, deploys-per-week, provision hard caps (see `abuse.*`).

Reseller catalog customers keep fixed packages (usage billing is platform-only).

## Config

See [`config/usage_billing.php`](../config/usage_billing.php):

| Key | Purpose |
|-----|---------|
| `floor_price_monthly` | Fallback monthly floor when product price is 0 |
| `included.*` | CPU / memory / disk / mailboxes allotment |
| `rates.*` | Overage unit prices (bandwidth off by default) |
| `hard_caps.*` | Soft operational ceilings |
| `grace_percent` | Do not bill until usage exceeds included × (1 + grace%) |
| `auto_include_email` | Attach Mailcow sibling on new usage orders |
| `free_period.*` | First-period free days, once-per-account, zero checkout |
| `abuse.*` | Concurrent apps, deploy rate, domain cool-down, provision caps |

Env overrides: `USAGE_BILLING_*` (see config file).

## Service fields

- `billing_mode`: `package` (legacy fixed) or `usage`
- `included_limits` / `usage_rates`: snapshots at order time
- `custom_price`: monthly floor for usage mode (kept during free period; first paid invoice uses it after `next_due_date`)
- `service_meta.usage_free_period_granted` / `primary_domain`: free-period + lock metadata

Existing package services keep `billing_mode=package` and their `next_due_date`. Overage lines still apply when `product.overage_enabled` (grandfathered exceed-specs). Annual package customers stay on package until the package year ends.

## Domain locks

Table `domain_deployment_locks`: `locked` while the service lives; `cooldown` until `cool_down_until` after terminate. Bind-domain in the customer console respects the same rules.

## Crons

- `cron:collect-container-metrics` — container CPU/RAM/disk
- `cron:collect-mail-usage-snapshots` — mailbox counts for usage invoices
- `cron:generate-invoices` — floor renewal + overage via `ContainerOverageBillingService`

## Customer UI

Confirm screen shows free-period messaging. Container console overview shows period usage vs included, projected overage, and recent overage invoice lines.
