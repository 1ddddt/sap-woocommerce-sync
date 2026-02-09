/**
 * WordPress Test Environment Provisioner
 *
 * Sets up WordPress options and test data required before running E2E tests.
 * Run this once before your first test run:
 *
 *   npm run setup:wp
 *
 * Prerequisites:
 * 1. WordPress running and accessible at WP_URL
 * 2. Plugin installed and activated
 * 3. WooCommerce installed and configured
 * 4. .env file filled with correct credentials
 */
const path = require('path');
require('dotenv').config({ path: path.resolve(__dirname, '../../../.env') });

const DbHelper = require('../helpers/db-helper');
const { startServer } = require('../helpers/sap-mock-server');

async function provision() {
    console.log('=== SAP WC Sync v2 - E2E Environment Setup ===\n');

    const config = {
        dbHost: process.env.DB_HOST || '127.0.0.1',
        dbPort: Number(process.env.DB_PORT) || 3306,
        dbName: process.env.DB_NAME || 'wordpress',
        dbUser: process.env.DB_USER || 'root',
        dbPass: process.env.DB_PASS || 'root',
        dbPrefix: process.env.DB_PREFIX || 'wp_',
        wpUrl: process.env.WP_URL || 'http://localhost:8080',
        sapMockUrl: process.env.SAP_MOCK_URL || 'http://localhost:3001',
        webhookSecret: process.env.WEBHOOK_SECRET || 'test-webhook-secret-for-e2e',
    };

    const db = new DbHelper(config);
    await db.connect();

    try {
        // 1. Configure SAP connection to mock server
        console.log('1. Setting SAP connection to mock server...');
        await db.setOption('sap_wc_base_url', config.sapMockUrl + '/b1s/v1');
        await db.setOption('sap_wc_company_db', 'E2ETestDB');
        await db.setOption('sap_wc_username', 'manager');
        await db.setOption('sap_wc_password', 'test-password');
        await db.setOption('sap_wc_default_warehouse', 'WEB-GEN');
        console.log('   Done.\n');

        // 2. Configure webhook settings
        console.log('2. Configuring webhook settings...');
        await db.setOption('sap_wc_webhook_secret', config.webhookSecret);
        await db.setOption('sap_wc_enable_webhooks', 'yes');
        console.log('   Done.\n');

        // 3. Enable sync features
        console.log('3. Enabling sync features...');
        await db.setOption('sap_wc_enable_order_sync', 'yes');
        await db.setOption('sap_wc_enable_inventory_sync', 'yes');
        await db.setOption('sap_wc_transfer_account', '_SYS00000000155');
        await db.setOption('sap_wc_default_customer', 'C00001');
        console.log('   Done.\n');

        // 4. Reset circuit breaker to healthy state
        console.log('4. Resetting circuit breaker...');
        await db.setCircuitBreakerState({
            state: 'closed',
            failure_count: 0,
            last_failure: 0,
            opened_at: 0,
        });
        console.log('   Done.\n');

        // 5. Clean up old test data
        console.log('5. Cleaning up old test data...');
        await db.truncatePluginTables();
        console.log('   Done.\n');

        // 6. Verify tables exist
        console.log('6. Verifying database tables...');
        const tables = [
            db.productMapTable,
            db.orderMapTable,
            db.customerMapTable,
            db.syncLogTable,
            db.eventQueueTable,
            db.deadLetterTable,
        ];
        for (const table of tables) {
            try {
                await db.query(`SELECT 1 FROM ${table} LIMIT 1`);
                console.log(`   ${table}`);
            } catch (err) {
                console.error(`   MISSING: ${table} - ${err.message}`);
            }
        }
        console.log('');

        console.log('=== Setup Complete ===');
        console.log(`WordPress URL: ${config.wpUrl}`);
        console.log(`SAP Mock URL:  ${config.sapMockUrl}`);
        console.log(`Webhook Secret: ${config.webhookSecret}`);
        console.log('\nRun tests with: npm test');

    } catch (err) {
        console.error('Setup failed:', err.message);
        process.exit(1);
    } finally {
        await db.close();
    }
}

provision();
