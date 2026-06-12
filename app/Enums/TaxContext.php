<?php

namespace App\Enums;

/**
 * Controls when platform admin VAT/tax settings apply.
 *
 * - PlatformCustomer: direct customers billed by the platform (taxed).
 * - ResellerSubscription: reseller package / subscription fees to the platform (taxed).
 * - ResellerWholesale: reseller B2B (domains, wholesale products, reseller end-customers) — exempt.
 */
enum TaxContext: string
{
    case PlatformCustomer = 'platform_customer';
    case ResellerSubscription = 'reseller_subscription';
    case ResellerWholesale = 'reseller_wholesale';
}
