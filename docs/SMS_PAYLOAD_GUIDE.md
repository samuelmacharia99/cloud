# SMS Payload Structure - Talksasa Bulk SMS

## Overview
This document describes the payload structure used when sending SMS messages on behalf of resellers through the Talksasa Cloud platform.

## Reseller SMS Configuration

The reseller SMS settings stored in `user.settings['sms']`:

```json
{
  "api_key": "sms_api_key_from_talksasa",
  "sender_id": "TALKSASA",
  "enabled": true,
  "updated_at": "2026-05-03T10:30:00Z"
}
```

**Fields:**
- `api_key` (string, max 255): Talksasa Bulk SMS API key for authentication
- `sender_id` (string, max 11): Sender ID displayed to SMS recipients
- `enabled` (boolean): Whether SMS is enabled for this reseller
- `updated_at` (ISO8601): Last update timestamp

---

## Single SMS Payload

### Structure
```json
{
  "api_key": "sms_api_key_from_talksasa",
  "sender_id": "TALKSASA",
  "phone": "+254712345678",
  "message": "Your order #12345 has been confirmed. Total: KES 5,000",
  "timestamp": "2026-05-03T10:30:00Z"
}
```

### Fields
| Field | Type | Required | Description | Example |
|-------|------|----------|-------------|---------|
| `api_key` | string | Yes | Talksasa SMS API key | `sk_live_abc123...` |
| `sender_id` | string | Yes | Sender ID (max 11 chars) | `TALKSASA` |
| `phone` | string | Yes | Recipient phone in E.164 format | `+254712345678` |
| `message` | string | Yes | SMS message content | `Your invoice is ready` |
| `timestamp` | ISO8601 | Yes | Request timestamp for audit | `2026-05-03T10:30:00Z` |

### Phone Number Normalization

Supported formats (automatically normalized to E.164):
```
Input              →  Output
0712345678         →  +254712345678
254712345678       →  +254712345678
+254712345678      →  +254712345678
712345678          →  +254712345678
```

### PHP Usage Example

```php
$reseller = \App\Models\User::find($resellerId);
$smsService = app(\App\Services\SmsPayloadService::class);

// Build single SMS payload
$payload = $smsService->buildSmsPayload(
    reseller: $reseller,
    phoneNumber: '0712345678',
    message: 'Your invoice is ready'
);

// Result:
// {
//   "api_key": "sk_live_...",
//   "sender_id": "TALKSASA",
//   "phone": "+254712345678",
//   "message": "Your invoice is ready",
//   "timestamp": "2026-05-03T10:30:00Z"
// }
```

---

## Bulk SMS Payload

### Structure
```json
{
  "api_key": "sms_api_key_from_talksasa",
  "sender_id": "TALKSASA",
  "recipients": [
    "+254712345678",
    "+254723456789",
    "+254734567890"
  ],
  "message": "Scheduled maintenance tonight at 2 AM",
  "timestamp": "2026-05-03T10:30:00Z"
}
```

### Fields
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `api_key` | string | Yes | Talksasa SMS API key |
| `sender_id` | string | Yes | Sender ID (max 11 chars) |
| `recipients` | array | Yes | Array of phone numbers in E.164 format |
| `message` | string | Yes | SMS message content (same for all recipients) |
| `timestamp` | ISO8601 | Yes | Request timestamp for audit |

### PHP Usage Example

```php
$reseller = \App\Models\User::find($resellerId);
$smsService = app(\App\Services\SmsPayloadService::class);

// Build bulk SMS payload
$payload = $smsService->buildBulkSmsPayload(
    reseller: $reseller,
    phoneNumbers: [
        '0712345678',
        '0723456789',
        '+254734567890'
    ],
    message: 'System maintenance notification'
);

// Result:
// {
//   "api_key": "sk_live_...",
//   "sender_id": "TALKSASA",
//   "recipients": [
//     "+254712345678",
//     "+254723456789",
//     "+254734567890"
//   ],
//   "message": "System maintenance notification",
//   "timestamp": "2026-05-03T10:30:00Z"
// }
```

---

## Webhook Payload (Delivery Status)

### Structure
```json
{
  "sms_id": "sms_abc123xyz789",
  "status": "delivered",
  "phone": "+254712345678",
  "timestamp": "2026-05-03T10:35:00Z"
}
```

### Fields
| Field | Type | Values | Description |
|-------|------|--------|-------------|
| `sms_id` | string | - | Unique SMS identifier from Talksasa |
| `status` | string | `delivered`, `failed`, `pending` | Delivery status |
| `phone` | string | - | Recipient phone number |
| `timestamp` | ISO8601 | - | Webhook notification timestamp |

### Status Values
- **delivered**: SMS successfully delivered to recipient
- **failed**: SMS delivery failed (invalid number, provider issue, etc.)
- **pending**: SMS pending delivery

---

## Integration Examples

### Sending Invoice Notification SMS

```php
use App\Services\SmsPayloadService;
use Illuminate\Support\Facades\Http;

class InvoiceNotificationService
{
    public function notifyReseller(Invoice $invoice, User $reseller): void
    {
        $smsService = app(SmsPayloadService::class);
        
        $payload = $smsService->buildSmsPayload(
            reseller: $reseller,
            phoneNumber: $invoice->user->phone,
            message: "Invoice #{$invoice->number} for KES {$invoice->total} is due on {$invoice->due_date->format('M d, Y')}"
        );

        // Send to Talksasa SMS API
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => "Bearer {$payload['api_key']}",
        ])->post('https://api.talksasa.com/sms/send', $payload);

        \Log::info('Invoice SMS sent', [
            'invoice_id' => $invoice->id,
            'reseller_id' => $reseller->id,
            'response_code' => $response->status(),
        ]);
    }
}
```

### Sending Order Confirmation SMS

```php
public function notifyOrderConfirmation(Order $order, User $reseller): void
{
    $smsService = app(SmsPayloadService::class);
    
    $payload = $smsService->buildSmsPayload(
        reseller: $reseller,
        phoneNumber: $order->customer->phone,
        message: "Order #{$order->number} confirmed! Total: KES {$order->total}. Ref: {$order->id}"
    );

    // Send to Talksasa SMS API
    Http::post('https://api.talksasa.com/sms/send', $payload);
}
```

### Sending Bulk Notification SMS

```php
public function notifyMultipleCustomers(User $reseller, string $message, array $customerIds): void
{
    $phoneNumbers = \App\Models\User::whereIn('id', $customerIds)
        ->pluck('phone')
        ->toArray();

    $smsService = app(SmsPayloadService::class);
    
    $payload = $smsService->buildBulkSmsPayload(
        reseller: $reseller,
        phoneNumbers: $phoneNumbers,
        message: $message
    );

    // Send to Talksasa SMS API
    Http::post('https://api.talksasa.com/sms/bulk', $payload);
}
```

---

## Error Handling

### Validation Errors

```php
try {
    $payload = $smsService->buildSmsPayload(
        reseller: $reseller,
        phoneNumber: 'invalid-phone',
        message: $message
    );
} catch (\InvalidArgumentException $e) {
    \Log::error('SMS Payload validation failed: ' . $e->getMessage());
    return back()->with('error', 'Invalid phone number format');
}
```

### API Errors

```php
$response = Http::post('https://api.talksasa.com/sms/send', $payload);

if ($response->failed()) {
    \Log::error('SMS API Error', [
        'status' => $response->status(),
        'error' => $response->json('error'),
        'payload' => $payload,
    ]);
    
    return back()->with('error', 'Failed to send SMS');
}
```

---

## Reseller Settings Configuration

Resellers configure their SMS settings via `/reseller/settings`:

```html
<!-- Settings Form -->
<form method="POST" action="{{ route('reseller.settings.sms.update') }}">
    @csrf
    
    <input type="password" name="sms_api_key" 
        value="{{ $smsSettings['api_key'] }}" 
        placeholder="Talksasa API Key" required>
    
    <input type="text" name="sms_sender_id" 
        value="{{ $smsSettings['sender_id'] }}" 
        placeholder="e.g., TALKSASA" maxlength="11" required>
    
    <input type="hidden" name="sms_enabled" value="0">
    <input type="checkbox" name="sms_enabled" value="1" 
        {{ $smsSettings['enabled'] ? 'checked' : '' }}>
    
    <button type="submit">Save SMS Settings</button>
</form>
```

### Test SMS Endpoint

```html
<form method="POST" action="{{ route('reseller.settings.sms.test') }}">
    @csrf
    <input type="tel" name="phone" 
        placeholder="Phone number with country code"
        value="+254712345678" required>
    <button type="submit">Send Test SMS</button>
</form>
```

---

## Security Notes

1. **API Key Storage**
   - API keys are stored encrypted in the `settings` JSON column
   - Never log API keys in full
   - Use partial masking: `sk_live_...xxxxx`

2. **Rate Limiting**
   - Implement rate limiting per reseller
   - Prevent SMS bombing attacks
   - Log all SMS attempts for audit

3. **Validation**
   - Validate phone numbers before sending
   - Verify sender_id doesn't exceed 11 characters
   - Ensure message length doesn't exceed SMS limits (160 chars for standard SMS)

4. **Audit Trail**
   - Log all SMS sends with reseller_id, recipient, timestamp
   - Track delivery status via webhooks
   - Maintain SMS history for compliance

---

## Database Schema

SMS logs are stored in the `sms_logs` table:

```php
Schema::create('sms_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id');        // Reseller or customer
    $table->foreignId('reseller_id');    // Which reseller sent it
    $table->string('phone');             // Recipient phone
    $table->text('message');             // SMS content
    $table->string('status')->default('pending'); // pending, delivered, failed
    $table->string('external_id')->nullable(); // ID from SMS provider
    $table->json('response')->nullable(); // Provider response
    $table->timestamps();
});
```

---

## Testing

### Unit Test Example

```php
public function testSmsPayloadGeneration()
{
    $reseller = User::factory()->reseller()->create([
        'settings' => [
            'sms' => [
                'api_key' => 'test_key_123',
                'sender_id' => 'TEST',
                'enabled' => true,
            ]
        ]
    ]);

    $smsService = app(SmsPayloadService::class);
    
    $payload = $smsService->buildSmsPayload(
        reseller: $reseller,
        phoneNumber: '0712345678',
        message: 'Test message'
    );

    $this->assertEquals('test_key_123', $payload['api_key']);
    $this->assertEquals('TEST', $payload['sender_id']);
    $this->assertEquals('+254712345678', $payload['phone']);
    $this->assertEquals('Test message', $payload['message']);
}
```

---

## References

- [Talksasa Bulk SMS API Documentation](https://docs.talksasa.com/sms)
- [E.164 Phone Number Format](https://en.wikipedia.org/wiki/E.164)
- [SMS Character Encoding](https://en.wikipedia.org/wiki/SMS#Message_length)
