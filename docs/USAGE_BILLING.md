# Usage billing (application hosting)

Platform customers deploy without picking RAM/CPU packages. Flow: tech stack → database → primary domain → checkout (monthly starter floor). Email for that domain is included by default.

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

Env overrides: `USAGE_BILLING_*` (see config file).

## Service fields

- `billing_mode`: `package` (legacy fixed) or `usage`
- `included_limits` / `usage_rates`: snapshots at order time
- `custom_price`: monthly floor for usage mode

Existing package services keep `billing_mode=package` and their `next_due_date`. Overage lines still apply when `product.overage_enabled` (grandfathered exceed-specs).

## Crons

- `cron:collect-container-metrics` — container CPU/RAM/disk
- `cron:collect-mail-usage-snapshots` — mailbox counts for usage invoices
- `cron:generate-invoices` — floor renewal + overage via `ContainerOverageBillingService`

## Customer UI

Container console overview shows period usage vs included, projected overage, and recent overage invoice lines.
