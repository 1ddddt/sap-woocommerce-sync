/**
 * Jest Global Setup
 *
 * Starts the SAP mock server and verifies WordPress is reachable
 * before any test spec runs.
 */
const path = require('path');
require('dotenv').config({ path: path.resolve(__dirname, '../../../.env') });

const { startServer } = require('../helpers/sap-mock-server');
const fetch = require('node-fetch');

module.exports = async function globalSetup() {
    console.log('\n🔧 Global Setup: Starting SAP mock server...');

    // Start mock SAP Service Layer
    const server = await startServer(Number(process.env.SAP_MOCK_PORT) || 3001);
    global.__SAP_MOCK_SERVER__ = server;

    // Store server reference for teardown
    process.env.__SAP_MOCK_STARTED__ = 'true';

    // Verify WordPress is reachable
    const wpUrl = process.env.WP_URL || 'http://localhost:8080';
    try {
        const res = await fetch(`${wpUrl}/wp-json/`, { timeout: 10000 });
        if (!res.ok) {
            throw new Error(`WordPress responded with ${res.status}`);
        }
        console.log(`✅ WordPress reachable at ${wpUrl}`);
    } catch (err) {
        console.error(`❌ Cannot reach WordPress at ${wpUrl}: ${err.message}`);
        console.error('   Make sure WordPress is running and WP_URL in .env is correct.');
        process.exit(1);
    }

    // Verify WooCommerce REST API
    const wcKey = process.env.WC_CONSUMER_KEY;
    const wcSecret = process.env.WC_CONSUMER_SECRET;
    if (wcKey && wcSecret) {
        try {
            const res = await fetch(
                `${wpUrl}/wp-json/wc/v3/system_status?consumer_key=${wcKey}&consumer_secret=${wcSecret}`,
                { timeout: 10000 }
            );
            if (res.ok) {
                console.log('✅ WooCommerce REST API accessible');
            } else {
                console.warn('⚠️  WooCommerce REST API returned', res.status);
            }
        } catch (err) {
            console.warn('⚠️  Could not verify WooCommerce API:', err.message);
        }
    }

    // Verify plugin webhook endpoint exists
    try {
        const res = await fetch(`${wpUrl}/wp-json/sap-wc/v1/health`, { timeout: 10000 });
        if (res.ok) {
            const data = await res.json();
            console.log(`✅ Plugin health endpoint: ${data.status} (v${data.version})`);
        } else {
            console.warn('⚠️  Plugin health endpoint returned', res.status);
        }
    } catch (err) {
        console.warn('⚠️  Plugin health endpoint not reachable:', err.message);
    }

    console.log('🔧 Global Setup complete.\n');
};
