# Reseller custom domain SSL (Provision SSL button)

Resellers can issue Let's Encrypt certificates for branding custom domains from **Settings → Branding → Provision SSL**.

The app runs `scripts/reseller-ssl/provision.sh`, which:

1. Ensures `public/.well-known/acme-challenge/` exists
2. Patches Apache port 80 so ACME paths are **not** redirected to HTTPS
3. Verifies HTTP 200 on the challenge URL
4. Runs `certbot certonly --webroot`
5. Writes/enables an Apache `*:443` vhost with the new certificate
6. Reloads Apache

## One-time server setup

On the production host (as **root**), from the app directory:

```bash
cd /var/www/talksasa-cloud   # or your deploy path
sudo bash scripts/reseller-ssl/install-host.sh
```

This script:

- Installs `certbot`, `apache2`, `python3`
- Enables Apache `rewrite` and `ssl`
- Creates `/etc/sudoers.d/talksasa-reseller-ssl` (mode `0440`) so `www-data` can run the provision script without a password
- Sets `RESELLER_SSL_CERTBOT_SUDO=true`, `RESELLER_SSL_USE_PROVISION_SCRIPT=true` in `.env`
- Runs `php artisan config:clear`

## Manual test (same as the button)

```bash
sudo /var/www/talksasa-cloud/scripts/reseller-ssl/provision.sh \
  --domain server.enthelotcloud.com \
  --webroot /var/www/talksasa-cloud/public \
  --email info@talksasa.com \
  --logs-dir /var/www/talksasa-cloud/storage/app/ssl-provisioning/test/logs
```

Expect:

```bash
curl -I http://server.enthelotcloud.com/.well-known/acme-challenge/test.txt
# HTTP/1.1 200 OK
```

## Environment variables

| Variable | Default | Purpose |
|----------|---------|---------|
| `RESELLER_SSL_USE_PROVISION_SCRIPT` | `true` | Use `provision.sh` instead of raw certbot |
| `RESELLER_SSL_PROVISION_SCRIPT` | *(auto)* | Full path to `provision.sh` |
| `RESELLER_SSL_CERTBOT_SUDO` | `false` | Prefix commands with `sudo -n` (required in production) |
| `RESELLER_SSL_CERTBOT_PATH` | `certbot` | Certbot binary when script is disabled |

## DNS / CAA errors

If Let's Encrypt reports **CAA SERVFAIL** for the parent zone, fix DNS at the domain’s nameservers (`dig CAA enthelotcloud.com @ns1.example.com`). The provision script cannot fix broken authoritative DNS.

## Disable the script (certbot only)

Set in `.env`:

```env
RESELLER_SSL_USE_PROVISION_SCRIPT=false
```

You must still configure Apache and sudo for certbot yourself.
