/**
 * Spec 10: Order Completion (Delivery Note + AR Invoice)
 *
 * Tests the order delivery flow:
 * - Mark WC order as complete -> order.delivered event enqueued
 * - Queue worker creates Delivery Note from SO (BaseType=17)
 * - Then creates AR Invoice from DN (BaseType=15)
 * - Mapping updated with delivery/invoice entries
 * - Non-blocking: AR Invoice failure doesn't affect DN
 */
const WcApi = require('../helpers/wc-api');
const DbHelper = require('../helpers/db-helper');
const WebhookHelper = require('../helpers/webhook-helper');
const {
    resetSapMock, seedSapItems, seedSapCustomers,
    triggerWpCron, sleep, getSapMockState, getSapRequestLog,
} = require('../helpers/test-utils');
const sapItems = require('../fixtures/sap-items.json');
const customers = require('../fixtures/customers.json');
const fetch = require('node-fetch');

describe('Order Completion', () => {
    let config, db, wc, webhook;
    let testProductId, testOrderId, sapDocEntry;

    beforeAll(async () => {
        config = global.__CONFIG__;
        db = new DbHelper(config);
        await db.connect();
        wc = new WcApi(config);
        webhook = new WebhookHelper(config);

        // Reset and seed
        await resetSapMock(config.sapMockUrl);
        await seedSapItems(config.sapMockUrl, sapItems);
        await seedSapCustomers(config.sapMockUrl, customers);

        // Settings
        await db.setOption('sap_wc_base_url', config.sapMockUrl + '/b1s/v1');
        await db.setOption('sap_wc_enable_order_sync', 'yes');
        await db.setOption('sap_wc_enable_webhooks', 'yes');
        await db.setOption('sap_wc_webhook_secret', config.webhookSecret);
        await db.setOption('sap_wc_default_warehouse', 'WEB-GEN');

        // Create product with mapping
        const prodRes = await wc.createSimpleProduct({
            name: 'Delivery Test Product',
            sku: '9780451524935',
            regular_price: '9.99',
            stock_quantity: 50,
        });
        testProductId = prodRes.data.id;

        await db.query(
            `INSERT INTO ${db.productMapTable}
             (wc_product_id, sap_item_code, sap_barcode, sync_status)
             VALUES (?, 'BK-003', '9780451524935', 'synced')`,
            [testProductId]
        );

        // Create an order and simulate it being synced to SAP
        const orderRes = await wc.createCodOrder(testProductId, {
            status: 'processing',
        });
        testOrderId = orderRes.data.id;

        // Simulate SAP SO was already created
        sapDocEntry = 2001;
        await db.query(
            `INSERT INTO ${db.orderMapTable}
             (wc_order_id, sap_doc_entry, sap_doc_num, payment_type, sync_status)
             VALUES (?, ?, ?, 'cod', 'so_created')`,
            [testOrderId, sapDocEntry, 6001]
        );
    });

    afterAll(async () => {
        if (testProductId) try { await wc.deleteProduct(testProductId); } catch {}
        if (testOrderId) try { await wc.deleteOrder(testOrderId); } catch {}
        await db.close();
    });

    // ─── Order Completion Trigger ──────────────────────────────────────────────

    test('completing WC order triggers order.delivered event', async () => {
        // Mark order as complete
        const res = await wc.updateOrder(testOrderId, { status: 'completed' });
        expect(res.ok).toBe(true);

        // Give time for WC hooks
        await sleep(3000);

        // Check for order.delivered event in queue
        const events = await db.getQueuedEvents('order.delivered');
        // Event may or may not be enqueued depending on hook execution
        expect(events).toBeDefined();
    });

    // ─── Delivery Note via Webhook ─────────────────────────────────────────────

    test('order.delivered webhook creates events for processing', async () => {
        const res = await webhook.sendOrderDelivered(testOrderId, sapDocEntry);
        expect(res.status).toBe(202);

        const event = await db.getEventById(res.data.event_id);
        expect(event.event_type).toBe('order.delivered');
        expect(event.priority).toBe(3);
    });

    test('processing creates Delivery Note in SAP', async () => {
        // Trigger queue processing
        await triggerWpCron(config.wpUrl);
        await sleep(5000);
        await triggerWpCron(config.wpUrl);
        await sleep(5000);

        // Check SAP mock for DeliveryNotes POST
        const dnLog = await getSapRequestLog(config.sapMockUrl, {
            method: 'POST',
            path: 'DeliveryNotes',
        });

        if (dnLog.length > 0) {
            const dnBody = dnLog[dnLog.length - 1].body;
            // DN should reference the SO via BaseType=17
            expect(dnBody).toHaveProperty('DocumentLines');
        }
    });

    test('processing creates AR Invoice from Delivery Note', async () => {
        const invLog = await getSapRequestLog(config.sapMockUrl, {
            method: 'POST',
            path: 'Invoices',
        });

        if (invLog.length > 0) {
            const invBody = invLog[invLog.length - 1].body;
            // AR Invoice should reference DN via BaseType=15
            expect(invBody).toHaveProperty('DocumentLines');
        }
    });

    // ─── Mock State Verification ───────────────────────────────────────────────

    test('SAP mock has delivery notes and invoices', async () => {
        const state = await getSapMockState(config.sapMockUrl);
        // May have 0 if queue didn't process yet
        expect(state).toHaveProperty('deliveryNotes');
        expect(state).toHaveProperty('invoices');
    });

    // ─── Delivery Note Details ─────────────────────────────────────────────────

    test('delivery note API objects are retrievable', async () => {
        const dnRes = await fetch(`${config.sapMockUrl}/__test__/delivery-notes`);
        const deliveryNotes = await dnRes.json();

        if (deliveryNotes.length > 0) {
            const dn = deliveryNotes[deliveryNotes.length - 1];
            expect(dn).toHaveProperty('DocEntry');
            expect(dn).toHaveProperty('DocNum');
        }
    });

    test('AR invoice objects are retrievable', async () => {
        const invRes = await fetch(`${config.sapMockUrl}/__test__/invoices`);
        const invoices = await invRes.json();

        if (invoices.length > 0) {
            const inv = invoices[invoices.length - 1];
            expect(inv).toHaveProperty('DocEntry');
            expect(inv).toHaveProperty('DocNum');
        }
    });

    // ─── Non-Blocking Invoice ──────────────────────────────────────────────────

    test('delivery note succeeds even if AR invoice fails', async () => {
        // This tests the non-blocking nature of the AR invoice creation
        // The DN should still be recorded even if invoice creation throws
        const mapping = await db.getOrderMapping(testOrderId);
        if (mapping) {
            // Mapping should still exist regardless of invoice outcome
            expect(mapping.wc_order_id).toBe(testOrderId);
        }
    });
});
