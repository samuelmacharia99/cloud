# SMS Delivery Debugging Guide

## Overview

The SMS sending system includes comprehensive logging at every step to help diagnose delivery issues. All SMS attempts are logged in the `sms_logs` table and detailed information is written to the application logs.

---

## Log Locations

### Application Logs
```
storage/logs/laravel-YYYY-MM-DD.log
```

All SMS operations log detailed information here with context:
- Request payload (API key length, sender ID)
- API responses (status code, response body)
- Network/timeout errors
- Database logging errors

### Database Logs
```
SMS Logs Table: sms_logs
```

Every SMS attempt is recorded with:
- Recipient phone number
- Message content
- Sender ID used
- Status (sent/failed)
- Full API response
- Timestamp
- Reseller who sent it

---

## Viewing SMS Logs

### Command Line Tool

View recent SMS logs:
```bash
php artisan sms:logs
```

View failed SMS only:
```bash
php artisan sms:logs --failed
```

View specific reseller's logs:
```bash
php artisan sms:logs --reseller=james.otieno@techsolutions.co.ke
```

View logs from last 24 hours (combine filters):
```bash
php artisan sms:logs --failed --limit=50
```

View logs by status:
```bash
php artisan sms:logs --status=sent --status=failed
```

### Database Query

```sql
-- View all failed SMS
SELECT * FROM sms_logs WHERE status = 'failed' ORDER BY created_at DESC LIMIT 20;

-- View SMS by reseller
SELECT sms_logs.* FROM sms_logs 
JOIN users ON sms_logs.sent_by = users.id
WHERE users.email = 'reseller@example.com'
ORDER BY sms_logs.created_at DESC LIMIT 20;

-- View SMS from last hour
SELECT * FROM sms_logs 
WHERE created_at >= NOW() - INTERVAL 1 HOUR
ORDER BY created_at DESC;

-- Count SMS stats
SELECT status, COUNT(*) as count FROM sms_logs 
GROUP BY status;
```

---

## Log Entry Examples

### Successful SMS Send

```
[2026-05-03 10:30:00] production.INFO: SMS Send: Building payload {
  "reseller_id": 3,
  "reseller_email": "james.otieno@techsolutions.co.ke",
  "recipient": "+254712345678",
  "message_length": 45
}

[2026-05-03 10:30:00] production.INFO: SMS Send: Payload built {
  "reseller_id": 3,
  "reseller_email": "james.otieno@techsolutions.co.ke",
  "recipient": "+254712345678",
  "message_length": 45,
  "api_key_length": 32,
  "sender_id": "TALKSASA",
  "timestamp": "2026-05-03T10:30:00+00:00"
}

[2026-05-03 10:30:00] production.INFO: SMS Send: API request (attempt 1/3) {
  "reseller_id": 3,
  "reseller_email": "james.otieno@techsolutions.co.ke",
  "recipient": "+254712345678",
  "message_length": 45,
  "endpoint": "https://api.talksasa.com/sms/send",
  "timeout": 30
}

[2026-05-03 10:30:01] production.INFO: SMS Send: API response received {
  "reseller_id": 3,
  "reseller_email": "james.otieno@techsolutions.co.ke",
  "recipient": "+254712345678",
  "message_length": 45,
  "status_code": 200,
  "response_size": 156,
  "attempt": 1
}

[2026-05-03 10:30:01] production.INFO: SMS Send: Success {
  "reseller_id": 3,
  "reseller_email": "james.otieno@techsolutions.co.ke",
  "recipient": "+254712345678",
  "message_length": 45,
  "status": "sent",
  "sms_id": "sms_abc123xyz789"
}
```

### Failed SMS Send - Invalid API Key

```
[2026-05-03 10:35:00] production.WARNING: SMS Send: Missing API key {
  "reseller_id": 3,
  "reseller_email": "james.otieno@techsolutions.co.ke",
  "recipient": "+254712345678",
  "message_length": 45,
  "sms_enabled": false,
  "has_api_key": false,
  "has_sender_id": true
}

[2026-05-03 10:35:00] production.ERROR: SMS Send: Returning failure response {
  "reseller_id": 3,
  "reseller_email": "james.otieno@techsolutions.co.ke",
  "recipient": "+254712345678",
  "message_length": 45,
  "error": "SMS settings not configured"
}
```

### Failed SMS Send - Network Error

```
[2026-05-03 10:40:00] production.WARNING: SMS Send: API request failed (attempt 1/3) {
  "reseller_id": 3,
  "reseller_email": "james.otieno@techsolutions.co.ke",
  "recipient": "+254712345678",
  "message_length": 45,
  "error": "Connection timeout after 30 seconds",
  "retry_delay_ms": 1000
}

[2026-05-03 10:40:01] production.WARNING: SMS Send: API request failed (attempt 2/3) {
  "reseller_id": 3,
  "error": "cURL error 28: Operation timed out after 30000 milliseconds",
  "retry_delay_ms": 1000
}

[2026-05-03 10:40:02] production.ERROR: SMS Send: Exception occurred {
  "reseller_id": 3,
  "recipient": "+254712345678",
  "error": "API request failed after 3 attempts: cURL error 28: Operation timed out",
  "exception": "Exception",
  "trace": "..."
}
```

### Failed SMS Send - Invalid Credentials

```
[2026-05-03 10:45:00] production.INFO: SMS Send: API response received {
  "reseller_id": 3,
  "reseller_email": "james.otieno@techsolutions.co.ke",
  "recipient": "+254712345678",
  "status_code": 401,
  "response_size": 89,
  "attempt": 1
}

[2026-05-03 10:45:00] production.ERROR: SMS Send: Failed {
  "reseller_id": 3,
  "recipient": "+254712345678",
  "status_code": 401,
  "response": {
    "error": "Invalid API key",
    "error_code": "AUTH_001"
  },
  "reason": "Unauthorized"
}
```

---

## Troubleshooting Steps

### Step 1: Check Reseller Settings

```bash
php artisan sms:logs --reseller=your-email@example.com
```

This will show:
- ✓ SMS Enabled
- ✓ API Key Set
- ✓ Sender ID Set

**If any check fails:**
- Go to `/reseller/settings`
- Configure SMS settings
- Save and test

### Step 2: Review Recent Logs

```bash
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log | grep "SMS Send"
```

Watch logs in real-time while sending a test SMS.

### Step 3: Test SMS Sending

Via web UI:
1. Navigate to `/reseller/settings`
2. Click "Send Test SMS"
3. Enter your phone number
4. Submit

The system will:
- Log all steps
- Save to `sms_logs` table
- Return success/failure message

### Step 4: Analyze Failed SMS

View failed SMS:
```bash
php artisan sms:logs --failed --limit=10
```

This shows:
- Recipient phone number
- Status and timestamp
- Full API response
- Who sent it

### Step 5: Check API Endpoint

Verify Talksasa API is accessible:

```php
$response = Http::timeout(30)->post('https://api.talksasa.com/sms/send', [
    'api_key' => 'your_api_key',
    'sender_id' => 'TALKSASA',
    'phone' => '+254712345678',
    'message' => 'Test',
]);

echo $response->status() . "\n";
echo $response->body() . "\n";
```

---

## Common Issues & Solutions

### Issue: "SMS settings not configured"

**Cause:** Missing API key or sender ID

**Solution:**
1. Go to `/reseller/settings`
2. Fill in API key and sender ID
3. Check "Enable SMS Notifications"
4. Click "Save SMS Settings"
5. Verify with "Send Test SMS"

### Issue: "API request failed after 3 attempts"

**Cause:** Network/connectivity issue

**Possible solutions:**
- Verify Talksasa API endpoint is correct
- Check firewall/proxy settings
- Verify API credentials are not corrupted
- Check system logs for network errors

```bash
# Monitor network requests
tcpdump -i any -n 'port 443 and host api.talksasa.com'
```

### Issue: "Invalid API key" (401 error)

**Cause:** Incorrect or expired API key

**Solution:**
1. Verify API key from Talksasa dashboard
2. Copy exact key without spaces
3. Update in `/reseller/settings`
4. Test with "Send Test SMS"

### Issue: "Invalid sender ID"

**Cause:** Sender ID exceeds 11 characters or has invalid characters

**Solution:**
1. Sender ID must be max 11 characters
2. Only alphanumeric characters allowed
3. Update in `/reseller/settings`
4. Common valid values: TALKSASA, MYCOMPANY, etc.

### Issue: SMS marked as "sent" but not received

**Cause:** Provider-side issue or incorrect phone number

**Checks:**
1. Verify phone number format (should be +254712345678)
2. Check Talksasa delivery logs on their dashboard
3. Verify sender ID is whitelisted
4. Check phone carrier (some may block specific senders)

---

## Performance Considerations

### Retry Logic
- Attempts: 3 retries for network failures
- Delay: 1 second between retries
- Timeout: 30 seconds per request

### Logging Performance
- Async logging: Logs are written asynchronously
- Database: SMS logs use indexed columns for fast queries
- No impact on SMS sending performance

### Bulk SMS
- Process: Individual log entries per recipient
- Limitation: Current API supports one request at a time
- Future: Batch API endpoint for improved throughput

---

## Monitoring & Alerting

### Laravel Telescope (if available)

```bash
php artisan telescope:install
```

View all SMS requests in real-time at `/telescope`

### Log Monitoring

Create a daily report:

```php
// In scheduler
Schedule::call(function () {
    $failed = \App\Models\SmsLog::where('status', 'failed')
        ->where('created_at', '>=', now()->subDay())
        ->count();
    
    if ($failed > 0) {
        \Mail::to('admin@example.com')->send(
            new SmsFailed FailureReport($failed)
        );
    }
})->daily();
```

---

## Security Notes

### Logging Sensitive Data

API keys are **partially masked** in logs:
```
"api_key_length": 32  // Never logged in full
```

When viewing database records:
```sql
-- Safe query - doesn't expose API key
SELECT id, recipient, status, created_at FROM sms_logs;

-- Unsafe - avoid
SELECT * FROM sms_logs; -- response field contains full data
```

### Access Control

- Only resellers can view their own SMS logs
- Admins can view all logs
- Logs are stored in application database

---

## Testing

### Unit Test Example

```php
public function testSmsLogging()
{
    $reseller = User::factory()->reseller()->create([
        'settings' => ['sms' => [
            'api_key' => 'test_key',
            'sender_id' => 'TEST',
            'enabled' => true,
        ]]
    ]);

    $smsService = app(TalksasaSmsService::class);
    
    // Mock the HTTP response
    Http::fake([
        'https://api.talksasa.com/sms/send' => Http::response([
            'sms_id' => 'sms_123',
            'status' => 'sent',
        ])
    ]);

    $result = $smsService->sendSms(
        $reseller,
        '0712345678',
        'Test message'
    );

    $this->assertTrue($result['success']);
    
    // Verify log entry
    $this->assertDatabaseHas('sms_logs', [
        'recipient' => '+254712345678',
        'status' => 'sent',
        'sender_id' => 'TEST',
    ]);
}
```

---

## Additional Resources

- **Talksasa API Docs**: https://docs.talksasa.com/sms
- **Laravel Logging**: https://laravel.com/docs/logging
- **HTTP Client**: https://laravel.com/docs/http-client
