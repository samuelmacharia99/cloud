<script>
function storefrontPanel() {
    return {
        query: '',
        years: 1,
        domainMode: 'register',
        transferEpp: '',
        transferRegistrar: '',
        searching: false,
        searched: false,
        results: [],
        searchError: '',
        ordering: false,
        orderError: '',
        billingCycle: 'monthly',
        upsellOpen: false,
        upsellDomain: null,
        cartPageUrl: @js($cartPageUrl ?? route('reseller.public.store.cart.show')),
        searchUrl: @js($searchUrl),
        cartUrl: @js($cartUrl),
        hostingOptions: @js(collect($serviceGroups ?? [])->flatMap(fn ($g) => $g['products'] ?? [])->map(fn ($p) => [
            'id' => $p['id'],
            'name' => $p['name'],
            'type' => $p['type'] ?? '',
            'monthly_price' => $p['monthly_price'] ?? 0,
            'yearly_price' => $p['yearly_price'] ?? null,
            'order_path' => $p['order_path'] ?? null,
        ])->values()->all()),

        displayPrice(product) {
            if (this.billingCycle === 'annual') {
                const yearly = product.yearly_price != null
                    ? Number(product.yearly_price)
                    : Number(product.monthly_price || 0) * 12;
                return yearly;
            }
            return Number(product.monthly_price || 0);
        },

        priceLabel() {
            return this.billingCycle === 'annual' ? '/yr' : '/mo';
        },

        annualSavingsPercent(product) {
            const monthly = Number(product.monthly_price || 0);
            if (monthly <= 0) return null;
            const yearly = product.yearly_price != null
                ? Number(product.yearly_price)
                : null;
            if (yearly == null || yearly <= 0) return null;
            const full = monthly * 12;
            if (yearly >= full) return null;
            return Math.round(((full - yearly) / full) * 100);
        },

        async searchDomains() {
            if (this.query.trim().length < 2 || this.searching) return;
            this.searching = true;
            this.searchError = '';
            this.orderError = '';
            this.upsellOpen = false;
            try {
                const url = new URL(this.searchUrl, window.location.origin);
                url.searchParams.set('q', this.query.trim());
                url.searchParams.set('years', String(this.years || 1));
                const response = await fetch(url.toString(), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await response.json();
                if (!response.ok) {
                    this.searchError = data.message || 'Domain search failed.';
                    this.results = [];
                } else {
                    this.results = data.results || [];
                }
                this.searched = true;
                document.getElementById('domains')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } catch (e) {
                this.searchError = 'Domain search failed. Please try again.';
            } finally {
                this.searching = false;
            }
        },

        async orderDomain(row) {
            if (!row?.available || this.ordering) return;
            const ok = await this.addToCart([{
                type: 'domain',
                full_domain: row.full_domain,
                years: row.period_years || this.years || 1,
            }], { stay: true });
            if (ok) {
                this.upsellDomain = row.full_domain;
                this.upsellOpen = this.hostingOptions.length > 0;
                if (!this.upsellOpen) {
                    window.location.href = this.cartPageUrl;
                }
            }
        },

        async orderTransfer() {
            if (this.ordering) return;
            const domain = this.query.trim().toLowerCase().replace(/^https?:\/\//, '').replace(/^www\./, '');
            if (domain.length < 4) {
                this.orderError = 'Enter the domain you want to transfer.';
                return;
            }
            if (this.transferEpp.trim().length < 5) {
                this.orderError = 'Enter the EPP / auth code from your current registrar.';
                return;
            }
            if (this.transferRegistrar.trim().length < 2) {
                this.orderError = 'Enter your current registrar name.';
                return;
            }
            await this.addToCart([{
                type: 'domain_transfer',
                full_domain: domain,
                epp_code: this.transferEpp.trim(),
                old_registrar: this.transferRegistrar.trim(),
            }]);
        },

        async orderHosting(productId, cycle) {
            if (this.ordering) return;
            await this.addToCart([{
                type: 'reseller_product',
                id: productId,
                reseller_product_id: productId,
                billing_cycle: cycle || this.billingCycle || 'monthly',
            }]);
        },

        async addHostingUpsell(productId) {
            if (this.ordering) return;
            const ok = await this.addToCart([{
                type: 'reseller_product',
                id: productId,
                reseller_product_id: productId,
                billing_cycle: this.billingCycle || 'monthly',
            }], { stay: true });
            if (ok) {
                window.location.href = this.cartPageUrl;
            }
        },

        skipUpsell() {
            window.location.href = this.cartPageUrl;
        },

        async addToCart(items, options = {}) {
            this.ordering = true;
            this.orderError = '';
            try {
                const response = await fetch(this.cartUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ items }),
                });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    this.orderError = data.message || 'Could not add item to cart.';
                    return false;
                }
                if (!options.stay) {
                    window.location.href = data.cart_url || data.checkout_url || this.cartPageUrl;
                }
                return true;
            } catch (e) {
                this.orderError = 'Could not add item to cart. Please try again.';
                return false;
            } finally {
                this.ordering = false;
            }
        },
    };
}
</script>
