/**
 * WordPress Login Page Object
 *
 * Handles authentication via the WP login form.
 * URL: /wp-login.php
 */
class WpLogin {
    /**
     * @param {import('puppeteer').Page} page
     * @param {object} config - From __CONFIG__ global
     */
    constructor(page, config) {
        this.page = page;
        this.config = config;
        this.url = `${config.wpUrl}/wp-login.php`;
    }

    /**
     * Login to WordPress admin.
     * Skips if already logged in.
     */
    async login() {
        // Check if already logged in
        await this.page.goto(`${this.config.wpUrl}${this.config.wpAdminPath}/`, {
            waitUntil: 'networkidle2',
        });

        const currentUrl = this.page.url();
        if (!currentUrl.includes('wp-login.php')) {
            // Already logged in
            return;
        }

        // Navigate to login page
        await this.page.goto(this.url, { waitUntil: 'networkidle2' });

        // Fill credentials
        await this.page.type('#user_login', this.config.wpAdmin);
        await this.page.type('#user_pass', this.config.wpPass);

        // Check "Remember Me"
        const rememberMe = await this.page.$('#rememberme');
        if (rememberMe) {
            const isChecked = await this.page.$eval('#rememberme', el => el.checked);
            if (!isChecked) await rememberMe.click();
        }

        // Submit login
        await Promise.all([
            this.page.waitForNavigation({ waitUntil: 'networkidle2' }),
            this.page.click('#wp-submit'),
        ]);

        // Verify we're logged in
        const afterUrl = this.page.url();
        if (afterUrl.includes('wp-login.php')) {
            throw new Error('WordPress login failed — still on login page');
        }
    }

    /**
     * Logout from WordPress.
     */
    async logout() {
        // Get logout URL from admin bar
        const logoutUrl = await this.page.evaluate(() => {
            const link = document.querySelector('#wp-admin-bar-logout a');
            return link ? link.href : null;
        });

        if (logoutUrl) {
            await this.page.goto(logoutUrl, { waitUntil: 'networkidle2' });
        }
    }

    /**
     * Check if currently logged in.
     */
    async isLoggedIn() {
        try {
            await this.page.goto(`${this.config.wpUrl}${this.config.wpAdminPath}/`, {
                waitUntil: 'networkidle2',
            });
            return !this.page.url().includes('wp-login.php');
        } catch {
            return false;
        }
    }
}

module.exports = WpLogin;
