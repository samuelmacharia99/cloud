# Mailcow setup (Talksasa Cloud)

Ordered runbook for a **dedicated** Mailcow VPS. Talksasa provisions domains/mailboxes via the Mailcow REST API. Mailcow is **not** Application Hosting — do not install it on a container app node.

**Rule:** finish each phase and pass its checkpoint before starting the next. Do not sell Email Hosting or migrate MX until Phase E is green.

Replace placeholders:

| Placeholder | Example |
|-------------|---------|
| `MAIL_HOST` | `mail.talksasa.com` |
| `MAIL_IP` | public IPv4 of the VPS |
| `APP_IP` | Talksasa app server outbound IP (used for API allowlist) |

---

## Phase 0 — Decide before you touch the VPS

1. **One job only:** this VPS runs Mailcow. No websites, no Application Hosting, no DirectAdmin.
2. **Sizing (minimum for production):**
   - **8 GB RAM** (16 GB preferred once mailbox count grows)
   - 2–4 vCPU
   - 80+ GB SSD
3. **Clean IP:** prefer a new IP that has not been used for shared hosting / spammy mail. Check [MX Toolbox blacklist](https://mxtoolbox.com/blacklists.aspx) before go-live.
4. **Hostname plan:** pick `MAIL_HOST` now. It must match:
   - server hostname
   - DNS A record
   - PTR / reverse DNS at the VPS provider
5. **Do not** point customer MX here yet. Keep DirectAdmin MX until a test domain works end-to-end.

**Checkpoint:** you know `MAIL_HOST`, `MAIL_IP`, and `APP_IP`.

### Automated helper (Phases A–C)

On the mail VPS (as root), after DNS/PTR are set:

```bash
# Copy scripts/setup-mailcow-node.sh to the VPS, then:
# Defaults to mail.talksasa.com (override with MAIL_HOST=... if needed)
sudo bash setup-mailcow-node.sh check      # must pass
sudo bash setup-mailcow-node.sh all       # bootstrap + install
# Later, from the Talksasa app server:
# export MAILCOW_API_KEY=... ; bash setup-mailcow-node.sh api-test
```

`--force` skips DNS/PTR/RAM hard stops (lab only). Admin password, API key UI, and Talksasa node registration stay manual (Phases D–F).

---

## Phase A — Provider DNS + PTR (before installing Mailcow)

Do this first so Let's Encrypt and SMTP trust work on first boot.

1. At your DNS for the **brand** zone (e.g. `talksasa.com`):
   - `A` → `MAIL_HOST` → `MAIL_IP`
   - Optional `AAAA` only if you will use IPv6 end-to-end
2. At the VPS provider (Hetzner Cloud → IP → Reverse DNS):
   - PTR for `MAIL_IP` → exactly `MAIL_HOST`
3. Wait for DNS (often minutes; PTR can take longer).

Verify from your laptop:

```bash
dig +short A MAIL_HOST
dig +short -x MAIL_IP
# Both should show matching host / IP
```

**Checkpoint:** A and PTR agree. Do not install Mailcow until they do.

---

## Phase B — Harden the empty VPS

SSH in as root (or sudo).

1. Set hostname:

```bash
hostnamectl set-hostname MAIL_HOST
```

2. Install basics (Debian/Ubuntu):

```bash
apt update
apt install -y git openssl curl gawk coreutils grep jq ufw fail2ban
```

3. Firewall — open only what mail needs:

```bash
ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 25/tcp
ufw allow 465/tcp
ufw allow 587/tcp
ufw allow 993/tcp
ufw --force enable
ufw status
```

4. Confirm the provider does **not** block port 25 (common on some clouds). Send a test later from the box after Mailcow is up.

**Checkpoint:** hostname set, UFW active, SSH still works.

---

## Phase C — Install Docker + Mailcow (upstream path)

Follow [mailcow install docs](https://docs.mailcow.email/getstarted/install/). Summary:

1. Docker Engine (≥ 24) + Compose plugin (≥ 2):

```bash
curl -sSL https://get.docker.com/ | CHANNEL=stable sh
systemctl enable --now docker
apt install -y docker-compose-plugin
docker compose version
```

2. Clone and configure:

```bash
umask 0022
cd /opt
git clone https://github.com/mailcow/mailcow-dockerized
cd mailcow-dockerized
./generate_config.sh
```

When prompted, set hostname to **`MAIL_HOST`** (must match Phase A).

3. Review `mailcow.conf` (optional but recommended):

```bash
nano mailcow.conf
```

Leave defaults unless you know you need changes (HTTP redirect, skip branch updates, etc.).

4. Start:

```bash
docker compose pull
docker compose up -d
docker compose ps
```

5. Wait until containers are healthy (2–5 minutes). Then open:

- `https://MAIL_HOST/admin`
- Default login: `admin` / `moohoo` → **change password immediately**

**Checkpoint:** admin UI loads over HTTPS; all critical containers are `Up`.

---

## Phase D — Mailcow admin hygiene (before API)

In the Mailcow UI:

1. Change `admin` password; store it in your password manager.
2. **System** → confirm timezone / updates preference.
3. Optionally create a second admin for ops (do not share the API key as a login password).
4. Under mail settings, note rate limits exist — leave defaults until you understand volume.
5. Do **not** add production customer domains by hand if Talksasa will provision them via API (avoids double-management).

**Checkpoint:** you can log in with a new strong password.

---

## Phase E — API for Talksasa (critical)

1. Mailcow UI → **Configuration** → **Access** → edit administrator → **API**.
2. Enable API; create a **Read-Write** API key.
3. **API allowlist:** add `APP_IP` (Talksasa app server outbound IP).  
   - If the app uses IPv6 egress, allow that too.  
   - Empty allowlist = open to the world (bad). Wrong IP = Talksasa “Test connection” fails.
4. Smoke test from the **app server** (not only from your laptop):

```bash
curl -sS -H "X-API-Key: YOUR_KEY" \
  "https://MAIL_HOST/api/v1/get/status/version"
```

Expect JSON with a version string.

**Checkpoint:** curl from the app server succeeds. If it fails from the app but works from your laptop, fix allowlist / firewall / DNS first.

---

## Phase F — Register the node in Talksasa

Admin → **Nodes** → Add → **Mailcow**:

| Field | Value |
|-------|--------|
| Name | e.g. `Mail-01` |
| IP | `MAIL_IP` |
| Hostname | `MAIL_HOST` |
| API URL | `https://MAIL_HOST` |
| API token | RW key from Phase E |
| Verify SSL | **on** |
| Active | **on** |

Click **Test Mailcow API** on the node page. Status should go **online**.

**Checkpoint:** node online in Talksasa. Do not skip this.

---

## Phase G — Product + one dry-run domain

1. Confirm Email Hosting product(s) use `provisioning_driver_key=mailcow` and sensible `resource_limits`, e.g.:

```json
{
  "mailboxes": 10,
  "aliases": 20,
  "quota_mb": 51200,
  "mailbox_quota_mb": 5120
}
```

2. Provision a **test** domain you control (not a live customer):
   - Order Email Hosting or use admin provision path
   - Confirm domain + mailbox appear in Mailcow UI
3. DNS for that test domain (Talksasa Cloudflare helpers do this when DNS is managed; otherwise set manually):

| Type | Name | Value |
|------|------|--------|
| MX | `@` | `MAIL_HOST` (priority 10) |
| TXT | `@` | `v=spf1 mx a:MAIL_HOST -all` |
| TXT | `dkim._domainkey` | from Mailcow (DKIM for the domain) |
| TXT | `_dmarc` | `v=DMARC1; p=none; rua=mailto:dmarc@YOUR_BRAND` |

4. Send/receive a test message (webmail `https://MAIL_HOST/SOGo/` or IMAP).
5. Check headers / [mail-tester.com](https://www.mail-tester.com/) once — fix SPF/DKIM/PTR before scaling.

**Checkpoint:** test mailbox works. Only then consider customer traffic.

---

## Phase H — Deliverability (first weeks)

- Warm the IP: low volume, real traffic, no bulk blasts.
- Watch blacklists and Mailcow queue (stuck mail = misconfig or reputation).
- Keep DirectAdmin MX for migrated customers until IMAP sync is verified (admin DA→Mailcow migrate wizard).
- Do **not** cut MX for everyone on day one.

---

## What not to do (avoids a mess)

| Don't | Why |
|-------|-----|
| Install Mailcow on an Application Hosting node | Resource and port conflicts; different product |
| Skip PTR / mismatch hostname | Instant spam folder / reject |
| Open API with empty IP allowlist | Anyone can create mailboxes |
| Point live customer MX before dry-run | Outages and lost mail |
| Hand-edit domains that Talksasa owns | Double state, failed syncs |
| Reuse a burned shared-hosting IP | Reputation hell |
| Run with 2 GB RAM | OOM / flaky Docker stack |

---

## Quick recovery commands (on the mail VPS)

```bash
cd /opt/mailcow-dockerized
docker compose ps
docker compose logs --tail=100 nginx-mailcow
docker compose restart
```

Update Mailcow only via upstream docs (`update.sh`) during a maintenance window — never mid-migration.

---

## Go-live checklist (print this)

- [ ] Phase A: A + PTR match `MAIL_HOST`
- [ ] Phase B: UFW + hostname
- [ ] Phase C: `docker compose ps` healthy; HTTPS admin works
- [ ] Phase D: admin password changed
- [ ] Phase E: API RW key + `APP_IP` allowlist; curl from **app server** OK
- [ ] Phase F: Talksasa Mailcow node **online**
- [ ] Phase G: test domain send/receive OK
- [ ] Phase H: warm-up plan agreed; DA MX cutover only per customer after sync
