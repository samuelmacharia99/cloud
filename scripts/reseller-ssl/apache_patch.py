#!/usr/bin/env python3
"""
Patch an Apache config so HTTP-01 ACME challenges are not redirected to HTTPS.
Used by scripts/reseller-ssl/provision.sh (run as root).
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ACME_BEGIN = "# BEGIN TALKSASA_ACME"
ACME_END = "# END TALKSASA_ACME"
ACME_BLOCK = f"""{ACME_BEGIN}
    RewriteEngine On
    RewriteRule ^/\\.well-known/acme-challenge/ - [L]
{ACME_END}"""


def split_virtualhosts(content: str) -> list[tuple[str, str]]:
    parts = re.split(r"(<VirtualHost\b[^>]*>)", content, flags=re.IGNORECASE)
    if len(parts) < 2:
        return [("", content)]

    chunks: list[tuple[str, str]] = []
    prefix = parts[0]
    if prefix.strip():
        chunks.append(("", prefix))

    i = 1
    while i < len(parts):
        opener = parts[i]
        body = parts[i + 1] if i + 1 < len(parts) else ""
        chunks.append((opener, body))
        i += 2

    return chunks


def vhost_matches_domain(opener: str, body: str, domain: str) -> bool:
    block = opener + body
    if not re.search(r"<VirtualHost\s+[^>]*:\s*80\b", opener, re.IGNORECASE):
        return False
    return bool(
        re.search(
            rf"^\s*ServerName\s+{re.escape(domain)}\s*$",
            block,
            re.IGNORECASE | re.MULTILINE,
        )
        or re.search(
            rf"^\s*ServerAlias\s+.*\b{re.escape(domain)}\b",
            block,
            re.IGNORECASE | re.MULTILINE,
        )
    )


def has_acme_block(body: str) -> bool:
    return ACME_BEGIN in body


def strip_global_https_redirect(body: str) -> str:
    """Remove blanket HTTP→HTTPS redirects that break ACME."""
    lines = body.splitlines(keepends=True)
    out: list[str] = []
    for line in lines:
        if re.search(r"^\s*Redirect\s+(permanent|temp)\s+/\s+https?://", line, re.IGNORECASE):
            continue
        if re.search(
            r"^\s*RewriteRule\s+\^.*https://%{HTTP_HOST}",
            line,
            re.IGNORECASE,
        ) and ".well-known" not in line:
            if not any(ACME_BEGIN in l for l in out[-5:]):
                continue
        out.append(line)
    return "".join(out)


def inject_acme(body: str) -> str:
    if has_acme_block(body):
        return body

    body = strip_global_https_redirect(body)

    # Insert before closing VirtualHost or before other RewriteEngine
    insert_at = body.rfind("</VirtualHost>")
    if insert_at == -1:
        return body + "\n" + ACME_BLOCK + "\n"

    before = body[:insert_at]
    after = body[insert_at:]

    if "RewriteEngine On" in before and ACME_BEGIN not in before:
        before = before.replace(
            "RewriteEngine On",
            ACME_BLOCK + "\n    RewriteEngine On",
            1,
        )
        return before + after

    return before + "\n" + ACME_BLOCK + "\n\n    RewriteEngine On\n    RewriteCond %{REQUEST_URI} !^/\\.well-known/acme-challenge/\n    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]\n" + after


def patch_file(path: Path, domain: str) -> bool:
    content = path.read_text(encoding="utf-8", errors="replace")
    chunks = split_virtualhosts(content)
    changed = False
    rebuilt: list[str] = []

    for opener, body in chunks:
        if opener and vhost_matches_domain(opener, body, domain):
            new_body = inject_acme(body)
            if new_body != body:
                changed = True
                body = new_body
        rebuilt.append(opener + body)

    if not changed:
        return False

    path.write_text("".join(rebuilt), encoding="utf-8")
    return True


def main() -> int:
    if len(sys.argv) != 3:
        print("Usage: apache_patch.py <domain> <apache-config-file>", file=sys.stderr)
        return 2

    domain = sys.argv[1].strip().lower()
    config_file = Path(sys.argv[2])

    if not domain or ".." in domain or "/" in domain:
        print("Invalid domain", file=sys.stderr)
        return 2

    if not config_file.is_file():
        print(f"Config not found: {config_file}", file=sys.stderr)
        return 1

    if patch_file(config_file, domain):
        print(f"Patched ACME rules in {config_file}")
        return 0

    print(f"No changes needed in {config_file}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
