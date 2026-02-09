/**
 * Logs Page Object
 *
 * Represents the SAP WC Sync logs/monitoring page in WP Admin.
 * URL: /wp-admin/admin.php?page=sap-wc-sync-logs
 */
class LogsPage {
    /**
     * @param {import('puppeteer').Page} page
     * @param {object} config - From __CONFIG__ global
     */
    constructor(page, config) {
        this.page = page;
        this.config = config;
        this.url = `${config.wpUrl}${config.wpAdminPath}/admin.php?page=sap-wc-sync-logs`;
    }

    async navigate() {
        await this.page.goto(this.url, { waitUntil: 'networkidle2' });
    }

    // ─── Log Table ─────────────────────────────────────────────────────────────

    async getLogRows() {
        return this.page.$$eval('.sap-wc-logs-table tbody tr', rows =>
            rows.map(row => {
                const cells = row.querySelectorAll('td');
                return {
                    id: cells[0]?.textContent?.trim() || '',
                    entityType: cells[1]?.textContent?.trim() || '',
                    entityId: cells[2]?.textContent?.trim() || '',
                    action: cells[3]?.textContent?.trim() || '',
                    status: cells[4]?.textContent?.trim() || '',
                    message: cells[5]?.textContent?.trim() || '',
                    timestamp: cells[6]?.textContent?.trim() || '',
                };
            })
        );
    }

    async getLogCount() {
        const rows = await this.getLogRows();
        return rows.length;
    }

    // ─── Dead Letter Section ───────────────────────────────────────────────────

    async getDeadLetterRows() {
        return this.page.$$eval('.sap-wc-dead-letter-table tbody tr', rows =>
            rows.map(row => {
                const cells = row.querySelectorAll('td');
                return {
                    id: cells[0]?.textContent?.trim() || '',
                    eventType: cells[1]?.textContent?.trim() || '',
                    attempts: cells[2]?.textContent?.trim() || '',
                    lastError: cells[3]?.textContent?.trim() || '',
                    createdAt: cells[4]?.textContent?.trim() || '',
                };
            })
        );
    }

    async getDeadLetterCount() {
        const rows = await this.getDeadLetterRows();
        return rows.length;
    }

    async clickRetryDeadLetter(deadLetterId) {
        await this.page.click(`.sap-wc-retry-dead-letter[data-dead-letter-id="${deadLetterId}"]`);
    }

    // ─── Filters ───────────────────────────────────────────────────────────────

    async filterByEntityType(type) {
        await this.page.select('.sap-wc-filter-entity', type);
    }

    async filterByStatus(status) {
        await this.page.select('.sap-wc-filter-status', status);
    }

    // ─── Order Retry ───────────────────────────────────────────────────────────

    async clickRetryOrder(orderId) {
        await this.page.click(`.sap-wc-retry-order[data-order-id="${orderId}"]`);
    }

    // ─── Page State ────────────────────────────────────────────────────────────

    async isLoaded() {
        try {
            await this.page.waitForSelector('.sap-wc-logs-table', { timeout: 5000 });
            return true;
        } catch {
            return false;
        }
    }
}

module.exports = LogsPage;
