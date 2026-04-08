# Security Policy & Guidelines

## Overview

Talksasa Cloud implements enterprise-grade security measures to protect user data, prevent unauthorized access, and ensure system integrity.

---

## 1. Authentication & Authorization

### Password Security
- **Minimum Length:** 8 characters
- **Requirements:** Must contain uppercase, lowercase, numbers, and symbols
- **Policy:** Passwords must be uncompromised (checked against known breach databases)
- **Change:** Users can change passwords from Security settings page
- **Notifications:** Email + SMS sent when password changes

### Session Management
- **Timeout:** 120 minutes of total usage
- **Idle Timeout:** 60 minutes of inactivity
- **Secure Cookies:** HTTPS only, HTTPOnly flag enabled
- **CSRF Protection:** Token-based verification on all forms
- **Session Fixation:** Automatic session regeneration on login

### Rate Limiting
- **Login Attempts:** 5 failed attempts per 15 minutes → lockout
- **Email Verification:** 5 attempts per hour
- **Password Reset:** 3 attempts per hour
- **API Requests:** 100 requests per minute (per user)

### Access Control (RBAC)
- Admin: Full platform access
- Reseller: Managed package and customer access
- Customer: Own services, invoices, and support tickets
- All access validated via policies

---

## 2. Data Protection

### Encryption
- **Transport:** TLS 1.3 minimum (HTTPS only)
- **At Rest:** AES-256-CBC encryption for sensitive fields
- **Database:** Secure connections with encrypted credentials
- **API Keys:** Stored encrypted, never logged

### Sensitive Data Handling
- Phone numbers, emails encrypted in database
- Payment information never stored locally
- Temporary passwords not stored in logs
- All database queries use parameterized statements

### Database Security
- Parameterized queries (SQL injection prevention)
- Row-level access control (users can only see own data)
- Regular backups with encryption
- Database credentials from environment variables only

---

## 3. Input Validation & Output Encoding

### Input Validation
- **All input validated** before processing
- **File uploads:** Type, size, and content scanning
- **Maximum file size:** 50MB
- **Allowed types:** Images, documents, data files only
- **Malicious content:** Scanned for scripts and exploits

### Output Encoding
- **HTML escaping:** All user input displayed with htmlspecialchars()
- **XSS Prevention:** Content Security Policy headers
- **JSON:** Proper encoding for API responses
- **URLs:** Proper URL encoding for redirects

---

## 4. API Security

### Authentication
- **Sanctum Tokens:** Stateless API authentication
- **Frontend Requests:** CORS-protected, state-full cookies
- **API Keys:** Issued per application, with expiration

### Rate Limiting
- **API Endpoints:** 100 requests/minute per user
- **Login:** 5 attempts per 15 minutes
- **Headers:** X-RateLimit-Limit, X-RateLimit-Remaining provided

### Request Validation
- **Content-Type:** Validated (application/json for APIs)
- **Method:** Strict GET/POST/PUT/DELETE enforcement
- **Payload:** Size limits enforced
- **Parameters:** Type and format validation

---

## 5. Security Headers

All responses include the following security headers:

```
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), microphone=(), camera=()
Content-Security-Policy: [configured in config/security.php]
X-Robots-Tag: noai, noimageai
```

---

## 6. Activity Logging & Monitoring

### Logged Activities
- ✅ All authentication attempts (success/failure)
- ✅ Password changes with IP and timestamp
- ✅ Profile updates
- ✅ Account deletion
- ✅ Admin actions (product, customer, invoice management)
- ✅ Service provisioning/termination
- ✅ Payment processing
- ✅ Failed authorization attempts

### Log Retention
- **Retention Period:** 90 days
- **Storage:** Encrypted logs, separate from code
- **Access:** Admin only, audit trail maintained

### Security Alerts
- Suspicious login patterns
- Multiple failed attempts (auto-lockout)
- Unusual IP access
- Rapid API requests
- Data export attempts

---

## 7. File Upload Security

### Validation
- **Size Check:** Maximum 50MB
- **Type Check:** MIME type verification
- **Extension Check:** Whitelist of allowed extensions
- **Content Scan:** Check for embedded scripts

### Safe Handling
- **Storage:** Outside public web root
- **Permissions:** 644 permissions on files
- **Naming:** Random hash to prevent path traversal
- **Access:** Authenticated users only
- **Serving:** Via download controller (not direct access)

---

## 8. Configuration Security

### Environment Variables
```bash
APP_KEY=              # Must be set (php artisan key:generate)
APP_DEBUG=false       # NEVER true in production
APP_ENV=production    # Set appropriately

# Database
DB_CONNECTION=sqlite  # Use secure connection driver
DB_ENCRYPTION=true    # Enable connection encryption

# Session
SESSION_SECURE=true   # HTTPS only
SESSION_HTTP_ONLY=true

# CORS
FRONTEND_URL=         # Whitelist specific origins

# API Keys
SANCTUM_STATEFUL_DOMAINS=  # Whitelist frontend domain
```

### .env.example
```bash
APP_NAME=TalksasaCloud
APP_ENV=production
APP_KEY=base64:xxx
APP_DEBUG=false
APP_URL=https://yourdomain.com

# Database
DB_CONNECTION=sqlite
DB_ENCRYPTION=true
DB_TRUSTED_CERTIFICATE=/path/to/ca.pem

# Mail
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls

# Security
SESSION_SECURE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

# CORS
FRONTEND_URL=https://yourdomain.com
SANCTUM_STATEFUL_DOMAINS=yourdomain.com
```

---

## 9. Common Vulnerabilities Prevention

| Vulnerability | Prevention |
|---|---|
| SQL Injection | Parameterized queries, Laravel ORM |
| XSS (Cross-Site Scripting) | Output encoding, CSP headers |
| CSRF (Cross-Site Request Forgery) | CSRF tokens on all forms |
| Clickjacking | X-Frame-Options: DENY |
| MIME Type Sniffing | X-Content-Type-Options: nosniff |
| Insecure Deserialization | No unserialize() of untrusted data |
| Security Misconfiguration | Secure defaults, ENV validation |
| Broken Authentication | Session timeout, rate limiting, secure cookies |
| Sensitive Data Exposure | Encryption, HTTPS, secure headers |
| Using Components with Known Vulnerabilities | Composer audit, dependency updates |

---

## 10. Best Practices Checklist

### For Users
- ✅ Use strong, unique passwords (8+ chars, mixed case, symbols)
- ✅ Don't share credentials or API tokens
- ✅ Enable email notifications for account changes
- ✅ Verify emails promptly
- ✅ Review active sessions periodically
- ✅ Report suspicious activity immediately

### For Developers
- ✅ Use parameterized queries (Laravel ORM)
- ✅ Validate all user input
- ✅ Encode all output
- ✅ Never log sensitive data
- ✅ Use HTTPS for all connections
- ✅ Keep dependencies updated (`composer update`, `composer audit`)
- ✅ Use environment variables for secrets
- ✅ Follow Laravel security practices
- ✅ Test for vulnerabilities regularly
- ✅ Never disable CSRF protection

### For Deployment
- ✅ Set `APP_DEBUG=false` in production
- ✅ Use HTTPS with valid SSL certificate
- ✅ Enable firewall and DDoS protection
- ✅ Regular backups with encryption
- ✅ Monitor security logs
- ✅ Update system packages regularly
- ✅ Use strong database credentials
- ✅ Limit database access by IP
- ✅ Use environment-specific configurations
- ✅ Implement Web Application Firewall (WAF)

---

## 11. Incident Response

### Suspected Breach
1. **Immediately:** Lock affected accounts
2. **Notify:** Inform affected users via email + SMS
3. **Investigate:** Check activity logs for unauthorized access
4. **Reset:** Force password reset for compromised accounts
5. **Monitor:** Watch for suspicious activity for 30 days
6. **Report:** Document incident and review security controls

### Failed Login Lockout
- Automatic after 5 failed attempts
- Lockout duration: 15 minutes
- User notified via email
- Admin can manually unlock account

---

## 12. Compliance & Certifications

- OWASP Top 10 mitigation
- Laravel Security Best Practices
- PCI DSS compliance (payment handling)
- GDPR compliance (data privacy)
- Regular security audits recommended

---

## 13. Reporting Security Issues

**DO NOT** publicly disclose security vulnerabilities.

Contact: security@talksasa.com

Include:
- Detailed description of vulnerability
- Steps to reproduce
- Potential impact
- Your contact information

Response time: 24-48 hours

---

## 14. Updates & Patches

### Dependency Management
```bash
# Check for vulnerabilities
composer audit

# Update dependencies
composer update

# Run tests after updates
php artisan test
```

### Security Updates
- Applied within 24 hours of release
- Critical patches applied immediately
- Change logs reviewed before applying
- Testing done in staging first

---

## Contact & Support

For security questions or issues:
- Email: security@talksasa.com
- Support Portal: https://support.talksasa.com
- Emergency Hotline: +254 XXX XXX XXX

Last Updated: 2026-04-08
