/**
 * Products Page Object
 *
 * Represents the SAP WC Sync products monitoring page in WP Admin.
 * URL: /wp-admin/admin.php?page=sap-wc-sync-products
 */
class ProductsPage {
    /**
     * @param {import('puppeteer').Page} page
     * @param {object} config - From __CONFIG__ global
     */
    constructor(page, config) {
        this.page = page;
        this.config = config;
        this.url = `${config.wpUrl}${config.wpAdminPath}/admin.php?page=sap-wc-sync-products`;
    }

    async navigate() {
        await this.page.goto(this.url, { waitUntil: 'networkidle2' });
    }

    // ─── Product Table ─────────────────────────────────────────────────────────

    async getProductRows() {
        return this.page.$$eval('.sap-wc-products-table tbody tr', rows =>
            rows.map(row => ({
                productName: row.querySelector('td:nth-child(1)')?.textContent?.trim() || '',
                sku: row.querySelector('td:nth-child(2)')?.textContent?.trim() || '',
                itemCode: row.querySelector('td:nth-child(3)')?.textContent?.trim() || '',
                stock: row.querySelector('td:nth-child(4)')?.textContent?.trim() || '',
                status: row.querySelector('td:nth-child(5)')?.textContent?.trim() || '',
                lastSync: row.querySelector('td:nth-child(6)')?.textContent?.trim() || '',
            }))
        );
    }

    async getProductCount() {
        const rows = await this.getProductRows();
        return rows.length;
    }

    async getProductByItemCode(itemCode) {
        const rows = await this.getProductRows();
        return rows.find(r => r.itemCode === itemCode) || null;
    }

    // ─── Actions ───────────────────────────────────────────────────────────────

    async clickSyncButton(productId) {
        await this.page.click(`.sap-wc-sync-single[data-product-id="${productId}"]`);
    }

    async clickManualMapButton(productId) {
        await this.page.click(`.sap-wc-manual-map[data-product-id="${productId}"]`);
    }

    // ─── Manual Map Modal ──────────────────────────────────────────────────────

    async isModalOpen() {
        try {
            await this.page.waitForSelector('.sap-wc-modal-overlay', { visible: true, timeout: 3000 });
            return true;
        } catch {
            return false;
        }
    }

    async setItemCode(value) {
        await this.page.type('#sap-wc-map-itemcode', value);
    }

    async submitMap() {
        await this.page.click('#sap-wc-map-submit');
    }

    async cancelMap() {
        await this.page.click('#sap-wc-map-cancel');
    }

    async getMapResult() {
        try {
            await this.page.waitForSelector('#sap-wc-map-result p', { timeout: 10000 });
            return this.page.$eval('#sap-wc-map-result p', el => ({
                text: el.textContent,
                color: el.style.color,
            }));
        } catch {
            return { text: '', color: '' };
        }
    }

    // ─── Filters ───────────────────────────────────────────────────────────────

    async filterByStatus(status) {
        await this.page.select('.sap-wc-filter-status', status);
    }

    async search(query) {
        await this.page.evaluate(() => {
            const input = document.querySelector('.sap-wc-search-input');
            if (input) input.value = '';
        });
        await this.page.type('.sap-wc-search-input', query);
    }

    // ─── Page State ────────────────────────────────────────────────────────────

    async isLoaded() {
        try {
            await this.page.waitForSelector('.sap-wc-products-table', { timeout: 5000 });
            return true;
        } catch {
            return false;
        }
    }

    async getNoResultsMessage() {
        try {
            return await this.page.$eval('.sap-wc-no-results', el => el.textContent.trim());
        } catch {
            return null;
        }
    }
}

module.exports = ProductsPage;
