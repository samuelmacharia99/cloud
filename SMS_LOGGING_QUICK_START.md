# SMS Logging & Debugging - Quick Start Guide

## Overview

The SMS system now includes comprehensive logging at every step. When an SMS fails to deliver, you'll have detailed information to diagnose the issue.

---

## Quick Commands

### View Recent SMS Activity
```bash
php artisan sms:logs
```
Shows last 20 SMS records with status (✓ sent or ✗ failed)

### View Only Failed SMS
```bash
php artisan sms:logs --failed
```
Shows all failed SMS with full error details

### View Specific Reseller's SMS
```bash
php artisan sms:logs --reseller=james.otieno@techsolutions.co.ke
```
Shows logs for specific reseller + configuration check

### View More Records
```bash
php artisan sms:logs --limit=100
```
Show up to 100 records instead of default 20

---

## Understanding Log Entries

When SMS is sent, the system logs:

1. **Building Payload** - Validation of settings, recipient, message
2. **API Request** - Endpoint call with retry attempts
3. **API Response** - Status code and response body
4. **Database Log** - Record saved to `sms_logs` table

Each log includes:
- Reseller ID and email
- Recipient phone number
- Timestamp
- Success/failure status
- Full API response

---

## Testing SMS

### Via Web UI
1. Navigate to `/reseller/settings`
2. Configure SMS (API key, sender ID)
3. Click "Send Test SMS"
4. Enter your phone number
5. Submit and wait for success/error message

### Check Logs After Test
```bash
php artisan sms:logs --failed
```

---

## Common Log Patterns

### Successful SMS ✓
```
Status: SENT
Response: sms_abc123... (SMS ID)
```
✓ Message delivered to recipient

### Missing Configuration ✗
```
Status: FAILED
Error: SMS settings not configured
Details: API key or sender ID missing
```
→ Go to `/reseller/settings` and configure

### Invalid API Key ✗
```
Status: FAILED
Response: {"error": "Invalid API key", "error_code": "AUTH_001"}
```
→ Verify API key from Talksasa dashboard

### Network Error ✗
```
Status: FAILED
Error: API request failed after 3 attempts
```
→ Check internet connection and firewall

### Invalid Phone Number ✗
```
Status: FAILED
Response: {"error": "Invalid phone number format"}
```
→ Use format: +254712345678 or 0712345678

---

## Log File Locations

### Real-Time Application Log
```
storage/logs/laravel-2026-05-03.log
```

View live:
```bash
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log | grep "SMS"
```

### Database Logs
```sql
SELECT * FROM sms_logs ORDER BY created_at DESC LIMIT 20;
```

View failed only:
```sql
SELECT * FROM sms_logs WHERE status = 'failed' ORDER BY created_at DESC;
```

---

## Debugging Workflow

1. **Test SMS via Settings Page**
   ```
   /reseller/settings → Test SMS
   ```

2. **Check Results**
   ```bash
   php artisan sms:logs --failed
   ```

3. **View Full Response**
   ```bash
   php artisan sms:logs --reseller=your-email@example.com
   ```

4. **Check Configuration**
   ```
   Configuration Check section in command output
   ✓ SMS Enabled
   ✓ API Key Set
   ✓ Sender ID Set
   ```

5. **Review Log File**
   ```bash
   tail -f storage/logs/laravel-$(date +%Y-%m-%d).log | grep "SMS Send"
   ```

---

## SMS Payload Being Sent

When reseller sends SMS, the exact payload is:

```json
{
  "api_key": "your_configured_api_key",
  "sender_id": "TALKSASA",
  "phone": "+254712345678",
  "message": "Your message text here",
  "timestamp": "2026-05-03T10:30:00Z"
}
```

All details are logged so you can verify:
- ✓ API key is not empty
- ✓ Sender ID is not empty (max 11 chars)
- ✓ Phone number is properly formatted
- ✓ Message is not empty

---

## Response Codes

When Talksasa API responds:

| Code | Meaning | Action |
|------|---------|--------|
| 200 | Success | SMS queued for delivery ✓ |
| 400 | Bad Request | Check message/phone format |
| 401 | Unauthorized | Check API key |
| 429 | Rate Limited | Wait and retry |
| 500 | Server Error | Talksasa API issue |

---

## Real-World Examples

### Example 1: SMS Sent Successfully
```bash
$ php artisan sms:logs

📱 SMS Delivery Logs Summary
================================================
Total Records: 3 | Sent: 2 | Failed: 1

ID │ Recipient  │ Status  │ Reseller          │ Sender ID │ Sent At
───┼────────────┼─────────┼───────────────────┼───────────┼──────────────
3  │ ...345678  │ SENT    │ james.otieno@...  │ TALKSASA  │ 10:35:00
2  │ ...456789  │ SENT    │ james.otieno@...  │ TALKSASA  │ 10:30:00
1  │ ...567890  │ FAILED  │ james.otieno@...  │ TALKSASA  │ 10:25:00

Failed SMS Details:
✗ ID 1: +254712567890
  Status: failed
  Response: {"error": "Invalid API key"}
```

### Example 2: Configuration Issue
```bash
$ php artisan sms:logs --reseller=james.otieno@techsolutions.co.ke

🔧 Configuration Check for: james.otieno@techsolutions.co.ke

✗ SMS Enabled          ← NOT ENABLED
✓ API Key Set
✓ Sender ID Set

→ Enable SMS in settings at /reseller/settings
```

### Example 3: Debugging Failed SMS
```bash
$ tail -f storage/logs/laravel-2026-05-03.log | grep "SMS Send"

[2026-05-03 10:35:00] production.INFO: SMS Send: Building payload {
  "reseller_id": 3,
  "recipient": "+254712345678",
  "message_length": 45
}

[2026-05-03 10:35:00] production.INFO: SMS Send: Payload built {
  "api_key_length": 32,
  "sender_id": "TALKSASA"
}

[2026-05-03 10:35:00] production.INFO: SMS Send: API request (attempt 1/3) {
  "endpoint": "https://api.talksasa.com/sms/send"
}

[2026-05-03 10:35:01] production.ERROR: SMS Send: Failed {
  "status_code": 401,
  "response": {"error": "Invalid API key"}
}
```

---

## Troubleshooting Checklist

When SMS fails:

- [ ] Check configuration: `php artisan sms:logs --reseller=email`
- [ ] Verify API key is correct (check Talksasa dashboard)
- [ ] Verify sender ID is max 11 characters
- [ ] Check phone number format (+254... or 0...)
- [ ] Verify SMS is enabled at `/reseller/settings`
- [ ] Check internet connection
- [ ] Review full logs: `tail -f storage/logs/laravel-DATE.log | grep SMS`
- [ ] Check Talksasa API status
- [ ] Verify message length (max 160 chars)

---

## What's Logged

Every SMS attempt logs:

✓ **Request Side:**
- Reseller ID and email
- Recipient phone number
- Message length
- API key length (not the key itself for security)
- Sender ID
- Timestamp

✓ **Response Side:**
- HTTP status code
- Full API response JSON
- Retry attempts (if failed)
- Error messages

✓ **Database:**
- All above information
- Stored in `sms_logs` table
- Indexed for fast querying

---

## Performance Impact

- **No delays** - Logging is asynchronous
- **No blocking** - SMS sending not affected by logging
- **Fast queries** - Indexed columns for quick lookup
- **Retry logic** - Automatic 3 retries on network failure

---

## Getting Help

If SMS still not working:

1. Run: `php artisan sms:logs --failed`
2. Check error message
3. Review: `/docs/SMS_DEBUGGING_GUIDE.md`
4. Check Talksasa API status
5. Verify API credentials from Talksasa dashboard

---

## Related Commands

```bash
# View logs
php artisan sms:logs

# View logs with all options
php artisan sms:logs --help

# View application log live
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log | grep SMS

# Check database directly
php artisan tinker
> \App\Models\SmsLog::where('status', 'failed')->latest()->limit(5)->get()
```

---

## Next Steps

1. **Configure SMS**: Go to `/reseller/settings`
2. **Test**: Click "Send Test SMS"
3. **Monitor**: `php artisan sms:logs`
4. **Debug if needed**: Check error details in output

✓ System is now production-ready with full logging!
