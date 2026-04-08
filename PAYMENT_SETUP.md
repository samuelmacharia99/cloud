# Payment Gateway Setup Guide

This guide explains how to configure and use the integrated payment gateways: M-Pesa, Stripe, and PayPal.

---

## Overview

The system supports three payment gateways:

1. **M-Pesa** - Mobile money (Kenya)
2. **Stripe** - Credit/debit cards (International)
3. **PayPal** - Online payment (International)

Customers can select their preferred payment method when paying invoices.

---

## Configuration

Add the following environment variables to your `.env` file:

### M-Pesa (Safaricom Daraja API v2)

```env
# M-Pesa Configuration
MPESA_CONSUMER_KEY=your_consumer_key_here
MPESA_CONSUMER_SECRET=your_consumer_secret_here
MPESA_BUSINESS_SHORT_CODE=174379
MPESA_PASS_KEY=your_passkey_here
MPESA_PRODUCTION=false  # Set to true for production
```

**Getting M-Pesa Credentials:**

1. Go to https://developer.safaricom.co.ke
2. Create/Login to your account
3. Create a new app under "My Apps"
4. Under "OAuth 2.0" copy your Consumer Key and Consumer Secret
5. Create your passkey at https://sandbox.safaricom.co.ke (generate using test credentials first)
6. Your Business Short Code is the Paybill number (e.g., 174379 for Safaricom)

**Testing:**

- Use Business Short Code: `174379`
- Use Test Phone: `254708374149` or `254723456789`
- For sandbox, use the test credentials provided by Safaricom

### Stripe

```env
# Stripe Configuration
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

**Getting Stripe Credentials:**

1. Go to https://dashboard.stripe.com
2. Login or create account
3. In Developers > API Keys, copy:
   - Secret key (starts with `sk_`)
   - Publishable key (starts with `pk_`)
4. For webhooks, go to Developers > Webhooks
   - Create endpoint for `https://yourdomain.com/webhooks/stripe`
   - Select events: `checkout.session.completed`
   - Copy the Signing Secret

### PayPal

```env
# PayPal Configuration
PAYPAL_CLIENT_ID=your_client_id
PAYPAL_CLIENT_SECRET=your_client_secret
PAYPAL_PRODUCTION=false  # Set to true for production
PAYPAL_WEBHOOK_ID=your_webhook_id
```

**Getting PayPal Credentials:**

1. Go to https://developer.paypal.com
2. Login or create account
3. Create a REST API signature app
4. Copy Client ID and Secret from the app
5. For webhooks:
   - Go to Settings > Webhook Handler
   - Create webhook for endpoint: `https://yourdomain.com/webhooks/paypal`
   - Subscribe to events: `CHECKOUT.ORDER.COMPLETED`, `PAYMENT.CAPTURE.COMPLETED`
   - Copy Webhook ID

---

## How Payment Gateway Selection Works

### For Customers

1. Customer creates invoice by completing checkout
2. Customer views invoice and clicks "Pay Now" button
3. Selects preferred payment method (M-Pesa, Stripe, or PayPal)
4. If M-Pesa: Enters phone number → STK push sent → Completes on phone
5. If Stripe: Redirected to Stripe checkout → Completes card payment
6. If PayPal: Redirected to PayPal → Completes login and payment
7. After payment: Auto-redirect to success page, services provisioned

### For Admins

- Payment methods shown only if configured (credentials present in .env)
- Only fully configured gateways appear to customers
- Admin can see all payment records in admin panel

---

## Payment Flow

### M-Pesa Flow

```
Customer clicks Pay → Selects M-Pesa → Enters phone
    ↓
PaymentController.initiate() → MpesaService.initiate()
    ↓
Creates STK push request → Safaricom API
    ↓
M-Pesa prompt shown on customer's phone
    ↓
Customer enters PIN
    ↓
Safaricom sends callback webhook
    ↓
PaymentController.mpesaCallback() updates payment status
    ↓
Invoice marked paid → Services provisioned
```

### Stripe Flow

```
Customer clicks Pay → Selects Stripe
    ↓
PaymentController.initiate() → StripeService.initiate()
    ↓
Creates checkout session → Stripe
    ↓
Redirects to Stripe checkout URL
    ↓
Customer completes card payment
    ↓
Redirected to success page
    ↓
Webhook confirmation from Stripe
    ↓
Invoice marked paid → Services provisioned
```

### PayPal Flow

```
Customer clicks Pay → Selects PayPal
    ↓
PaymentController.initiate() → PayPalService.initiate()
    ↓
Creates order → PayPal API
    ↓
Redirects to PayPal approval URL
    ↓
Customer logs in and approves payment
    ↓
Redirected to success page
    ↓
Payment captured → Services provisioned
```

---

## Auto-Provisioning Services

When payment is received:

1. Payment status updated to `completed`
2. Invoice status updated to `paid`
3. `PaymentController.provisionServices()` triggered
4. All pending services in invoice marked as `provisioning`
5. `service:provision` command run for each service
6. Container deployed, domain registered, etc.

---

## Testing Payments

### M-Pesa Testing (Sandbox)

```bash
# Use these test credentials
Business Short Code: 174379
Consumer Key: (from Safaricom developer portal)
Consumer Secret: (from Safaricom developer portal)
Pass Key: (from Safaricom developer portal)
Test Phone: 254708374149 or 254723456789

# To simulate payment:
1. Initiate payment with test phone
2. Query M-Pesa API to check status (may auto-complete in sandbox)
3. Or manually simulate webhook callback
```

### Stripe Testing

```bash
# Use these test card numbers
Visa: 4242 4242 4242 4242
Visa Debit: 4000 0566 5566 5556
Mastercard: 5555 5555 5555 4444
Amex: 3782 822463 10005

Any future expiry date and any 3-digit CVC
```

### PayPal Testing

```bash
# Use PayPal Sandbox Business Account
# Login to https://sandbox.paypal.com

# Test Buyer Account: buyer@example.com
# Test Seller Account: seller@example.com
(Create these in PayPal Developer > Sandbox Accounts)
```

---

## Webhook Verification

### M-Pesa Callback

```
POST /webhooks/mpesa/callback
Headers: (standard HTTPS)
Body: {
    "Body": {
        "stkCallback": {
            "MerchantRequestID": "...",
            "CheckoutRequestID": "...",
            "ResultCode": 0,  // 0 = success
            "ResultDesc": "The service request has been processed successfully.",
            "CallbackMetadata": {
                "Item": [
                    {"Name": "Amount", "Value": 10},
                    {"Name": "MpesaReceiptNumber", "Value": "LHG31H500210"},
                    {"Name": "TransactionDate", "Value": 20191122063845},
                    {"Name": "PhoneNumber", "Value": 254708374149}
                ]
            }
        }
    }
}
```

### Stripe Webhook

```
POST /webhooks/stripe
Headers: Stripe-Signature: t=timestamp, v1=signature
Body: {
    "type": "checkout.session.completed",
    "data": {
        "object": {
            "id": "cs_...",
            "payment_status": "paid",
            "metadata": {
                "invoice_id": 123
            }
        }
    }
}
```

### PayPal Webhook

```
POST /webhooks/paypal
Headers: PayPal-Transmission-Id, PayPal-Transmission-Time, etc.
Body: {
    "event_type": "CHECKOUT.ORDER.COMPLETED",
    "resource": {
        "id": "3JS30575RG000364L",
        "status": "COMPLETED",
        "custom_id": "123",
        "amount": {"value": "100.00", "currency_code": "USD"}
    }
}
```

---

## Production Deployment

### Before Going Live

1. **Switch to Production Credentials**
   ```env
   MPESA_PRODUCTION=true
   PAYPAL_PRODUCTION=true
   STRIPE_SECRET_KEY=sk_live_...
   ```

2. **Enable HTTPS**
   - All payment endpoints MUST be HTTPS
   - Browsers will block HTTP payment forms

3. **Configure Webhook URLs**
   - M-Pesa: Callback URL in code (already: `/webhooks/mpesa/callback`)
   - Stripe: https://yourdomain.com/webhooks/stripe
   - PayPal: https://yourdomain.com/webhooks/paypal

4. **Test Each Gateway**
   ```bash
   # Test M-Pesa with real account
   # Test Stripe with test card
   # Test PayPal with sandbox account
   ```

5. **Set Up Logging**
   ```env
   LOG_CHANNEL=stack
   LOG_LEVEL=debug  # During first week
   LOG_LEVEL=warning  # After stable
   ```

6. **Monitor Payments**
   - Admin > Payments dashboard
   - Check failed payments daily
   - Monitor webhook delivery

---

## Common Issues

### M-Pesa Payment Stuck in "Pending"

- Callback webhook not being called
- Check M-Pesa phone number format (254xxxxxxxxx)
- Verify callback URL is reachable
- Check M-Pesa credentials are correct
- Enable detailed logging to debug

### Stripe Not Creating Session

- Check STRIPE_SECRET_KEY is valid
- Verify API key not expired
- Check webhook secret configuration
- Review Stripe dashboard for errors

### PayPal Authorization Fails

- Check CLIENT_ID and SECRET are correct
- Verify PAYPAL_PRODUCTION flag matches credentials
- Confirm webhook URL is publicly accessible
- Check PayPal account is active

### Services Not Provisioning After Payment

- Check `service:provision` command exists
- Verify invoice items have `service_id` set
- Check Laravel logs for provisioning errors
- Ensure node credentials are correct for deployment

---

## Support & Troubleshooting

### View Payment Logs

```bash
# In Laravel Logs
tail -f storage/logs/laravel.log | grep -i payment

# Specific payment
grep "Invoice #INV-20240101-00001" storage/logs/laravel.log
```

### Check Payment Status via Artisan

```bash
# Query M-Pesa payment status
php artisan payment:check-mpesa --id=<checkout_request_id>

# Check invoice payments
php artisan payment:list --invoice=<invoice_id>
```

### Manual Payment Recording

```bash
# Mark payment as completed (admin only)
php artisan payment:complete --invoice=<id> --amount=<amount> --method=<method>
```

---

## Security Considerations

1. **API Keys** - Never commit to git, use .env only
2. **Webhooks** - Verify signatures on all webhooks
3. **HTTPS Required** - All payment endpoints must be HTTPS
4. **Rate Limiting** - Enabled on payment endpoints
5. **Encryption** - Sensitive data logged securely
6. **PCI Compliance** - Card data never stored locally

---

## Useful Resources

- **M-Pesa API**: https://developer.safaricom.co.ke
- **Stripe API**: https://stripe.com/docs/api
- **PayPal API**: https://developer.paypal.com/docs/api/overview
- **Webhook Testing**: https://webhook.site (temporary URL for testing)

---

## Next Steps

1. Get API credentials for at least one gateway
2. Configure in `.env`
3. Test payment flow with test credentials
4. Deploy to staging for team testing
5. Deploy to production with live credentials
6. Monitor webhook delivery and payment completion
7. Set up admin alerts for failed payments

