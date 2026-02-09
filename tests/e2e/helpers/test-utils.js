/**
 * Test Utilities
 *
 * Shared utility functions for E2E tests including
 * polling, waiting, and common assertion helpers.
 */
const fetch = require('node-fetch');

/**
 * Wait for a condition to be true, polling at intervals.
 * @param {Function} conditionFn - Async function that returns truthy when done
 * @param {object} options
 * @param {number} options.timeout - Max wait time in ms (default: 30000)
 * @param {number} options.interval - Poll interval in ms (default: 1000)
 * @param {string} options.message - Error message on timeout
 */
async function waitForCondition(conditionFn, options = {}) {
    const { timeout = 30000, interval = 1000, message = 'Condition not met' } = options;
    const deadline = Date.now() + timeout;

    while (Date.now() < deadline) {
        try {
            const result = await conditionFn();
            if (result) return result;
        } catch {
            // Condition threw — keep polling
        }
        await sleep(interval);
    }

    throw new Error(`Timeout: ${message} (waited ${timeout}ms)`);
}

/**
 * Wait for a queue event to reach a specific status.
 */
async function waitForEventStatus(db, eventId, expectedStatus, timeout = 30000) {
    return waitForCondition(
        async () => {
            const event = await db.getEventById(eventId);
            return event && event.status === expectedStatus ? event : null;
        },
        { timeout, message: `Event #${eventId} did not reach status '${expectedStatus}'` }
    );
}

/**
 * Wait for a product mapping to exist for a WC product.
 */
async function waitForProductMapping(db, wcProductId, timeout = 30000) {
    return waitForCondition(
        async () => db.getProductMapping(wcProductId),
        { timeout, message: `Product mapping for WC product #${wcProductId} not found` }
    );
}

/**
 * Wait for an order mapping to exist for a WC order.
 */
async function waitForOrderMapping(db, wcOrderId, timeout = 30000) {
    return waitForCondition(
        async () => db.getOrderMapping(wcOrderId),
        { timeout, message: `Order mapping for WC order #${wcOrderId} not found` }
    );
}

/**
 * Wait for queue to drain (no pending events).
 */
async function waitForQueueDrain(db, timeout = 60000) {
    return waitForCondition(
        async () => {
            const depth = await db.getQueueDepth();
            return depth === 0 ? true : null;
        },
        { timeout, interval: 2000, message: 'Queue did not drain' }
    );
}

/**
 * Trigger WP-Cron manually via HTTP.
 * WordPress processes scheduled events on this request.
 */
async function triggerWpCron(wpUrl) {
    try {
        await fetch(`${wpUrl}/wp-cron.php?doing_wp_cron=1`, { timeout: 30000 });
    } catch {
        // WP-Cron may return empty response
    }
}

/**
 * Trigger the plugin's queue processing via AJAX.
 * This simulates what WP-Cron would do on the sap_wc_process_queue hook.
 */
async function triggerQueueProcessing(wpUrl, nonce) {
    try {
        await fetch(`${wpUrl}/wp-admin/admin-ajax.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=sap_wc_process_queue&nonce=${nonce}`,
            timeout: 60000,
        });
    } catch {
        // May fail if no AJAX handler exists for direct trigger
    }
}

/**
 * Sleep for specified milliseconds.
 */
function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

/**
 * Reset SAP mock server state.
 */
async function resetSapMock(sapMockUrl) {
    await fetch(`${sapMockUrl}/__test__/reset`, { method: 'POST' });
}

/**
 * Seed items into SAP mock server.
 */
async function seedSapItems(sapMockUrl, items) {
    await fetch(`${sapMockUrl}/__test__/seed/items`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(items),
    });
}

/**
 * Seed customers into SAP mock server.
 */
async function seedSapCustomers(sapMockUrl, customers) {
    await fetch(`${sapMockUrl}/__test__/seed/customers`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(customers),
    });
}

/**
 * Get SAP mock server state summary.
 */
async function getSapMockState(sapMockUrl) {
    const res = await fetch(`${sapMockUrl}/__test__/state`);
    return res.json();
}

/**
 * Get SAP mock request log (for verifying API calls).
 */
async function getSapRequestLog(sapMockUrl, filters = {}) {
    const url = new URL(`${sapMockUrl}/__test__/request-log`);
    if (filters.method) url.searchParams.set('method', filters.method);
    if (filters.path) url.searchParams.set('path', filters.path);
    const res = await fetch(url.toString());
    return res.json();
}

/**
 * Set SAP mock failure mode.
 */
async function setSapFailMode(sapMockUrl, mode, afterN = 0) {
    await fetch(`${sapMockUrl}/__test__/fail-mode`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ mode, afterN }),
    });
}

/**
 * Simulate SAP stock change via mock server.
 */
async function simulateStockChange(sapMockUrl, itemCode, inStock, committed, warehouse = 'WEB-GEN') {
    await fetch(`${sapMockUrl}/__test__/stock-change`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ itemCode, inStock, committed, warehouse }),
    });
}

module.exports = {
    waitForCondition,
    waitForEventStatus,
    waitForProductMapping,
    waitForOrderMapping,
    waitForQueueDrain,
    triggerWpCron,
    triggerQueueProcessing,
    sleep,
    resetSapMock,
    seedSapItems,
    seedSapCustomers,
    getSapMockState,
    getSapRequestLog,
    setSapFailMode,
    simulateStockChange,
};
