/**
 * Jest Global Teardown
 *
 * Stops the SAP mock server and cleans up test resources.
 */
module.exports = async function globalTeardown() {
    console.log('\n🧹 Global Teardown: Cleaning up...');

    // Stop mock SAP server
    if (global.__SAP_MOCK_SERVER__) {
        await new Promise((resolve) => {
            global.__SAP_MOCK_SERVER__.close(() => {
                console.log('✅ SAP mock server stopped');
                resolve();
            });
        });
    }

    console.log('🧹 Teardown complete.\n');
};
