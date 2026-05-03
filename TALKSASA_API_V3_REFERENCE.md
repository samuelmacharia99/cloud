# Talksasa Bulk SMS API v3 - Complete Reference

## Official Documentation
- **Endpoint**: https://bulksms.talksasa.com/api/v3/sms/send
- **Method**: POST
- **Authentication**: Bearer Token in Authorization header

---

## Single SMS Payload

### Request
```bash
curl -X POST https://bulksms.talksasa.com/api/v3/sms/send \
  -H 'Authorization: Bearer 49|LNFe8WJ7CPtvl2mzowAB4ll4enbFR0XGgnQh2qWY' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{
    "recipient": "254712345678",
    "sender_id": "TALKSASA",
    "type": "plain",
    "message": "This is a test message"
  }'
```

### JSON Payload
```json
{
  "recipient": "254712345678",
  "sender_id": "TALKSASA",
  "type": "plain",
  "message": "This is a test message"
}
```

### Parameters

| Parameter | Required | Type | Max Length | Description |
|-----------|----------|------|-----------|-------------|
| `recipient` | Yes | string | N/A | Phone number(s) comma-separated |
| `sender_id` | Yes | string | 11 | Alphanumeric sender identifier |
| `type` | Yes | string | N/A | Must be "plain" for SMS |
| `message` | Yes | string | N/A | SMS message content |
| `schedule_time` | No | datetime | N/A | Scheduled time (Y-m-d H:i) |
| `dlt_template_id` | No | string | N/A | DLT template ID |

### HTTP Headers
```
Authorization: Bearer {api_token}
Content-Type: application/json
Accept: application/json
```

### Success Response (200)
```json
{
  "status": "success",
  "data": "sms reports with all details"
}
```

### Error Response
```json
{
  "status": "error",
  "message": "A human-readable description of the error."
}
```

---

## Bulk SMS Payload (Multiple Recipients)

### Request
```bash
curl -X POST https://bulksms.talksasa.com/api/v3/sms/send \
  -H 'Authorization: Bearer 2|1qF0ry6pPJISiV8HYIciUekmiqAajvy8dkFN0d2T9c3d9c27' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{
    "recipient": "254781000403,254707711847",
    "sender_id": "TALKSASA",
    "type": "plain",
    "message": "This is a test message"
  }'
```

### JSON Payload
```json
{
  "recipient": "254781000403,254707711847",
  "sender_id": "TALKSASA",
  "type": "plain",
  "message": "This is a test message"
}
```

### Key Differences from Single SMS
- `recipient` is a **comma-separated string**, NOT an array
- **No spaces** after commas
- Can handle unlimited recipients in single API call

---

## Phone Number Formats

All these formats are accepted and normalized:

| Format | Example | Status |
|--------|---------|--------|
| International E.164 | +254712345678 | ✓ Standard |
| Country code prefix | 254712345678 | ✓ Accepted |
| Leading zero | 0712345678 | ✓ Accepted |
| Without country code | 712345678 | ✓ Accepted (assumes +254) |

---

## Sender ID Rules

- **Maximum 11 characters** (alphanumeric only)
- **Common Examples:**
  - `TALKSASA` (8 chars) ✓
  - `MYCOMPANY` (9 chars) ✓
  - `INVOICES` (8 chars) ✓
  - `ABC123` (6 chars) ✓
  - `A` (1 char) ✓

- **Invalid:**
  - `MY COMPANY` (contains space) ✗
  - `MY-COMPANY` (contains hyphen) ✗
  - `MYCOMPANYLONG` (13 chars, exceeds limit) ✗

---

## Message Content

### Character Limits
- **Standard SMS**: 160 characters (GSM 7-bit)
- **Unicode SMS**: 70 characters (for special characters, emojis)

### Character Types
- **English**: All ASCII characters
- **Special Characters**: Emojis, accents automatically converted to Unicode mode
- **Languages**: Supports any Unicode language

### Examples
```
Standard (160 chars):
"Your order has been confirmed. Order #12345. Total: KES 5,000. Thank you!"

With Emoji (70 chars mode):
"Order confirmed! 🎉 Click here: example.com"

Arabic (70 chars mode):
"طلبك قد تم تأكيده بنجاح"
```

---

## Scheduled Send

### Format
- **Required Format**: `Y-m-d H:i` (RFC3339 compatible)
- **Example**: `2026-05-03 14:30`
- **Timezone**: Server timezone (usually UTC)

### Example with Schedule
```json
{
  "recipient": "254712345678",
  "sender_id": "TALKSASA",
  "type": "plain",
  "message": "Scheduled message",
  "schedule_time": "2026-05-03 14:30"
}
```

---

## DLT (Distributed Ledger Technology)

### Purpose
- Compliance for regulated industries
- Template-based messages for verification

### Usage
```json
{
  "recipient": "254712345678",
  "sender_id": "TALKSASA",
  "type": "plain",
  "message": "Verification code: 123456",
  "dlt_template_id": "template_id_123"
}
```

### Note
- Requires DLT template registration with Talksasa
- Optional but recommended for compliance

---

## Response Examples

### Success Response
```json
{
  "status": "success",
  "data": {
    "message_id": "606812e63f78b",
    "recipient": "254712345678",
    "status": "sent",
    "timestamp": "2026-05-03T10:30:00Z",
    "cost": 0.50
  }
}
```

### Bulk Success Response
```json
{
  "status": "success",
  "data": {
    "campaign_id": "campaign_123abc",
    "recipients": 2,
    "status": "sent",
    "timestamp": "2026-05-03T10:30:00Z",
    "total_cost": 1.00
  }
}
```

### Error Responses

**Invalid API Token**
```json
{
  "status": "error",
  "message": "Unauthorized"
}
```
Status Code: `401`

**Invalid Phone Number**
```json
{
  "status": "error",
  "message": "Invalid phone number format"
}
```
Status Code: `400`

**Sender ID Too Long**
```json
{
  "status": "error",
  "message": "Sender ID must not exceed 11 characters"
}
```
Status Code: `400`

**Rate Limited**
```json
{
  "status": "error",
  "message": "Too many requests. Please try again later."
}
```
Status Code: `429`

---

## PHP Implementation (Laravel)

### Using TalksasaSmsService (Recommended)

```php
use App\Services\TalksasaSmsService;

// Single SMS
$smsService = app(TalksasaSmsService::class);
$result = $smsService->sendSms(
    reseller: $reseller,
    phoneNumber: '0712345678',
    message: 'Your invoice is ready'
);

if ($result['success']) {
    echo "SMS sent: " . $result['talksasa_status'];
} else {
    echo "Error: " . $result['message'];
}
```

### Result Structure

```php
[
    'success' => true,
    'status' => 'sent',                    // 'sent' or 'failed'
    'talksasa_status' => 'success',        // Talksasa API status
    'message' => 'SMS sent successfully',  // User-friendly message
    'response' => [                        // Full API response
        'status' => 'success',
        'data' => [...]
    ]
]
```

### Direct HTTP Request (Laravel)

```php
use Illuminate\Support\Facades\Http;

$smsSettings = app(\App\Services\ResellerSettingsService::class)
    ->getSmsSettings($reseller);

$response = Http::withHeaders([
    'Authorization' => 'Bearer ' . $smsSettings['api_key'],
    'Content-Type' => 'application/json',
    'Accept' => 'application/json',
])->post('https://bulksms.talksasa.com/api/v3/sms/send', [
    'recipient' => '254712345678',
    'sender_id' => 'TALKSASA',
    'type' => 'plain',
    'message' => 'Test message'
]);

if ($response->successful()) {
    $data = $response->json();
    if ($data['status'] === 'success') {
        echo "SMS sent successfully";
    }
} else {
    echo "API Error: " . $response->status();
}
```

---

## Debugging

### View SMS Logs
```bash
php artisan sms:logs
```

### View Failed SMS
```bash
php artisan sms:logs --failed
```

### Check Reseller Configuration
```bash
php artisan sms:logs --reseller=email@example.com
```

### Check Application Logs
```bash
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log | grep "SMS Send"
```

### Database Query
```sql
SELECT * FROM sms_logs 
WHERE status = 'failed' 
ORDER BY created_at DESC 
LIMIT 20;
```

---

## Common Issues

### Issue: "Unauthorized" (401)
**Cause**: Invalid or expired API token
**Solution**: 
1. Get fresh API token from Talksasa dashboard
2. Update in `/reseller/settings`
3. Test with "Send Test SMS"

### Issue: "Invalid phone number format"
**Cause**: Phone number not properly formatted
**Solution**:
- Use: `254712345678` or `+254712345678` or `0712345678`
- Don't use: `254 712 345 678` (spaces) or `(254) 712-345-678` (symbols)

### Issue: "Too many requests" (429)
**Cause**: Rate limiting (too many SMS in short time)
**Solution**:
- Implement retry logic with delays
- Use batch sending if available
- Contact Talksasa for rate limit increase

### Issue: "Sender ID must not exceed 11 characters"
**Cause**: Sender ID too long
**Solution**:
- Maximum 11 alphanumeric characters
- Examples: `TALKSASA` (8), `MYAPP` (5), `ALERTS` (6)

---

## Rate Limits & Quotas

### Per Minute
- Typically 60 requests/minute per API key
- Contact Talksasa for higher limits

### Per Account
- Depends on subscription level
- Check Talksasa dashboard for account limits

### Retry Strategy
- Automatic retries: 3 attempts with 1-second delay
- Configurable in `TalksasaSmsService`

---

## Security Best Practices

1. **Never Log API Keys** - Only log length, not full key
2. **Use Bearer Tokens** - Always use Authorization header
3. **HTTPS Only** - All requests must be encrypted
4. **Validate Input** - Phone numbers, messages
5. **Audit Trail** - Log all SMS attempts in database
6. **Error Handling** - Don't expose API details to end users

---

## Testing

### Test SMS via Web UI
1. Go to `/reseller/settings`
2. Configure API token and sender ID
3. Click "Send Test SMS"
4. Enter test phone number
5. Check logs: `php artisan sms:logs`

### Unit Test Example
```php
public function testSmsSending()
{
    Http::fake([
        'https://bulksms.talksasa.com/api/v3/sms/send' => Http::response([
            'status' => 'success',
            'data' => ['message_id' => '123abc']
        ])
    ]);

    $result = $smsService->sendSms($reseller, '0712345678', 'Test');
    
    $this->assertTrue($result['success']);
    $this->assertEquals('success', $result['talksasa_status']);
}
```

---

## Contact & Support

- **Talksasa Dashboard**: https://bulksms.talksasa.com
- **API Endpoint**: https://bulksms.talksasa.com/api/v3/sms/send
- **Support**: Check dashboard for contact details
