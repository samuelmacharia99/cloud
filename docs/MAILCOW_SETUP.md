# Mailcow setup (Talksasa Cloud)

Ops checklist before enabling Email Hosting in production. Talksasa provisions domains/mailboxes via the Mailcow REST API; Mailcow itself is a dedicated appliance (not an Application Hosting container).

## 1. Server

- Fresh VPS (Hetzner or similar) with a **clean IP** (avoid reusing spammy shared-host IPs).
- Hostname: `mail.<your-brand>` (example: `mail.talksasa.com`).
- Matching **PTR / reverse DNS** for that IP.
- Install [mailcow-dockerized](https://github.com/mailcow/mailcow-dockerized) per upstream docs.
- Open ports: `25`, `465`, `587`, `993`, `80`, `443` (and SSH).

## 2. API access

1. Mailcow UI → Configuration → Access → Edit administrator → **API**.
2. Create a **Read-Write** API key.
3. Whitelist the Talksasa **app server** outbound IP(s). Update the allowlist if the app IP changes.

Smoke test:

```bash
curl -sS -H "X-API-Key: YOUR_KEY" \
  "https://mail.example.com/api/v1/get/status/version"
```

## 3. Register the node in Talksasa

Admin → Nodes → Add Node → **Mailcow**:

| Field | Value |
|-------|--------|
| Hostname | `mail.example.com` |
| IP | public IP |
| API URL | `https://mail.example.com` |
| API token | RW API key |
| Verify SSL | on (production) |

Use **Test connection** — node should go **online**.

## 4. DNS templates (customer domains)

For each customer mail domain (auto-applied when DNS is on Talksasa Cloudflare):

| Type | Name | Value |
|------|------|--------|
| MX | `@` | `mail.example.com` (priority 10) |
| TXT | `@` | `v=spf1 mx a:mail.example.com -all` (adjust as needed) |
| TXT | `dkim._domainkey` | from Mailcow DKIM API |
| TXT | `_dmarc` | `v=DMARC1; p=none; rua=mailto:dmarc@your-brand` |

## 5. Deliverability

- Warm the IP slowly; monitor blacklists.
- Keep volume low for the first weeks.
- Do not cut MX until IMAP sync from DirectAdmin is verified (migration wizard).

## 6. Product config

Email products use `provisioning_driver_key=mailcow` and `resource_limits`:

```json
{
  "mailboxes": 10,
  "aliases": 20,
  "quota_mb": 51200,
  "mailbox_quota_mb": 5120
}
```
