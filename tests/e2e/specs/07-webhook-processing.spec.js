/**
 * Spec 07: Webhook Event Processing
 *
 * Tests the webhook REST endpoint:
 * - Valid HMAC signature -> 202 Accepted
 * - Invalid HMAC signature -> 403 Forbidden
 * - Missing signature -> 403 Forbidden
 * - Unknown event type -> 400 Bad Request
 * - Missing event_type -> 400 Bad Request
 * - Events are queued with correct priority
 * - Health check endpoint works without auth
 */
const WebhookHelper = require('../helpers/webhook-helper');
const DbHelper = require('../helpers/db-helper');
const { sleep } = require('../helpers/test-utils');

describe('Webhook Processing', () => {
    let config, db, webhook;

    beforeAll(async () => {
        config = global.__CONFIG__;
        db = new DbHelper(config);
        await db.connect();
        webhook = new WebhookHelper(config);

        // Ensure webhook secret is configured
        await db.setOption('sap_wc_webhook_secret', config.webhookSecret);
        await db.setOption('sap_wc_enable_webhooks', 'yes');
    });

    afterAll(async () => {
        await db.close();
    });

    // ─── Authentication ────────────────────────────────────────────────────────

    test('valid HMAC signature returns 202 Accepted', async () => {
        const res = await webhook.sendItemCreated('TEST-AUTH-001', 'Auth Test Item');
        expect(res.status).toBe(202);
        expect(res.data.status).toBe('accepted');
        expect(res.data.event_id).toBeGreaterThan(0);
    });

    test('invalid HMAC signature returns 403', async () => {
        const res = await webhook.sendWithBadSignature('item.created', {
            ItemCode: 'TEST-BAD-SIG',
            ItemName: 'Bad Signature Test',
        });
        // WordPress returns 403 for failed permission_callback
        expect([401, 403]).toContain(res.status);
    });

    test('missing signature returns 403', async () => {
        const res = await webhook.sendWithoutSignature('item.created', {
            ItemCode: 'TEST-NO-SIG',
            ItemName: 'No Signature Test',
        });
        expect([401, 403]).toContain(res.status);
    });

    // ─── Event Validation ──────────────────────────────────────────────────────

    test('unknown event type returns 400', async () => {
        const res = await webhook.send('unknown.event.type', { foo: 'bar' });
        expect(res.status).toBe(400);
        expect(res.data.error).toContain('Unknown event type');
    });

    test('missing event_type in payload returns 400', async () => {
        // Send raw payload without event_type wrapper
        const crypto = require('crypto');
        const fetch = require('node-fetch');
        const payload = JSON.stringify({ data: { foo: 'bar' } });
        const signature = crypto.createHmac('sha256', config.webhookSecret).update(payload).digest('hex');

        const fetchRes = await fetch(`${config.wpUrl}/wp-json/sap-wc/v1/webhook`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-SAP-Signature': signature,
            },
            body: payload,
        });

        expect(fetchRes.status).toBe(400);
    });

    // ─── Event Types ───────────────────────────────────────────────────────────

    test('item.created event is accepted and queued', async () => {
        const res = await webhook.sendItemCreated('WH-TEST-001', 'Webhook Test Product', '1234567890');
        expect(res.status).toBe(202);

        const eventId = res.data.event_id;
        const event = await db.getEventById(eventId);
        expect(event).not.toBeNull();
        expect(event.event_type).toBe('item.created');
        expect(event.status).toBe('pending');
    });

    test('item.updated event is accepted', async () => {
        const res = await webhook.sendItemUpdated('WH-TEST-001', 'Updated Name');
        expect(res.status).toBe(202);
    });

    test('item.stock_changed event is accepted', async () => {
        const res = await webhook.sendStockChanged('WH-TEST-001');
        expect(res.status).toBe(202);
    });

    test('item.code_changed event is accepted', async () => {
        const res = await webhook.sendItemCodeChanged('OLD-CODE', 'NEW-CODE');
        expect(res.status).toBe(202);
    });

    test('item.deactivated event is accepted', async () => {
        const res = await webhook.sendItemDeactivated('DEACT-001');
        expect(res.status).toBe(202);
    });

    test('item.returned event is accepted', async () => {
        const res = await webhook.sendItemReturned('RET-001');
        expect(res.status).toBe(202);
    });

    test('order.status_changed event is accepted', async () => {
        const res = await webhook.sendOrderStatusChanged(1000, 'C');
        expect(res.status).toBe(202);
    });

    test('order.cancelled event is accepted', async () => {
        const res = await webhook.sendOrderCancelled(1000);
        expect(res.status).toBe(202);
    });

    // ─── Priority Assignment ───────────────────────────────────────────────────

    test('order events get priority 1 (highest)', async () => {
        const res = await webhook.sendOrderStatusChanged(9999, 'C');
        expect(res.status).toBe(202);

        const event = await db.getEventById(res.data.event_id);
        expect(event.priority).toBe(1);
    });

    test('stock events get priority 2', async () => {
        const res = await webhook.sendStockChanged('PRIO-TEST');
        expect(res.status).toBe(202);

        const event = await db.getEventById(res.data.event_id);
        expect(event.priority).toBe(2);
    });

    test('item.created gets priority 5 (lowest)', async () => {
        const res = await webhook.sendItemCreated('PRIO-LOW', 'Low Priority');
        expect(res.status).toBe(202);

        const event = await db.getEventById(res.data.event_id);
        expect(event.priority).toBe(5);
    });

    // ─── Health Check ──────────────────────────────────────────────────────────

    test('health check endpoint returns system status', async () => {
        const health = await webhook.getHealth();

        expect(health).toHaveProperty('status');
        expect(health).toHaveProperty('version');
        expect(health).toHaveProperty('circuit_breaker');
        expect(health).toHaveProperty('queue_depth');
        expect(health).toHaveProperty('dead_letters');
        expect(['healthy', 'degraded']).toContain(health.status);
    });

    test('health check does not require authentication', async () => {
        const fetch = require('node-fetch');
        const res = await fetch(`${config.wpUrl}/wp-json/sap-wc/v1/health`);
        expect(res.status).toBe(200);
    });

    // ─── Queue Integrity ───────────────────────────────────────────────────────

    test('multiple rapid events are all queued correctly', async () => {
        const promises = [];
        for (let i = 0; i < 5; i++) {
            promises.push(webhook.sendItemCreated(`RAPID-${i}`, `Rapid Item ${i}`));
        }

        const results = await Promise.all(promises);
        const allAccepted = results.every(r => r.status === 202);
        expect(allAccepted).toBe(true);

        // Each should have a unique event_id
        const eventIds = results.map(r => r.data.event_id);
        const uniqueIds = new Set(eventIds);
        expect(uniqueIds.size).toBe(5);
    });
});
