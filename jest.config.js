/**
 * Jest Configuration for SAP WooCommerce Sync v2 E2E Tests
 *
 * Runs specs sequentially (--runInBand) because tests depend on
 * WordPress state and must execute in order.
 */
const path = require('path');

module.exports = {
    testMatch: ['**/tests/e2e/specs/**/*.spec.js'],
    testEnvironment: path.resolve(__dirname, 'tests/e2e/setup/jest-environment.js'),
    globalSetup: path.resolve(__dirname, 'tests/e2e/setup/global-setup.js'),
    globalTeardown: path.resolve(__dirname, 'tests/e2e/setup/global-teardown.js'),
    testTimeout: 120000,
    setupFilesAfterFramework: [],
    verbose: true,
    bail: false,
    reporters: [
        'default',
        [
            'jest-html-reporters',
            {
                publicPath: './tests/e2e/reports',
                filename: 'e2e-report.html',
                pageTitle: 'SAP WC Sync v2 - E2E Test Report',
                expand: true,
            },
        ],
    ].filter(r => {
        // Only use HTML reporter if installed
        if (Array.isArray(r) && r[0] === 'jest-html-reporters') {
            try { require.resolve('jest-html-reporters'); return true; }
            catch { return false; }
        }
        return true;
    }),
};
