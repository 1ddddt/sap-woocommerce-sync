/**
 * Webhook Helper
 *
 * Sends webhook events to the plugin's REST endpoint
 * with proper HMAC-SHA256 signature generation.
 */
const crypto = require('crypto');
const fetch = require('node-fetch');

class WebhookHelper {
    /**
     * @param {object} config - From __CONFIG__ global
     */
    constructor(config) {
        this.webhookUrl = `${config.wpUrl}/wp-json/sap-wc/v1/webhook`;
        this.healthUrl = `${config.wpUrl}/wp-json/sap-wc/v1/health`;
        this.secret = config.webhookSecret;
        this.timeout = config.navigationTimeout || 30000;
    }

    /**
     * Generate HMAC-SHA256 signature for a payload.
     */
    sign(body) {
        return crypto
            .createHmac('sha256', this.secret)
            .update(body)
            .digest('hex');
    }

    /**
     * Send a webhook event with valid HMAC signature.
     * @returns {Promise<{status: number, data: object}>}
     */
    async send(eventType, data = {}) {
        const payload = JSON.stringify({ event_type: eventType, data });
        const signature = this.sign(payload);

        const res = await fetch(this.webhookUrl, {
            method: 'POST',
            timeout: this.timeout,
            headers: {
                'Content-Type': 'application/json',
                'X-SAP-Signature': signature,
            },
            body: payload,
        });

        const responseData = await res.json().catch(() => ({}));
        return { status: res.status, data: responseData };
    }

    /**
     * Send a webhook event with INVALID signature (for security testing).
     */
    async sendWithBadSignature(eventType, data = {}) {
        const payload = JSON.stringify({ event_type: eventType, data });

        const res = await fetch(this.webhookUrl, {
            method: 'POST',
            timeout: this.timeout,
            headers: {
                'Content-Type': 'application/json',
                'X-SAP-Signature': 'invalid-signature-000000',
            },
            body: payload,
        });

        const responseData = await res.json().catch(() => ({}));
        return { status: res.status, data: responseData };
    }

    /**
     * Send a webhook event with NO signature header.
     */
    async sendWithoutSignature(eventType, data = {}) {
        const payload = JSON.stringify({ event_type: eventType, data });

        const res = await fetch(this.webhookUrl, {
            method: 'POST',
            timeout: this.timeout,
            headers: {
                'Content-Type': 'application/json',
            },
            body: payload,
        });

        const responseData = await res.json().catch(() => ({}));
        return { status: res.status, data: responseData };
    }

    /**
     * Get health check status.
     */
    async getHealth() {
        const res = await fetch(this.healthUrl, { timeout: this.timeout });
        return res.json();
    }

    // ─── Convenience Methods for Common Events ─────────────────────────────────

    async sendItemCreated(itemCode, itemName, barcode = null) {
        return this.send('item.created', {
            ItemCode: itemCode,
            ItemName: itemName,
            BarCode: barcode,
            ItemBarCodeCollection: barcode ? [{ Barcode: barcode }] : [],
        });
    }

    async sendItemUpdated(itemCode, itemName, barcode = null) {
        return this.send('item.updated', {
            ItemCode: itemCode,
            ItemName: itemName,
            BarCode: barcode,
        });
    }

    async sendStockChanged(itemCode) {
        return this.send('item.stock_changed', {
            ItemCode: itemCode,
        });
    }

    async sendItemCodeChanged(oldItemCode, newItemCode) {
        return this.send('item.code_changed', {
            OldItemCode: oldItemCode,
            NewItemCode: newItemCode,
        });
    }

    async sendItemDeactivated(itemCode) {
        return this.send('item.deactivated', {
            ItemCode: itemCode,
        });
    }

    async sendItemReturned(itemCode) {
        return this.send('item.returned', {
            ItemCode: itemCode,
        });
    }

    async sendOrderPlaced(orderId) {
        return this.send('order.placed', {
            order_id: orderId,
        });
    }

    async sendOrderStatusChanged(docEntry, status) {
        return this.send('order.status_changed', {
            DocEntry: docEntry,
            Status: status,
        });
    }

    async sendOrderCancelled(docEntry) {
        return this.send('order.cancelled', {
            DocEntry: docEntry,
        });
    }

    async sendOrderDelivered(orderId, sapDocEntry) {
        return this.send('order.delivered', {
            order_id: orderId,
            sap_doc_entry: sapDocEntry,
        });
    }

    async sendOrderRefunded(orderId, refundId, sapDocEntry) {
        return this.send('order.refunded', {
            order_id: orderId,
            refund_id: refundId,
            sap_doc_entry: sapDocEntry,
        });
    }
}

module.exports = WebhookHelper;
