/**
 * Custom Jest Environment with Puppeteer Browser
 *
 * Launches a shared Puppeteer browser instance for all tests in a spec file.
 * Each spec gets a fresh page; each suite shares the browser.
 */
const NodeEnvironment = require('jest-environment-node').TestEnvironment;
const puppeteer = require('puppeteer');
const path = require('path');

require('dotenv').config({ path: path.resolve(__dirname, '../../../.env') });

class PuppeteerEnvironment extends NodeEnvironment {
    async setup() {
        await super.setup();

        const headless = process.env.HEADLESS !== 'false';
        const slowMo = Number(process.env.SLOWMO) || 0;

        // Launch browser
        this.browser = await puppeteer.launch({
            headless: headless ? 'new' : false,
            slowMo,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
            ],
            defaultViewport: {
                width: Number(process.env.VIEWPORT_WIDTH) || 1280,
                height: Number(process.env.VIEWPORT_HEIGHT) || 800,
            },
        });

        // Expose browser and config to test globals
        this.global.__BROWSER__ = this.browser;
        this.global.__CONFIG__ = {
            wpUrl: process.env.WP_URL || 'http://localhost:8080',
            wpAdmin: process.env.WP_ADMIN_USER || 'admin',
            wpPass: process.env.WP_ADMIN_PASS || 'admin',
            wpAdminPath: process.env.WP_ADMIN_PATH || '/wp-admin',
            wcKey: process.env.WC_CONSUMER_KEY || '',
            wcSecret: process.env.WC_CONSUMER_SECRET || '',
            wpAppUser: process.env.WP_APP_USER || 'admin',
            wpAppPass: process.env.WP_APP_PASS || '',
            sapMockUrl: process.env.SAP_MOCK_URL || 'http://localhost:3001',
            sapMockPort: Number(process.env.SAP_MOCK_PORT) || 3001,
            webhookSecret: process.env.WEBHOOK_SECRET || 'test-webhook-secret-for-e2e',
            dbHost: process.env.DB_HOST || '127.0.0.1',
            dbPort: Number(process.env.DB_PORT) || 3306,
            dbName: process.env.DB_NAME || 'wordpress',
            dbUser: process.env.DB_USER || 'root',
            dbPass: process.env.DB_PASS || 'root',
            dbPrefix: process.env.DB_PREFIX || 'wp_',
            navigationTimeout: Number(process.env.NAVIGATION_TIMEOUT) || 30000,
            defaultTimeout: Number(process.env.DEFAULT_TIMEOUT) || 10000,
        };
    }

    async teardown() {
        if (this.browser) {
            await this.browser.close();
        }
        await super.teardown();
    }
}

module.exports = PuppeteerEnvironment;
