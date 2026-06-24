# Reseller public website API

Resellers with a **custom branding domain** can opt in to a JSON API on that domain so their own marketing site can:

- Search domain availability (bare name or FQDN) with **retail** prices for TLDs they have enabled
- List **active catalog services** and prices
- Prepare a cart and send visitors to **guest checkout** (account creation before payment)

The API is only served on the reseller's configured custom domain — not on the main platform host.

---

## Platform (main website)

Super admins can enable the same API on the **main platform domain** (e.g. `servers.talksasa.com`) from **Admin → Developers**:

- Enable public website API and optional CORS origins
- Generate a platform API token (admin bearer auth)
- List **retail domain pricing** for enabled TLDs with admin retail tiers
- List **active platform products** from Admin → Products
- List **reseller hosting plans** (monthly/annual tiers for becoming a reseller)
- Cart → `domain-checkout` for guest account creation

Base URL: `https://{platform-domain}/api/v1/public`

Reseller branding domains continue to use the same paths with reseller retail pricing and `/checkout` on their custom domain.

---

1. **Settings → Branding** — set and save your **custom domain** (e.g. `billing.acmehosting.com`).
2. Point DNS to this server and provision SSL (see `docs/RESELLER_SSL.md`).
3. Enable **Public website API** on the same branding form.
4. Optionally add **allowed website origins** if your storefront runs on a different domain than your portal (for browser CORS).

**Base URL** (after DNS is live):

```text
https://{your-custom-domain}/api/v1/public
```

---

## Authentication

Use either:

1. **Same custom domain** — host your frontend on the same domain as the API. No token required; the server identifies your account from the hostname.

2. **Bearer token** — for server-side integrations or when the hostname cannot identify your account. Generate a token from **Developers** in your reseller portal.

```http
Authorization: Bearer YOUR_API_TOKEN
Accept: application/json
```

Regenerating a token invalidates the previous one immediately.

Cross-origin browser calls (different marketing domain) require the request `Origin` header to match an entry in **allowed website origins** (Settings → Branding).

Rate limit: **30 requests/minute** per IP per reseller domain.

---

## Endpoints

### `GET /domains/search`

Search availability and retail pricing.

| Parameter | Required | Description |
|-----------|----------|-------------|
| `q` | Yes | Domain label (`example`) or FQDN (`example.com`) |
| `period` | No | Registration years (default `1`) |

Bare-name searches check **every TLD you have enabled** in Domain Pricing for that period.

**Example**

```http
GET https://billing.acmehosting.com/api/v1/public/domains/search?q=acme&period=1
```

**Response**

```json
{
  "success": true,
  "query": "acme",
  "period_years": 1,
  "currency": "KES",
  "checkout_url": "https://billing.acmehosting.com/checkout",
  "results": [
    {
      "domain": "acme",
      "extension": ".com",
      "full_domain": "acme.com",
      "available": true,
      "period_years": 1,
      "price": 2299,
      "currency": "KES",
      "checkout_url": "https://billing.acmehosting.com/checkout"
    }
  ]
}
```

Only TLDs with **enabled reseller retail pricing** for the requested period are included. Wholesale costs and registrar details are never returned.

---

### `GET /domains/extensions`

List sellable TLDs and retail prices (no availability check).

| Parameter | Required | Description |
|-----------|----------|-------------|
| `period` | No | Years (default `1`) |

---

### `GET /services`

List active, orderable catalog items.

For **VPS** and **dedicated server** products, each service includes a `configuration` object:

- `specs` / `spec_lines` — hardware summary
- `locations[]` — datacenter options with per-location `prices` (monthly, quarterly, semi-annual, annual, setup_fee)
- `ip_options[]` — additional IP pricing
- `operating_systems[]` — allowed OS keys for cart/checkout

```json
{
  "success": true,
  "currency": "KES",
  "checkout_url": "https://billing.acmehosting.com/checkout",
  "services": [
    {
      "id": 12,
      "name": "Starter Hosting",
      "description": "5GB SSD, 1 site",
      "type": "shared_hosting",
      "monthly_price": 499,
      "yearly_price": 4990,
      "setup_fee": 0,
      "currency": "KES",
      "billing_cycles": ["monthly", "quarterly", "semi-annual", "annual"],
      "features": ["5GB SSD", "Free SSL"]
    },
    {
      "id": 18,
      "name": "Cloud VPS 4GB",
      "type": "vps",
      "monthly_price": 2500,
      "yearly_price": 25000,
      "setup_fee": 0,
      "currency": "KES",
      "billing_cycles": ["monthly", "quarterly", "semi-annual", "annual"],
      "configuration": {
        "specs": { "cpu_cores": 2, "ram_gb": 4, "storage_gb": 80 },
        "spec_lines": ["2 CPU Cores", "4 GB RAM", "80 GB NVMe Storage"],
        "locations": [
          {
            "key": "usa",
            "name": "United States",
            "city": "New York",
            "prices": {
              "monthly": 2800,
              "quarterly": 8400,
              "semi-annual": 16800,
              "annual": 28000,
              "setup_fee": 0,
              "currency": "KES"
            }
          }
        ],
        "ip_options": [
          { "ip_count": 1, "monthly_addon": 0, "setup_addon": 0, "label": "1 IP" },
          { "ip_count": 2, "monthly_addon": 200, "setup_addon": 50, "label": "2 IPs (+200/mo)" }
        ],
        "operating_systems": [
          { "key": "ubuntu-24.04", "label": "Ubuntu 24.04 LTS (Noble Numbat)" }
        ],
        "max_ip_count": 8
      }
    }
  ]
}
```

---

### `GET /reseller-packages` (platform host only)

List active reseller subscription plans configured under **Admin → Reseller Packages**.

| Parameter | Required | Description |
|-----------|----------|-------------|
| `cycle` | No | `monthly` or `annually` |

```json
{
  "success": true,
  "currency": "KES",
  "checkout_url": "https://servers.talksasa.com/domain-checkout",
  "packages": [
    {
      "id": 2,
      "name": "Starter Reseller",
      "description": "Launch your hosting brand",
      "billing_cycle": "monthly",
      "price": 4999,
      "subtotal": 4999,
      "tax": 0,
      "total": 4999,
      "currency": "KES",
      "max_services": 50,
      "max_users": 100,
      "disk_pool_gb": 500,
      "disk_overage_rate": 0,
      "features": [
        "Up to 100 customers",
        "Up to 50 active services",
        "500 GB disk pool"
      ]
    }
  ]
}
```

Not available on reseller branding domains (404).

---

### `POST /cart`

Validate items, store them in a server session, and return a **checkout deep link**.

**Request body**

```json
{
  "items": [
    {
      "type": "domain",
      "full_domain": "acme.com",
      "years": 1
    },
    {
      "type": "service",
      "reseller_product_id": 12,
      "billing_cycle": "annual"
    },
    {
      "type": "service",
      "reseller_product_id": 18,
      "billing_cycle": "monthly",
      "location_key": "usa",
      "ip_count": 2,
      "operating_system": "ubuntu-24.04"
    }
  ]
}
```

| Item type | Fields |
|-----------|--------|
| `domain` | `full_domain`, optional `years` |
| `service` | `reseller_product_id` (or `id`), `billing_cycle` |
| `service` (VPS / dedicated) | above plus `location_key`, `ip_count`, `operating_system` (from `GET /services` → `configuration`) |
| `reseller_package` | `reseller_package_id` (platform host only; must be the only cart item) |

Domains must be **available** and priced. Services must be active in your catalog.

**Response**

```json
{
  "success": true,
  "item_count": 2,
  "checkout_url": "https://billing.acmehosting.com/checkout"
}
```

Redirect the visitor's browser to `checkout_url` (same browser session). Checkout supports **guest account creation** before payment; new customers are linked to your reseller account automatically.

---

## Embed example (same domain)

```html
<input id="domain" placeholder="Find a domain">
<button id="search">Search</button>
<ul id="results"></ul>

<script>
const API = '/api/v1/public';

document.getElementById('search').onclick = async () => {
  const q = document.getElementById('domain').value.trim();
  const res = await fetch(`${API}/domains/search?q=${encodeURIComponent(q)}`);
  const data = await res.json();
  const list = document.getElementById('results');
  list.innerHTML = '';
  for (const row of data.results || []) {
    const li = document.createElement('li');
    li.textContent = `${row.full_domain} — ${row.available ? 'Available' : 'Taken'} — KES ${row.price}`;
    if (row.available) {
      const btn = document.createElement('button');
      btn.textContent = 'Buy';
      btn.onclick = async () => {
        const cart = await fetch(`${API}/cart`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ items: [{ type: 'domain', full_domain: row.full_domain, years: row.period_years }] }),
        });
        const payload = await cart.json();
        if (payload.checkout_url) window.location.href = payload.checkout_url;
      };
      li.appendChild(btn);
    }
    list.appendChild(li);
  }
};
</script>
```

---

## Cross-origin embed

If your storefront is `https://www.acme.com` and the API is `https://billing.acme.com`:

1. Add `https://www.acme.com` to **allowed website origins** in branding settings.
2. Use `fetch(..., { credentials: 'include' })` so the session cookie is sent when posting to `/cart` and opening checkout.

---

## Errors

| HTTP | Meaning |
|------|---------|
| `404` | Request not on a reseller custom domain |
| `403` | Public API not enabled for this reseller |
| `422` | Validation failed or cart items invalid |
| `429` | Rate limit exceeded |

---

## Security notes

- Opt-in only; disabled by default.
- Retail prices only — no wholesale or upstream registrar data.
- Cart preparation re-validates availability and pricing server-side.
- Checkout re-prices items; client-supplied amounts are not trusted.
