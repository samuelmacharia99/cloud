<script>
function storefrontPanel() {
    return {
        query: '',
        searching: false,
        searched: false,
        results: [],
        searchError: '',
        ordering: false,
        orderError: '',
        searchUrl: @js($searchUrl),
        cartUrl: @js($cartUrl),

        async searchDomains() {
            if (this.query.trim().length < 2 || this.searching) return;
            this.searching = true;
            this.searchError = '';
            this.orderError = '';
            try {
                const url = new URL(this.searchUrl, window.location.origin);
                url.searchParams.set('q', this.query.trim());
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
            await this.addToCart([{
                type: 'domain',
                full_domain: row.full_domain,
                years: row.period_years || 1,
            }]);
        },

        async orderHosting(productId) {
            if (this.ordering) return;
            await this.addToCart([{
                type: 'reseller_product',
                id: productId,
                reseller_product_id: productId,
                billing_cycle: 'monthly',
            }]);
        },

        async addToCart(items) {
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
                    return;
                }
                window.location.href = data.cart_url || data.checkout_url;
            } catch (e) {
                this.orderError = 'Could not add item to cart. Please try again.';
            } finally {
                this.ordering = false;
            }
        },
    };
}
</script>
