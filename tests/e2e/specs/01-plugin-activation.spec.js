/**
 * Spec 01: Plugin Activation & Database Setup
 *
 * Verifies the plugin activates cleanly, creates all required
 * database tables, schedules cron hooks, and initializes options.
 */
const WpLogin = require('../helpers/page-objects/wp-login');
const DbHelper = require('../helpers/db-helper');

describe('Plugin Activation', () => {
    let page, config, db, login;

    beforeAll(async () => {
        config = global.__CONFIG__;
        db = new DbHelper(config);
        await db.connect();
        page = await global.__BROWSER__.newPage();
        page.setDefaultTimeout(config.defaultTimeout);
        page.setDefaultNavigationTimeout(config.navigationTimeout);
        login = new WpLogin(page, config);
        await login.login();
    });

    afterAll(async () => {
        await page.close();
        await db.close();
    });

    test('plugin is active', async () => {
        await page.goto(`${config.wpUrl}${config.wpAdminPath}/plugins.php`, {
            waitUntil: 'networkidle2',
        });

        const pluginRow = await page.evaluate(() => {
            const rows = document.querySelectorAll('tr[data-plugin]');
            for (const row of rows) {
                const name = row.querySelector('.plugin-title strong');
                if (name && name.textContent.includes('SAP WooCommerce Sync')) {
                    return {
                        name: name.textContent.trim(),
                        isActive: row.classList.contains('active'),
                    };
                }
            }
            return null;
        });

        expect(pluginRow).not.toBeNull();
        expect(pluginRow.isActive).toBe(true);
    });

    test('product_map table exists with correct schema', async () => {
        const columns = await db.query(
            `SHOW COLUMNS FROM ${db.productMapTable}`
        );
        const columnNames = columns.map(c => c.Field);

        expect(columnNames).toContain('id');
        expect(columnNames).toContain('wc_product_id');
        expect(columnNames).toContain('sap_item_code');
        expect(columnNames).toContain('sap_barcode');
        expect(columnNames).toContain('sync_status');
        expect(columnNames).toContain('last_sync_at');
    });

    test('order_map table exists with correct schema', async () => {
        const columns = await db.query(
            `SHOW COLUMNS FROM ${db.orderMapTable}`
        );
        const columnNames = columns.map(c => c.Field);

        expect(columnNames).toContain('id');
        expect(columnNames).toContain('wc_order_id');
        expect(columnNames).toContain('sap_doc_entry');
        expect(columnNames).toContain('payment_type');
        expect(columnNames).toContain('sync_status');
    });

    test('event_queue table exists with correct schema', async () => {
        const columns = await db.query(
            `SHOW COLUMNS FROM ${db.eventQueueTable}`
        );
        const columnNames = columns.map(c => c.Field);

        expect(columnNames).toContain('id');
        expect(columnNames).toContain('event_type');
        expect(columnNames).toContain('event_source');
        expect(columnNames).toContain('payload');
        expect(columnNames).toContain('status');
        expect(columnNames).toContain('priority');
        expect(columnNames).toContain('attempts');
        expect(columnNames).toContain('max_attempts');
        expect(columnNames).toContain('process_after');
        expect(columnNames).toContain('locked_at');
    });

    test('dead_letter_queue table exists with correct schema', async () => {
        const columns = await db.query(
            `SHOW COLUMNS FROM ${db.deadLetterTable}`
        );
        const columnNames = columns.map(c => c.Field);

        expect(columnNames).toContain('id');
        expect(columnNames).toContain('original_event_id');
        expect(columnNames).toContain('event_type');
        expect(columnNames).toContain('payload');
        expect(columnNames).toContain('error_history');
        expect(columnNames).toContain('total_attempts');
        expect(columnNames).toContain('resolved');
    });

    test('sync_log table exists', async () => {
        const columns = await db.query(
            `SHOW COLUMNS FROM ${db.syncLogTable}`
        );
        const columnNames = columns.map(c => c.Field);

        expect(columnNames).toContain('id');
        expect(columnNames).toContain('entity_type');
        expect(columnNames).toContain('entity_id');
        expect(columnNames).toContain('action');
        expect(columnNames).toContain('status');
        expect(columnNames).toContain('message');
    });

    test('customer_map table exists', async () => {
        const columns = await db.query(
            `SHOW COLUMNS FROM ${db.customerMapTable}`
        );
        const columnNames = columns.map(c => c.Field);

        expect(columnNames).toContain('wc_customer_id');
        expect(columnNames).toContain('sap_card_code');
    });

    test('cron hooks are scheduled (5-minute intervals)', async () => {
        const cronOption = await db.getOption('cron');
        expect(cronOption).toBeTruthy();

        // The cron data contains our hooks
        const cronData = typeof cronOption === 'string' ? cronOption : JSON.stringify(cronOption);
        expect(cronData).toContain('sap_wc_sync_inventory');
        expect(cronData).toContain('sap_wc_process_queue');
    });

    test('no legacy 30-second cron schedule exists', async () => {
        const cronOption = await db.getOption('cron');
        const cronData = typeof cronOption === 'string' ? cronOption : JSON.stringify(cronOption);

        // v1 used a 30-second interval — should not exist
        expect(cronData).not.toContain('sap_wc_30sec');
    });

    test('health endpoint returns valid response', async () => {
        const response = await page.evaluate(async (url) => {
            const res = await fetch(`${url}/wp-json/sap-wc/v1/health`);
            return { status: res.status, data: await res.json() };
        }, config.wpUrl);

        expect(response.status).toBe(200);
        expect(response.data).toHaveProperty('status');
        expect(response.data).toHaveProperty('version');
        expect(response.data).toHaveProperty('circuit_breaker');
        expect(response.data).toHaveProperty('queue_depth');
    });
});
