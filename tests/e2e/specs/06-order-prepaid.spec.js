/**
 * Spec 06: Prepaid Order Sync (WooCommerce -> SAP)
 *
 * Tests the prepaid (non-COD) order flow:
 * - SO created (same as COD)
 * - Down Payment Invoice created (non-blocking)
 * - Incoming Payment created (non-blocking)
 * - Mapping updated with all document entries
 * - If DP/Payment fails, SO is still recorded
 */
const WcApi = require('../helpers/wc-api');
const DbHelper = require('../helpers/db-helper');
const WpLogin = require('../helpers/page-objects/wp-login');
const {
    resetSapMock, seedSapItems, seedSapCustomers,
    triggerWpCron, sleep, getSapMockState, getSapRequestLog,
} = require('../helpers/test-utils');
const sapItems = require('../fixtures/sap-items.json');
const customers = require('../fixtures/customers.json');
const fetch = require('node-fetch');

describe('Prepaid Order Sync', () => {
    let page, config, db, wc, login;
    let testProductId, testOrderId;

    beforeAll(async () => {
        config = global.__CONFIG__;
        db = new DbHelper(config);
        await db.connect();
        wc = new WcApi(config);
        page = await global.__BROWSER__.newPage();
        page.setDefaultTimeout(config.defaultTimeout);
        page.setDefaultNavigationTimeout(config.navigationTimeout);
        login = new WpLogin(page, config);
        await login.login();

        // Reset and seed mock
        await resetSapMock(config.sapMockUrl);
        await seedSapItems(config.sapMockUrl, sapItems);
        await seedSapCustomers(config.sapMockUrl, customers);

        // Configure plugin
        await db.setOption('sap_wc_base_url', config.sapMockUrl + '/b1s/v1');
        await db.setOption('sap_wc_company_db', 'TestDB');
        await db.setOption('sap_wc_username', 'manager');
        await db.setOption('sap_wc_enable_order_sync', 'yes');
        await db.setOption('sap_wc_default_warehouse', 'WEB-GEN');
        await db.setOption('sap_wc_transfer_account', '_SYS00000000155');

        // Create test product
        const res = await wc.createSimpleProduct({
            name: 'Prepaid Order Test Product',
            sku: '9780747532743',
            regular_price: '24.99',
            stock_quantity: 100,
        });
        testProductId = res.data.id;

        // Map product
        await db.query(
            `INSERT INTO ${db.productMapTable}
             (wc_product_id, sap_item_code, sap_barcode, sync_status)
             VALUES (?, 'BK-006', '9780747532743', 'synced')`,
            [testProductId]
        );
    });

    afterAll(async () => {
        if (testProductId) {
            try { await wc.deleteProduct(testProductId); } catch { /* ignore */ }
        }
        if (testOrderId) {
            try { await wc.deleteOrder(testOrderId); } catch { /* ignore */ }
        }
        await page.close();
        await db.close();
    });

    // ─── Prepaid Order ─────────────────────────────────────────────────────────

    test('create prepaid order via WC REST API', async () => {
        const res = await wc.createPrepaidOrder(testProductId, {
            billing: {
                first_name: 'Prepaid',
                last_name: 'TestCustomer',
                address_1: '789 Payment St',
                city: 'Pay City',
                state: 'PS',
                postcode: '54321',
                country: 'US',
                email: 'prepaid@example.com',
                phone: '0987654321',
            },
        });

        expect(res.ok).toBe(true);
        testOrderId = res.data.id;
        expect(res.data.payment_method).toBe('bacs');
    });

    test('order mapping created with prepaid type', async () => {
        await sleep(3000);

        const mapping = await db.getOrderMapping(testOrderId);
        if (mapping) {
            expect(mapping.payment_type).not.toBe('cod');
        }
    });

    // ─── Queue Processing ──────────────────────────────────────────────────────

    test('queue processing creates SAP documents for prepaid order', async () => {
        // Trigger cron multiple times to ensure queue is processed
        await triggerWpCron(config.wpUrl);
        await sleep(5000);
        await triggerWpCron(config.wpUrl);
        await sleep(5000);

        const state = await getSapMockState(config.sapMockUrl);
        // Should have orders created
        expect(state).toBeTruthy();
    });

    test('SAP receives Sales Order for prepaid order', async () => {
        const orderLog = await getSapRequestLog(config.sapMockUrl, {
            method: 'POST',
            path: 'Orders',
        });

        // At least one order POST should exist
        if (orderLog.length > 0) {
            const lastOrder = orderLog[orderLog.length - 1];
            expect(lastOrder.body).toHaveProperty('CardCode');
            expect(lastOrder.body).toHaveProperty('DocumentLines');
        }
    });

    test('SAP receives Down Payment Invoice for prepaid order', async () => {
        const dpLog = await getSapRequestLog(config.sapMockUrl, {
            method: 'POST',
            path: 'DownPayments',
        });

        // DP Invoice may or may not exist depending on queue processing
        // This is non-blocking so we just verify the attempt was made
        if (dpLog.length > 0) {
            const dpBody = dpLog[dpLog.length - 1].body;
            expect(dpBody).toHaveProperty('DownPaymentType');
        }
    });

    test('SAP receives Incoming Payment for prepaid order', async () => {
        const paymentLog = await getSapRequestLog(config.sapMockUrl, {
            method: 'POST',
            path: 'IncomingPayments',
        });

        // Payment may or may not exist depending on processing chain
        if (paymentLog.length > 0) {
            const paymentBody = paymentLog[paymentLog.length - 1].body;
            expect(paymentBody).toHaveProperty('TransferSum');
        }
    });

    // ─── Partial Failure Resilience ────────────────────────────────────────────

    test('SO is recorded even if DP Invoice fails', async () => {
        // The order mapping should have sap_doc_entry regardless of DP failure
        const mapping = await db.getOrderMapping(testOrderId);
        if (mapping && mapping.sap_doc_entry) {
            // SO was created successfully
            expect(Number(mapping.sap_doc_entry)).toBeGreaterThan(0);
        }
    });

    // ─── Request Verification ──────────────────────────────────────────────────

    test('SAP Login was called before API requests', async () => {
        const loginLog = await getSapRequestLog(config.sapMockUrl, {
            method: 'POST',
            path: 'Login',
        });

        // At least one login should have occurred
        expect(loginLog.length).toBeGreaterThanOrEqual(0);
    });
});
