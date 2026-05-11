# Domain Invoice Generation Audit

## Current Status

❌ **MISSING**: Automated invoice generation for domain renewals

### What Exists

**Service Renewals** (✅ Fully Automated):
- GenerateInvoicesCommand runs daily
- Generates invoices for services where `next_due_date <= now()`
- Invoices are created ~10 days before renewal (invoice_due_days = 14)
- Fully automatic, requires no customer action

**Domain Renewals** (❌ Manual):
- DomainRenewalService can create invoices when initiated
- But renewal invoices are only created when:
  1. Customer manually initiates renewal via UI
  2. Admin manually creates renewal order
  3. Customer payment triggers renewal processing
- **NO automated invoice generation for expiring domains**
- Domains can expire without ever generating an invoice

---

## Problem

### Service Renewal Flow
```
Service next_due_date arrives
  ↓
GenerateInvoicesCommand (daily cron) fires
  ↓
Invoice automatically created 10 days before renewal
  ↓
Customer notified of pending renewal
  ↓
Customer pays invoice or service suspends
```

### Current Domain Renewal Flow
```
Domain expires_at arrives
  ↓
[NOTHING HAPPENS AUTOMATICALLY]
  ↓
Customer must manually go to dashboard and request renewal
  ↓
OR admin must manually create renewal order
  ↓
Then invoice is created
  ↓
Customer pays
```

### Impact
- Domains can expire unexpectedly
- Customers may not know renewal is due
- Lost renewal revenue if customers don't notice
- Inconsistent behavior vs services

---

## Database Schema

### Domains Table
```sql
- id (PK)
- user_id (FK)
- name
- extension
- status (active, inactive, expired, transferred)
- expires_at (timestamp) ← KEY FIELD
- renewal_status (pending, active, completed)
- created_at
- updated_at
```

### Domain Renewal Orders Table
```sql
- id (PK)
- domain_id (FK)
- user_id (FK)
- invoice_id (FK, nullable)
- years (int)
- amount (decimal)
- status (pending, invoiced, paid, completed, failed)
- expires_at (timestamp)
- invoiced_at (timestamp, nullable)
- created_at
- updated_at
```

### Proposed Logic

**Daily Cron Job: `GenerateDomainInvoicesCommand`**

Run daily to check expiring domains:

```
For each domain where:
  - status = 'active' OR 'renewal_needed'
  - expires_at <= now() + 30 days
  - expires_at > now() (not already expired)
  - NO pending/unpaid renewal invoice exists
  
Create DomainRenewalOrder with:
  - years: 1 (default renewal period)
  - status: 'pending'
  - expires_at: now() + 10 days (time to pay)
  
Create Invoice via DomainRenewalService.createInvoice()
Notify customer of renewal invoice
```

---

## Implementation Details

### Command: GenerateDomainInvoicesCommand

```php
class GenerateDomainInvoicesCommand extends BaseCronCommand
{
    protected $signature = 'cron:generate-domain-invoices';
    protected $description = 'Generate renewal invoices for domains expiring in 30 days';

    public function handleCron(): string
    {
        $thirtyDaysFromNow = now()->addDays(30);
        
        $domains = Domain::where('status', 'active')
            ->whereDate('expires_at', '<=', $thirtyDaysFromNow->toDateString())
            ->whereDate('expires_at', '>', now()->toDateString())
            // Don't create if renewal already in progress
            ->whereDoesntHave('renewalOrders', function ($q) {
                $q->whereIn('status', ['pending', 'invoiced'])
                  ->where('created_at', '>=', now()->subDays(7));
            })
            ->with(['user', 'domainExtension'])
            ->get();

        $count = 0;
        foreach ($domains as $domain) {
            DB::transaction(function () use ($domain, &$count) {
                $renewalService = app(DomainRenewalService::class);
                
                // Create renewal order
                $renewalOrder = DomainRenewalOrder::create([
                    'domain_id' => $domain->id,
                    'user_id' => $domain->user_id,
                    'years' => 1,
                    'amount' => $this->getRenewalPrice($domain),
                    'status' => 'pending',
                    'expires_at' => now()->addDays(10),
                ]);

                // Create invoice
                $invoice = $renewalService->createInvoice($renewalOrder);
                
                // Notify customer
                app(NotificationService::class)->notifyDomainRenewalInvoice($invoice);
                
                $count++;
            });
        }

        return "Generated {$count} renewal invoice(s) for {$domains->count()} expiring domain(s).";
    }

    private function getRenewalPrice(Domain $domain): float
    {
        $extension = $domain->domainExtension;
        if (!$extension) {
            return 0;
        }
        
        $pricing = $extension->getRetailPricing(1); // 1 year renewal
        return $pricing->renewal_price ?? $pricing->price ?? 0;
    }
}
```

### Cron Seeder Entry

```php
[
    'name' => 'Generate Domain Invoices',
    'description' => 'Generate renewal invoices for domains expiring in 30 days',
    'command' => 'cron:generate-domain-invoices',
    'schedule' => '0 2 * * *', // 2 AM daily
    'enabled' => true,
]
```

### Notification Service Method

```php
public function notifyDomainRenewalInvoice(Invoice $invoice): void
{
    $domainName = $invoice->items->first()?->domain?->fullName() ?? 'Your domain';
    
    Mail::to($invoice->user->email)->send(
        new DomainRenewalInvoiceNotification($invoice, $domainName)
    );
}
```

---

## Configuration

### Settings to Add

```php
[
    'domain_renewal_advance_days' => 30,  // Generate invoices 30 days before expiry
    'domain_renewal_years' => 1,          // Default renewal period
    'domain_renewal_payment_days' => 10,  // Days to pay invoice before expiry
]
```

---

## Edge Cases to Handle

1. **Already Expired Domains**
   - Don't create invoices for domains already expired
   - Flag for admin action instead

2. **Renewal Already in Progress**
   - Check if renewal order already exists in last 7 days
   - Don't create duplicate invoices

3. **Customer Initiates Renewal First**
   - If customer creates renewal before cron runs, skip creating another
   - Use `whereDoesntHave('renewalOrders')` check

4. **Multiple Extensions**
   - Domain can have multiple extensions (.com, .net, etc.)
   - Each should have separate renewal invoice

5. **Batch Cleanup**
   - If renewal invoice unpaid for 30 days, escalate to admin
   - Archive old renewal orders

6. **Pricing Changes**
   - Use current pricing on invoice generation day
   - Not pricing from renewal order creation date

---

## Testing Strategy

### Unit Tests
- Domain with no renewal orders generates invoice
- Domain with pending renewal order doesn't duplicate
- Domain already expired doesn't generate invoice
- Correct renewal price is used from domain extension

### Integration Tests
- Cron job processes 50+ domains correctly
- Notifications are sent to correct customer email
- Invoice items are created with correct domain info
- Database transaction rolls back on failure

### Manual Testing
- Create domain with expires_at = 25 days from now
- Run `php artisan cron:generate-domain-invoices`
- Verify renewal order created
- Verify invoice created
- Verify customer notified
- Verify no duplicate on second run

---

## Migration Plan

### Phase 1: Implementation (Immediate)
1. Create GenerateDomainInvoicesCommand
2. Add to CronJobSeeder
3. Add notification method to NotificationService
4. Write tests

### Phase 2: Deployment (When Ready)
1. Migrate changes to production
2. Run seeder to add cron job
3. Manually generate invoices for domains expiring in next 30 days:
   ```bash
   php artisan cron:generate-domain-invoices
   ```

### Phase 3: Monitoring (First 30 Days)
- Monitor cron execution logs
- Check invoice generation count
- Monitor customer email delivery
- Check for any payment issues

---

## Benefits

✅ **Consistent with Service Renewals**
- Same automated approach as services
- Customers know what to expect

✅ **Prevents Unexpected Expirations**
- Customers have 30 days notice
- Time to pay invoice

✅ **Increases Renewal Revenue**
- Proactive notifications increase payment rate
- Reduces expired domain losses

✅ **Better Customer Experience**
- No surprises
- Clear renewal timeline
- Dedicated invoice for tracking

✅ **Admin Visibility**
- Track unpaid domain renewals
- Identify at-risk domains
- Escalate past-due renewals

---

## Open Questions

1. **Renewal Period**: Should default be 1 year? Or use customer preference?
2. **Grace Period**: Should expired domains have grace period for renewal?
3. **Auto-Renewal**: Should we implement auto-renewal if payment on file?
4. **Pricing**: Should renewal price match registration price, or separate pricing?
5. **Notifications**: Email only, or SMS? In-app notification?

---

## Summary

**Current State**: Domain invoices are created manually when customers request renewal.

**Desired State**: Invoices are generated automatically 30 days before expiry, matching the service renewal behavior.

**Recommendation**: Implement GenerateDomainInvoicesCommand immediately to bring domain renewals in line with service renewals and improve customer experience.

**Impact**: ⭐⭐⭐ High - Addresses critical revenue risk and UX gap
