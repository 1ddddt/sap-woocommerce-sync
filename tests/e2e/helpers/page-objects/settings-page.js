/**
 * Settings Page Object
 *
 * Represents the SAP WC Sync settings page in WP Admin.
 * URL: /wp-admin/admin.php?page=sap-wc-sync
 */
class SettingsPage {
    /**
     * @param {import('puppeteer').Page} page
     * @param {object} config - From __CONFIG__ global
     */
    constructor(page, config) {
        this.page = page;
        this.config = config;
        this.url = `${config.wpUrl}${config.wpAdminPath}/admin.php?page=sap-wc-sync`;
    }

    async navigate() {
        await this.page.goto(this.url, { waitUntil: 'networkidle2' });
    }

    // ─── Connection Settings ───────────────────────────────────────────────────

    async setBaseUrl(value) {
        await this.page.evaluate(() => document.getElementById('sap_wc_base_url').value = '');
        await this.page.type('#sap_wc_base_url', value);
    }

    async setCompanyDb(value) {
        await this.page.evaluate(() => document.getElementById('sap_wc_company_db').value = '');
        await this.page.type('#sap_wc_company_db', value);
    }

    async setUsername(value) {
        await this.page.evaluate(() => document.getElementById('sap_wc_username').value = '');
        await this.page.type('#sap_wc_username', value);
    }

    async setPassword(value) {
        await this.page.evaluate(() => document.getElementById('sap_wc_password').value = '');
        await this.page.type('#sap_wc_password', value);
    }

    async setWarehouse(value) {
        await this.page.evaluate(() => document.getElementById('sap_wc_default_warehouse').value = '');
        await this.page.type('#sap_wc_default_warehouse', value);
    }

    // ─── Webhook Settings ──────────────────────────────────────────────────────

    async getWebhookUrl() {
        return this.page.$eval('#sap_wc_webhook_url', el => el.value);
    }

    async setWebhookSecret(value) {
        await this.page.evaluate(() => document.getElementById('sap_wc_webhook_secret').value = '');
        await this.page.type('#sap_wc_webhook_secret', value);
    }

    async toggleWebhooks(enable) {
        const checkbox = await this.page.$('#sap_wc_enable_webhooks');
        const isChecked = await this.page.$eval('#sap_wc_enable_webhooks', el => el.checked);
        if ((enable && !isChecked) || (!enable && isChecked)) {
            await checkbox.click();
        }
    }

    async toggleOrderSync(enable) {
        const isChecked = await this.page.$eval('#sap_wc_enable_order_sync', el => el.checked);
        if ((enable && !isChecked) || (!enable && isChecked)) {
            await this.page.click('#sap_wc_enable_order_sync');
        }
    }

    async toggleInventorySync(enable) {
        const isChecked = await this.page.$eval('#sap_wc_enable_inventory_sync', el => el.checked);
        if ((enable && !isChecked) || (!enable && isChecked)) {
            await this.page.click('#sap_wc_enable_inventory_sync');
        }
    }

    // ─── Actions ───────────────────────────────────────────────────────────────

    async clickTestConnection() {
        await this.page.click('#sap-wc-test-connection');
    }

    async clickSyncInventory() {
        await this.page.click('#sap-wc-sync-inventory');
    }

    async clickSyncProducts() {
        await this.page.click('#sap-wc-sync-products');
    }

    async clickSaveSettings() {
        await this.page.click('#submit');
        await this.page.waitForNavigation({ waitUntil: 'networkidle2' });
    }

    // ─── Status Cards ──────────────────────────────────────────────────────────

    async getActionResult() {
        try {
            await this.page.waitForSelector('#sap-wc-action-result', { visible: true, timeout: 5000 });
            return {
                text: await this.page.$eval('#sap-wc-action-result', el => el.textContent),
                classes: await this.page.$eval('#sap-wc-action-result', el => el.className),
            };
        } catch {
            return { text: '', classes: '' };
        }
    }

    async waitForActionResult(type, timeout = 30000) {
        await this.page.waitForSelector(`#sap-wc-action-result.${type}`, {
            visible: true,
            timeout,
        });
        return this.getActionResult();
    }

    async getStatusCards() {
        return this.page.$$eval('.sap-wc-status-card', cards =>
            cards.map(card => ({
                label: card.querySelector('.sap-wc-status-label')?.textContent?.trim() || '',
                value: card.querySelector('.sap-wc-status-value')?.textContent?.trim() || '',
            }))
        );
    }

    // ─── Page State ────────────────────────────────────────────────────────────

    async isLoaded() {
        try {
            await this.page.waitForSelector('#sap_wc_base_url', { timeout: 5000 });
            return true;
        } catch {
            return false;
        }
    }

    async getFormValues() {
        return this.page.evaluate(() => ({
            baseUrl: document.getElementById('sap_wc_base_url')?.value || '',
            companyDb: document.getElementById('sap_wc_company_db')?.value || '',
            username: document.getElementById('sap_wc_username')?.value || '',
            warehouse: document.getElementById('sap_wc_default_warehouse')?.value || '',
        }));
    }
}

module.exports = SettingsPage;
