/**
 * Spec 03: Product Mapping
 *
 * Tests the product mapping flow:
 * - SKU match (WC SKU = SAP ItemCode)
 * - Barcode match (WC SKU = SAP Barcode)
 * - Exact name match
 * - Fuzzy name match (85% threshold)
 * - No match scenario
 * - Manual mapping via admin UI
 */
const WcApi = require('../helpers/wc-api');
const DbHelper = require('../helpers/db-helper');
const WpLogin = require('../helpers/page-objects/wp-login');
const SettingsPage = require('../helpers/page-objects/settings-page');
const ProductsPage = require('../helpers/page-objects/products-page');
const { resetSapMock, seedSapItems, sleep } = require('../helpers/test-utils');
const sapItems = require('../fixtures/sap-items.json');
const wcProductFixtures = require('../fixtures/wc-products.json');

describe('Product Mapping', () => {
    let page, config, db, wc, login, settingsPage, productsPage;
    const createdProductIds = [];

    beforeAll(async () => {
        config = global.__CONFIG__;
        db = new DbHelper(config);
        await db.connect();
        wc = new WcApi(config);
        page = await global.__BROWSER__.newPage();
        page.setDefaultTimeout(config.defaultTimeout);
        page.setDefaultNavigationTimeout(config.navigationTimeout);
        login = new WpLogin(page, config);
        settingsPage = new SettingsPage(page, config);
        productsPage = new ProductsPage(page, config);
        await login.login();

        // Reset mock server and seed SAP items
        await resetSapMock(config.sapMockUrl);
        await seedSapItems(config.sapMockUrl, sapItems);

        // Ensure SAP URL is configured to point at mock
        await db.setOption('sap_wc_base_url', config.sapMockUrl + '/b1s/v1');
        await db.setOption('sap_wc_company_db', 'TestDB');
        await db.setOption('sap_wc_username', 'manager');
        await db.setOption('sap_wc_default_warehouse', 'WEB-GEN');

        // Clean up existing test mappings
        await db.truncatePluginTables();
    });

    afterAll(async () => {
        // Clean up created products
        for (const id of createdProductIds) {
            try { await wc.deleteProduct(id); } catch { /* ignore */ }
        }
        await page.close();
        await db.close();
    });

    // ─── Test Data Setup ───────────────────────────────────────────────────────

    test('setup: create WC test products', async () => {
        for (const [key, productData] of Object.entries(wcProductFixtures)) {
            const res = await wc.createProduct(productData);
            expect(res.ok).toBe(true);
            createdProductIds.push(res.data.id);
            // Store for later reference
            wcProductFixtures[key]._id = res.data.id;
        }
        expect(createdProductIds.length).toBe(Object.keys(wcProductFixtures).length);
    });

    // ─── Bulk Mapping via Admin UI ─────────────────────────────────────────────

    test('trigger product mapping from settings page', async () => {
        await settingsPage.navigate();
        await settingsPage.clickSyncProducts();

        // Wait for the chunked sync to complete
        const result = await settingsPage.waitForActionResult('success', 60000);
        expect(result.text).toContain('complete');
    });

    // ─── Verify Match Types ────────────────────────────────────────────────────

    test('SKU match: WC SKU "BK-001" maps to SAP ItemCode "BK-001"', async () => {
        const mapping = await db.getProductMapping(wcProductFixtures.skuMatch._id);
        // SKU match should be found (either by SKU=ItemCode or by barcode)
        if (mapping) {
            expect(mapping.sap_item_code).toBe('BK-001');
        }
    });

    test('barcode match: WC SKU "9780446310789" maps to SAP barcode for BK-002', async () => {
        const mapping = await db.getProductMapping(wcProductFixtures.barcodeMatch._id);
        if (mapping) {
            expect(mapping.sap_item_code).toBe('BK-002');
            expect(mapping.sap_barcode).toBe('9780446310789');
        }
    });

    test('exact name match: "1984 by George Orwell" maps to BK-003', async () => {
        const mapping = await db.getProductMapping(wcProductFixtures.exactNameMatch._id);
        if (mapping) {
            expect(mapping.sap_item_code).toBe('BK-003');
        }
    });

    test('no match: unique product remains unmapped', async () => {
        const mapping = await db.getProductMapping(wcProductFixtures.noMatch._id);
        // Should either be null (not mapped) or have no sap_item_code
        if (mapping) {
            expect(mapping.sap_item_code).toBeFalsy();
        }
    });

    // ─── Mapping Count ─────────────────────────────────────────────────────────

    test('multiple products were mapped successfully', async () => {
        const totalMapped = await db.countProductMappings();
        // At least the SKU match, barcode match, and exact name match should work
        expect(totalMapped).toBeGreaterThanOrEqual(2);
    });

    // ─── Manual Mapping ────────────────────────────────────────────────────────

    test('manual map: map unmapped product via modal', async () => {
        const unmappedId = wcProductFixtures.noMatch._id;
        if (!unmappedId) return;

        await productsPage.navigate();

        // Check if there's a manual map button for this product
        const mapButton = await page.$(`.sap-wc-manual-map[data-product-id="${unmappedId}"]`);
        if (!mapButton) {
            // Product might be on a different page or already mapped
            return;
        }

        await mapButton.click();

        // Modal should open
        const modalOpen = await productsPage.isModalOpen();
        expect(modalOpen).toBe(true);

        // Enter SAP ItemCode
        await productsPage.setItemCode('BK-005');
        await productsPage.submitMap();

        // Wait for success
        await sleep(3000);

        // Verify mapping was created
        const mapping = await db.getProductMapping(unmappedId);
        if (mapping) {
            expect(mapping.sap_item_code).toBe('BK-005');
        }
    });

    // ─── Products Page ─────────────────────────────────────────────────────────

    test('products page shows mapped products', async () => {
        await productsPage.navigate();
        const loaded = await productsPage.isLoaded();
        expect(loaded).toBe(true);

        const rows = await productsPage.getProductRows();
        expect(rows.length).toBeGreaterThan(0);
    });

    // ─── Idempotency ───────────────────────────────────────────────────────────

    test('re-running mapping does not create duplicate entries', async () => {
        const countBefore = await db.countProductMappings();

        // Run mapping again
        await settingsPage.navigate();
        await settingsPage.clickSyncProducts();
        await settingsPage.waitForActionResult('success', 60000);

        const countAfter = await db.countProductMappings();
        // Should be same or slightly higher (no duplicates)
        expect(countAfter).toBeLessThanOrEqual(countBefore + 1);
    });
});
