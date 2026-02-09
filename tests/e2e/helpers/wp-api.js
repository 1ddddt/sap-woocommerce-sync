/**
 * WordPress REST API Helper
 *
 * Provides authenticated access to WordPress REST endpoints
 * using Application Passwords for test setup and verification.
 */
const fetch = require('node-fetch');

class WpApi {
    /**
     * @param {object} config - From __CONFIG__ global
     */
    constructor(config) {
        this.baseUrl = config.wpUrl;
        this.auth = Buffer.from(`${config.wpAppUser}:${config.wpAppPass}`).toString('base64');
        this.timeout = config.navigationTimeout || 30000;
    }

    /**
     * Make authenticated REST API request.
     */
    async request(endpoint, options = {}) {
        const url = `${this.baseUrl}/wp-json${endpoint}`;
        const res = await fetch(url, {
            timeout: this.timeout,
            ...options,
            headers: {
                'Authorization': `Basic ${this.auth}`,
                'Content-Type': 'application/json',
                ...(options.headers || {}),
            },
        });
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); } catch { data = text; }
        return { status: res.status, data, ok: res.ok };
    }

    async get(endpoint) {
        return this.request(endpoint, { method: 'GET' });
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
        return this.request(endpoint, { method: 'DELETE' });
    }

    // ─── WordPress Options ─────────────────────────────────────────────────────

    /**
     * Update a WordPress option via the settings endpoint.
     * Note: Requires custom REST endpoint or WP-CLI.
     * Falls back to AJAX approach.
     */
    async updateOption(key, value) {
        return this.post('/sap-wc/v1/test/set-option', { key, value });
    }

    // ─── Plugin Health ─────────────────────────────────────────────────────────

    async getPluginHealth() {
        const res = await fetch(`${this.baseUrl}/wp-json/sap-wc/v1/health`, {
            timeout: this.timeout,
        });
        return res.json();
    }

    // ─── Plugin Status ─────────────────────────────────────────────────────────

    async getPlugins() {
        return this.get('/wp/v2/plugins');
    }

    async activatePlugin(plugin) {
        return this.put(`/wp/v2/plugins/${plugin}`, { status: 'active' });
    }

    async deactivatePlugin(plugin) {
        return this.put(`/wp/v2/plugins/${plugin}`, { status: 'inactive' });
    }
}

module.exports = WpApi;
