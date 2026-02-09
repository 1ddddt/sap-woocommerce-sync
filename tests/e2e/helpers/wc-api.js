/**
 * WooCommerce REST API Helper
 *
 * Provides access to WooCommerce v3 REST API endpoints
 * using consumer key/secret authentication.
 */
const fetch = require('node-fetch');

class WcApi {
    /**
     * @param {object} config - From __CONFIG__ global
     */
    constructor(config) {
        this.baseUrl = `${config.wpUrl}/wp-json/wc/v3`;
        this.key = config.wcKey;
        this.secret = config.wcSecret;
        this.timeout = config.navigationTimeout || 30000;
    }

    /**
     * Build authenticated URL with consumer key/secret query params.
     */
    buildUrl(endpoint, params = {}) {
        const url = new URL(`${this.baseUrl}${endpoint}`);
        url.searchParams.set('consumer_key', this.key);
        url.searchParams.set('consumer_secret', this.secret);
        Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
        return url.toString();
    }

    async request(endpoint, options = {}, params = {}) {
        const url = this.buildUrl(endpoint, params);
        const res = await fetch(url, {
            timeout: this.timeout,
            ...options,
            headers: {
                'Content-Type': 'application/json',
                ...(options.headers || {}),
            },
        });
        const data = await res.json();
        return { status: res.status, data, ok: res.ok };
    }

    async get(endpoint, params = {}) {
        return this.request(endpoint, { method: 'GET' }, params);
    }

    async post(endpoint, body) {
        return this.request(endpoint, {
            method: 'POST',
            body: JSON.stringify(body),
        });
    }

    async put(endpoint, body) {
        return this.request(endpoint, {
            method: 'PUT',
            body: JSON.stringify(body),
        });
    }

    async delete(endpoint) {
        return this.request(endpoint, { method: 'DELETE' }, { force: 'true' });
    }

    // ─── Products ──────────────────────────────────────────────────────────────

    async createProduct(data) {
        return this.post('/products', data);
    }

    async getProduct(id) {
        return this.get(`/products/${id}`);
    }

    async updateProduct(id, data) {
        return this.put(`/products/${id}`, data);
    }

    async deleteProduct(id) {
        return this.delete(`/products/${id}`);
    }

    async listProducts(params = {}) {
        return this.get('/products', params);
    }

    // ─── Orders ────────────────────────────────────────────────────────────────

    async createOrder(data) {
        return this.post('/orders', data);
    }

    async getOrder(id) {
        return this.get(`/orders/${id}`);
    }

    async updateOrder(id, data) {
        return this.put(`/orders/${id}`, data);
    }

    async deleteOrder(id) {
        return this.delete(`/orders/${id}`);
    }

    async listOrders(params = {}) {
        return this.get('/orders', params);
    }

    // ─── Order Refunds ─────────────────────────────────────────────────────────

    async createRefund(orderId, data) {
        return this.post(`/orders/${orderId}/refunds`, data);
    }

    async listRefunds(orderId) {
        return this.get(`/orders/${orderId}/refunds`);
    }

    // ─── Customers ─────────────────────────────────────────────────────────────

    async createCustomer(data) {
        return this.post('/customers', data);
    }

    async getCustomer(id) {
        return this.get(`/customers/${id}`);
    }

    // ─── System ────────────────────────────────────────────────────────────────

    async getSystemStatus() {
        return this.get('/system_status');
    }

    // ─── Convenience Methods ───────────────────────────────────────────────────

    /**
     * Create a simple product with defaults.
     */
    async createSimpleProduct(overrides = {}) {
        return this.createProduct({
            name: `Test Product ${Date.now()}`,
            type: 'simple',
            regular_price: '29.99',
            manage_stock: true,
            stock_quantity: 100,
            status: 'publish',
            ...overrides,
        });
    }

    /**
     * Create a COD order with a single product.
     */
    async createCodOrder(productId, overrides = {}) {
        return this.createOrder({
            payment_method: 'cod',
            payment_method_title: 'Cash on delivery',
            set_paid: false,
            status: 'processing',
            billing: {
                first_name: 'Test',
                last_name: 'Customer',
                address_1: '123 Test St',
                city: 'Test City',
                state: 'TS',
                postcode: '12345',
                country: 'US',
                email: 'test@example.com',
                phone: '1234567890',
            },
            shipping: {
                first_name: 'Test',
                last_name: 'Customer',
                address_1: '123 Test St',
                city: 'Test City',
                state: 'TS',
                postcode: '12345',
                country: 'US',
            },
            line_items: [
                { product_id: productId, quantity: 1 },
            ],
            ...overrides,
        });
    }

    /**
     * Create a prepaid order (non-COD payment).
     */
    async createPrepaidOrder(productId, overrides = {}) {
        return this.createCodOrder(productId, {
            payment_method: 'bacs',
            payment_method_title: 'Direct bank transfer',
            set_paid: true,
            ...overrides,
        });
    }
}

module.exports = WcApi;
