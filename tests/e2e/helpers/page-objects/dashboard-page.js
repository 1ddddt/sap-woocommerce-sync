/**
 * Dashboard Page Object
 *
 * Represents the WordPress Dashboard with the SAP WC Sync widget.
 * URL: /wp-admin/index.php
 */
class DashboardPage {
    /**
     * @param {import('puppeteer').Page} page
     * @param {object} config - From __CONFIG__ global
     */
    constructor(page, config) {
        this.page = page;
        this.config = config;
        this.url = `${config.wpUrl}${config.wpAdminPath}/index.php`;
    }

    async navigate() {
        await this.page.goto(this.url, { waitUntil: 'networkidle2' });
    }

    // ─── Widget ────────────────────────────────────────────────────────────────

    async isWidgetVisible() {
        try {
            await this.page.waitForSelector('#sap-wc-sync-dashboard', { timeout: 5000 });
            return true;
        } catch {
            return false;
        }
    }

    async getWidgetMetrics() {
        return this.page.evaluate(() => {
            const widget = document.getElementById('sap-wc-sync-dashboard');
            if (!widget) return null;

            const metrics = {};
            widget.querySelectorAll('.sap-wc-metric').forEach(el => {
                const label = el.querySelector('.sap-wc-metric-label')?.textContent?.trim() || '';
                const value = el.querySelector('.sap-wc-metric-value')?.textContent?.trim() || '';
                metrics[label] = value;
            });
            return metrics;
        });
    }

    async getWidgetStatus() {
        try {
            return await this.page.$eval(
                '#sap-wc-sync-dashboard .sap-wc-health-status',
                el => el.textContent.trim()
            );
        } catch {
            return null;
        }
    }

    // ─── Page State ────────────────────────────────────────────────────────────

    async isLoaded() {
        try {
            await this.page.waitForSelector('#dashboard-widgets', { timeout: 5000 });
            return true;
        } catch {
            return false;
        }
    }
}

module.exports = DashboardPage;
