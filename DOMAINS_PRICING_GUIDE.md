# Domain & Pricing System - Complete Implementation Guide

## Overview
A production-grade two-tier domain pricing system that enables:
- **Retail pricing** for direct customers (full price)
- **Wholesale pricing** for resellers (bulk discount)
- Automatic margin calculation for reseller profitability
- 5 billing periods per extension (1, 2, 3, 5, 10 years)

## Database Schema

### Tables Created

#### `domain_extensions`
Represents TLD configurations (.com, .co.ke, .org, etc.)
```
- id (primary key)
- extension (unique) — ".com", ".co.ke", ".org"
- description — "Commercial domain", "Kenya Country Code"
- registrar — "ICANN", "KENIC"
- enabled — boolean
- dns_management — boolean (DNS features available)
- auto_renewal — boolean (auto-renewal available)
- timestamps
```

#### `domain_pricing`
Two-tier pricing table (retail + wholesale)
```
- id (primary key)
- domain_extension_id (foreign key)
- period_years — 1, 2, 3, 5, or 10
- tier — 'retail' or 'wholesale'
- price — decimal(10,2)
- setup_fee — decimal(10,2) (optional)
- enabled — boolean
- timestamps
- unique constraint: (extension_id, period_years, tier)
```

#### `domains` (enhanced)
Added new columns to existing domains table:
```
- registered_at — when domain was registered
- extension — FK to domain_extensions (for quick lookups)
```

## Admin Features

### 1. Domain Management (`/admin/domains`)
**List all registered domains with advanced filtering:**
- Search by domain name
- Filter by owner (name/email search)
- Filter by extension (.com, .co.ke, etc.)
- Filter by status (active, expired, suspended)
- Filter by registrar
- Filter by registration date range
- Filter by expiry date range
- Filter by expiry warning (domains expiring within N days)
- Pagination (20 per page)

**Actions:**
- View domain details
- Edit domain information
- See associated DNS records

### 2. Domain Details (`/admin/domains/{domain}`)
**View complete domain information:**
- Status with color coding
- Registration and expiry dates with "days remaining" calculation
- Nameservers (NS1, NS2)
- DNS records management
- Auto-renewal status
- Owner information
- Notes/history

### 3. Domain Editor (`/admin/domains/{domain}/edit`)
**Update domain information:**
- Change extension, registrar, status
- Set/update registration and expiry dates
- Configure nameservers
- Toggle auto-renewal
- Add administrative notes

### 4. Pricing Manager (`/admin/domains-pricing`)
**Complete pricing configuration interface:**

#### View All Extensions
- Table showing every extension with all pricing periods
- For each 1yr/2yr/3yr/5yr/10yr period:
  - Retail price
  - Wholesale price
  - Setup fees (if any)
  - Automatic margin calculation ($X at Y%)
  - Status indicators

#### Edit Pricing Modal
**Side-by-side pricing editor:**
- Retail pricing (Customer Rate)
  - Annual price
  - Optional setup fee
- Wholesale pricing (Reseller Rate)
  - Annual price (typically lower)
  - Optional setup fee
  - Real-time margin display showing $X and Y% margin

#### Add Extension Modal
**Create new domain extensions:**
- Extension name (.com, .co.ke, etc.)
- Description
- Registrar
- Toggle DNS management availability
- Toggle auto-renewal availability

## Models

### DomainExtension
```php
// Get retail pricing for a specific period
$ext->getRetailPricing(1) // Returns DomainPricing or null

// Get wholesale pricing
$ext->getWholesalePricing(1) // Returns DomainPricing or null

// Get all pricing for a period (both tiers keyed by tier)
$ext->getPricingForPeriod(1) // Returns Collection keyed by 'retail'/'wholesale'

// Relations
$ext->pricing() // HasMany DomainPricing
$ext->domains() // HasMany Domain
```

### DomainPricing
```php
// Attributes
$pricing->price        // decimal(10,2)
$pricing->setup_fee    // decimal(10,2)
$pricing->period_years // 1|2|3|5|10
$pricing->tier         // 'retail' or 'wholesale'

// Relations
$pricing->domainExtension() // BelongsTo
```

### Domain (Enhanced)
```php
// New attributes
$domain->extension    // ".com" (loaded via relationship)
$domain->registered_at // datetime

// Helper methods
$domain->isActive()        // bool
$domain->isExpired()       // bool
$domain->daysUntilExpiry() // int

// Relations
$domain->domainExtension() // BelongsTo DomainExtension
```

## Routes

### Admin Routes
```
GET    /admin/domains                 — List domains (with filters)
POST   /admin/domains                 — Create domain
GET    /admin/domains/create          — Create form
GET    /admin/domains/{domain}        — View detail
GET    /admin/domains/{domain}/edit   — Edit form
PATCH  /admin/domains/{domain}        — Update domain
DELETE /admin/domains/{domain}        — Delete domain

GET    /admin/domains-pricing         — Pricing manager
POST   /admin/domains-pricing         — Save pricing (both retail + wholesale)
POST   /admin/domain-extensions       — Create extension
```

### Customer Routes
```
GET    /my/domains/available          — Browse available extensions & pricing
```

## Controller Methods

### Admin\DomainController

#### index(Request $request)
Returns paginated domain list with advanced filtering support.

#### pricing(Request $request)
Returns pricing manager page with all extensions and their pricing.

#### storePricing(Request $request)
Saves both retail AND wholesale pricing for a period in one request.
- Expects: `domain_extension_id`, `period_years`, `retail_price`, `retail_setup_fee`, `wholesale_price`, `wholesale_setup_fee`
- Creates/updates both pricing records atomically

#### storeExtension(Request $request)
Creates new domain extension.
- Expects: `extension`, `registrar`, `dns_management`, `auto_renewal`

## Sample Data (Seeded)

5 domain extensions with full pricing:

### .com (Commercial)
- Retail: 1yr=$9.99, 2yr=$18.99, 3yr=$27.99, 5yr=$45.99, 10yr=$89.99
- Wholesale: 1yr=$5.99, 2yr=$11.99, 3yr=$17.99, 5yr=$29.99, 10yr=$59.99
- **Margin: $4-30 per sale (66.8% on annual)**

### .co.ke (Kenya Country Code)
- Retail: 1yr=$12.99, 2yr=$24.99, 3yr=$36.99, 5yr=$60.99, 10yr=$119.99
- Wholesale: 1yr=$7.99, 2yr=$15.99, 3yr=$23.99, 5yr=$39.99, 10yr=$79.99
- **Margin: $5-40 per sale (62.6% on annual)**

### .org (Organization)
- Retail: 1yr=$8.99, 2yr=$16.99, 3yr=$24.99, 5yr=$40.99, 10yr=$79.99
- Wholesale: 1yr=$5.49, 2yr=$10.99, 3yr=$16.49, 5yr=$26.99, 10yr=$52.99
- **Margin: $3.50-27 per sale (63.8% on annual)**

### .net (Network)
- Retail: 1yr=$9.49, 2yr=$17.99, 3yr=$26.99, 5yr=$44.99, 10yr=$87.99
- Wholesale: 1yr=$5.99, 2yr=$11.49, 3yr=$17.49, 5yr=$28.99, 10yr=$57.99
- **Margin: $3.50-30 per sale (58.4% on annual)**

### .io (Tech/Startup)
- Retail: 1yr=$34.99, 2yr=$64.99, 3yr=$94.99, 5yr=$154.99, 10yr=$299.99
- Wholesale: 1yr=$19.99, 2yr=$37.99, 3yr=$55.99, 5yr=$89.99, 10yr=$179.99
- **Margin: $15-120 per sale (75% on annual)**

## Usage Flow

### Admin Workflow

1. **Navigate to Catalog > Domains & Pricing**
2. **View pricing page** (initially empty or shows existing extensions)
3. **Add Extension** (if new)
   - Click "Add Extension" button
   - Fill extension, description, registrar
   - Select features (DNS, auto-renewal)
   - Submit
4. **Configure Pricing**
   - Find extension in list
   - Click "Edit" on desired period (1yr, 2yr, 3yr, 5yr, 10yr)
   - Enter retail price (what customers pay)
   - Enter wholesale price (what resellers pay)
   - System shows margin automatically
   - Save
5. **Manage Domains**
   - Go to "Domains & Pricing" link
   - Use filters to find specific domains
   - Click on domain to view/edit details
   - Update expiry dates, nameservers, notes

### Reseller Access Flow

1. **Reseller can see wholesale pricing** (via future checkout integration)
2. **Add domains to customers at wholesale rate**
3. **Mark up and resell at their own pricing**

### Customer Access Flow

1. **Navigate to "Available Domains"** (under My Account → Domains)
2. **Browse all enabled extensions** with retail pricing
3. **See all 5 billing periods** with per-year costs
4. **See features available** (DNS, auto-renewal)
5. **Register domain** (checkout integration)

## Frontend Features

### Admin Pricing Manager
- **Responsive grid layout** showing all extensions
- **Alpine.js powered modals** for add/edit
- **Real-time margin calculation** showing $X and Y%
- **Pre-filled pricing** when editing (no data loss)
- **Color-coded status** (Enabled/Disabled badges)
- **Registrar tracking** per extension
- **Success messages** after save

### Customer Domain Browser
- **Card-based layout** showing all extensions
- **3-column responsive grid** (1 on mobile, 3 on desktop)
- **Pricing for all 5 periods** in grid
- **Feature checkmarks** (DNS, auto-renewal)
- **"Save X%" messaging** for multi-year plans
- **Register button** per extension (integration point)

## Data Integrity

### Unique Constraints
```
domain_pricing: (domain_extension_id, period_years, tier)
```
Prevents duplicate pricing entries for same period/tier combination.

### Validation
- Extension names must be unique
- Prices must be positive decimals
- Period years must be in [1, 2, 3, 5, 10]
- Tiers must be 'retail' or 'wholesale'

### Cascading Deletes
- Deleting extension cascades to all its pricing records
- Deleting domain doesn't affect extensions/pricing

## Testing Checklist

- [x] Create domain extension via modal
- [x] Edit pricing (retail + wholesale simultaneously)
- [x] View all extensions with all pricing periods
- [x] Margin calculation displays correctly
- [x] Margin % calculated properly
- [x] Filter domains by all criteria
- [x] Edit domain expiry dates
- [x] Customer view shows available domains
- [x] Alpine.js modals open/close properly
- [x] Form validation prevents invalid data
- [x] Seeded data populates correctly

## Future Enhancements

1. **Domain Search Integration** — Check availability via registrar API
2. **Bulk Pricing Management** — Update multiple periods at once
3. **Pricing History** — Track price changes over time
4. **Discount Rules** — Volume discounts for resellers
5. **Auto-renewal Automation** — Cron job to renew before expiry
6. **Usage Analytics** — Most popular domains/periods
7. **Registrar Integration** — Auto-provision domains
8. **Customer Domain Dashboard** — Manage owned domains
9. **Renewal Automation** — Invoice generation for renewals
10. **WHOIS Management** — Privacy/registration details

## Performance Considerations

- **Indexes on frequently filtered columns:**
  - domains: (user_id, status, expires_at, extension)
  - domain_pricing: (domain_extension_id, tier)

- **Eager loading** in controllers prevents N+1 queries
- **Pagination** for domain lists (20 per page)
- **Pricing lookup** uses model methods for consistency

## Security Notes

- Extension management restricted to admin role
- Pricing only editable by admin
- Customer view shows only enabled extensions
- Domain operations protected by resource policies
- All input validated and sanitized
