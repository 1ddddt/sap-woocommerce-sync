/**
 * Spec 02: Settings Page & Configuration
 *
 * Tests the admin settings page: save/load settings, test connection,
 * webhook configuration, and status card display.
 */
const WpLogin = require('../helpers/page-objects/wp-login');
const SettingsPage = require('../helpers/page-objects/settings-page');
const DbHelper = require('../helpers/db-helper');

describe('Settings Page', () => {
    let page, config, db, login, settingsPage;

    beforeAll(async () => {
        config = global.__CONFIG__;
        db = new DbHelper(config);
        await db.connect();
        page = await global.__BROWSER__.newPage();
        page.setDefaultTimeout(config.defaultTimeout);
        page.setDefaultNavigationTimeout(config.navigationTimeout);
        login = new WpLogin(page, config);
        settingsPage = new SettingsPage(page, config);
        await login.login();
    });

    afterAll(async () => {
        await page.close();
        await db.close();
    });

    test('settings page loads correctly', async () => {
        await settingsPage.navigate();
        const loaded = await settingsPage.isLoaded();
        expect(loaded).toBe(true);
    });

    test('can fill in SAP connection settings', async () => {
        await settingsPage.navigate();

        await settingsPage.setBaseUrl(config.sapMockUrl + '/b1s/v1');
        await settingsPage.setCompanyDb('TestDB');
        await settingsPage.setUsername('manager');
        await settingsPage.setWarehouse('WEB-GEN');

        const values = await settingsPage.getFormValues();
        expect(values.baseUrl).toBe(config.sapMockUrl + '/b1s/v1');
        expect(values.companyDb).toBe('TestDB');
        expect(values.username).toBe('manager');
        expect(values.warehouse).toBe('WEB-GEN');
    });

    test('can save settings and they persist', async () => {
        await settingsPage.navigate();

        await settingsPage.setBaseUrl(config.sapMockUrl + '/b1s/v1');
        await settingsPage.setCompanyDb('E2ETestDB');
        await settingsPage.setUsername('e2e_user');
        await settingsPage.setWarehouse('WEB-GEN');

        await settingsPage.clickSaveSettings();

        // Verify from database
        const baseUrl = await db.getOption('sap_wc_base_url');
        const companyDb = await db.getOption('sap_wc_company_db');
        const username = await db.getOption('sap_wc_username');

        expect(baseUrl).toBe(config.sapMockUrl + '/b1s/v1');
        expect(companyDb).toBe('E2ETestDB');
        expect(username).toBe('e2e_user');
    });

    test('saved values are loaded on page refresh', async () => {
        await settingsPage.navigate();

        const values = await settingsPage.getFormValues();
        expect(values.baseUrl).toBe(config.sapMockUrl + '/b1s/v1');
        expect(values.companyDb).toBe('E2ETestDB');
        expect(values.username).toBe('e2e_user');
    });

    test('test connection button communicates with SAP', async () => {
        await settingsPage.navigate();

        // Configure mock SAP URL
        await settingsPage.setBaseUrl(config.sapMockUrl + '/b1s/v1');
        await settingsPage.setCompanyDb('TestDB');
        await settingsPage.setUsername('manager');
        await settingsPage.setPassword('test123');

        await settingsPage.clickTestConnection();

        // Wait for AJAX result
        const result = await settingsPage.waitForActionResult('success', 30000);
        expect(result.text).toBeTruthy();
        expect(result.classes).toContain('success');
    });

    test('test connection shows error for bad credentials', async () => {
        await settingsPage.navigate();

        await settingsPage.setBaseUrl('http://nonexistent-server:9999');
        await settingsPage.setCompanyDb('BadDB');
        await settingsPage.setUsername('wrong');
        await settingsPage.setPassword('wrong');

        await settingsPage.clickTestConnection();

        const result = await settingsPage.waitForActionResult('error', 30000);
        expect(result.classes).toContain('error');
    });

    test('webhook settings section is visible', async () => {
        await settingsPage.navigate();

        const webhookUrl = await settingsPage.getWebhookUrl();
        expect(webhookUrl).toContain('/wp-json/sap-wc/v1/webhook');
    });

    test('can toggle webhook and order sync settings', async () => {
        await settingsPage.navigate();

        await settingsPage.toggleWebhooks(true);
        await settingsPage.toggleOrderSync(true);
        await settingsPage.toggleInventorySync(true);
        await settingsPage.clickSaveSettings();

        // Verify from database
        const webhooks = await db.getOption('sap_wc_enable_webhooks');
        const orderSync = await db.getOption('sap_wc_enable_order_sync');
        const inventorySync = await db.getOption('sap_wc_enable_inventory_sync');

        expect(webhooks).toBe('yes');
        expect(orderSync).toBe('yes');
        expect(inventorySync).toBe('yes');
    });

    test('status cards display queue and system metrics', async () => {
        await settingsPage.navigate();

        const cards = await settingsPage.getStatusCards();
        // Should have status cards (exact labels depend on implementation)
        expect(cards.length).toBeGreaterThan(0);
    });

    test('webhook secret can be configured', async () => {
        await settingsPage.navigate();

        await settingsPage.setWebhookSecret(config.webhookSecret);
        await settingsPage.clickSaveSettings();

        const secret = await db.getOption('sap_wc_webhook_secret');
        expect(secret).toBe(config.webhookSecret);
    });
});
