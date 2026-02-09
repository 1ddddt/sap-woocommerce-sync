/**
 * Database Helper
 *
 * Direct MySQL access for test verification queries.
 * Uses mysql2/promise for async operations on plugin tables.
 */
const mysql = require('mysql2/promise');

class DbHelper {
    /**
     * @param {object} config - From __CONFIG__ global
     */
    constructor(config) {
        this.config = {
            host: config.dbHost,
            port: config.dbPort,
            user: config.dbUser,
            password: config.dbPass,
            database: config.dbName,
        };
        this.prefix = config.dbPrefix || 'wp_';
        this.pool = null;
    }

    async connect() {
        if (!this.pool) {
            this.pool = mysql.createPool({
                ...this.config,
                waitForConnections: true,
                connectionLimit: 5,
                queueLimit: 0,
            });
        }
        return this.pool;
    }

    async query(sql, params = []) {
        const pool = await this.connect();
        const [rows] = await pool.execute(sql, params);
        return rows;
    }

    async close() {
        if (this.pool) {
            await this.pool.end();
            this.pool = null;
        }
    }

    // ─── Table Names ───────────────────────────────────────────────────────────

    table(name) {
        return `${this.prefix}${name}`;
    }

    get productMapTable() { return this.table('sap_wc_product_map'); }
    get orderMapTable() { return this.table('sap_wc_order_map'); }
    get customerMapTable() { return this.table('sap_wc_customer_map'); }
    get syncLogTable() { return this.table('sap_wc_sync_log'); }
    get eventQueueTable() { return this.table('sap_wc_event_queue'); }
    get deadLetterTable() { return this.table('sap_wc_dead_letter_queue'); }

    // ─── Product Map ───────────────────────────────────────────────────────────

    async getProductMapping(wcProductId) {
        const rows = await this.query(
            `SELECT * FROM ${this.productMapTable} WHERE wc_product_id = ?`,
            [wcProductId]
        );
        return rows[0] || null;
    }

    async getProductMappingByItemCode(itemCode) {
        const rows = await this.query(
            `SELECT * FROM ${this.productMapTable} WHERE sap_item_code = ?`,
            [itemCode]
        );
        return rows[0] || null;
    }

    async countProductMappings(status = null) {
        let sql = `SELECT COUNT(*) as cnt FROM ${this.productMapTable}`;
        const params = [];
        if (status) {
            sql += ' WHERE sync_status = ?';
            params.push(status);
        }
        const rows = await this.query(sql, params);
        return rows[0].cnt;
    }

    async getAllProductMappings() {
        return this.query(`SELECT * FROM ${this.productMapTable} ORDER BY id`);
    }

    // ─── Order Map ─────────────────────────────────────────────────────────────

    async getOrderMapping(wcOrderId) {
        const rows = await this.query(
            `SELECT * FROM ${this.orderMapTable} WHERE wc_order_id = ?`,
            [wcOrderId]
        );
        return rows[0] || null;
    }

    async countOrderMappings(status = null) {
        let sql = `SELECT COUNT(*) as cnt FROM ${this.orderMapTable}`;
        const params = [];
        if (status) {
            sql += ' WHERE sync_status = ?';
            params.push(status);
        }
        const rows = await this.query(sql, params);
        return rows[0].cnt;
    }

    // ─── Event Queue ───────────────────────────────────────────────────────────

    async getQueuedEvents(eventType = null) {
        let sql = `SELECT * FROM ${this.eventQueueTable}`;
        const params = [];
        if (eventType) {
            sql += ' WHERE event_type = ?';
            params.push(eventType);
        }
        sql += ' ORDER BY id DESC';
        return this.query(sql, params);
    }

    async getPendingEvents() {
        return this.query(
            `SELECT * FROM ${this.eventQueueTable} WHERE status = 'pending' ORDER BY priority ASC, created_at ASC`
        );
    }

    async getQueueDepth() {
        const rows = await this.query(
            `SELECT COUNT(*) as cnt FROM ${this.eventQueueTable} WHERE status = 'pending'`
        );
        return rows[0].cnt;
    }

    async getEventById(id) {
        const rows = await this.query(
            `SELECT * FROM ${this.eventQueueTable} WHERE id = ?`,
            [id]
        );
        return rows[0] || null;
    }

    // ─── Dead Letter Queue ─────────────────────────────────────────────────────

    async getDeadLetters(resolved = false) {
        return this.query(
            `SELECT * FROM ${this.deadLetterTable} WHERE resolved = ? ORDER BY id DESC`,
            [resolved ? 1 : 0]
        );
    }

    async countDeadLetters(resolved = false) {
        const rows = await this.query(
            `SELECT COUNT(*) as cnt FROM ${this.deadLetterTable} WHERE resolved = ?`,
            [resolved ? 1 : 0]
        );
        return rows[0].cnt;
    }

    // ─── Sync Logs ─────────────────────────────────────────────────────────────

    async getRecentLogs(limit = 20) {
        return this.query(
            `SELECT * FROM ${this.syncLogTable} ORDER BY id DESC LIMIT ?`,
            [limit]
        );
    }

    async getLogsByEntity(entityType, entityId = null) {
        let sql = `SELECT * FROM ${this.syncLogTable} WHERE entity_type = ?`;
        const params = [entityType];
        if (entityId) {
            sql += ' AND entity_id = ?';
            params.push(entityId);
        }
        sql += ' ORDER BY id DESC';
        return this.query(sql, params);
    }

    // ─── WordPress Options ─────────────────────────────────────────────────────

    async getOption(key) {
        const rows = await this.query(
            `SELECT option_value FROM ${this.table('options')} WHERE option_name = ?`,
            [key]
        );
        return rows[0]?.option_value ?? null;
    }

    async setOption(key, value) {
        await this.query(
            `INSERT INTO ${this.table('options')} (option_name, option_value, autoload)
             VALUES (?, ?, 'no')
             ON DUPLICATE KEY UPDATE option_value = ?`,
            [key, value, value]
        );
    }

    async getTransient(key) {
        return this.getOption(`_transient_${key}`);
    }

    // ─── Circuit Breaker ───────────────────────────────────────────────────────

    async getCircuitBreakerState() {
        const val = await this.getOption('sap_wc_circuit_breaker');
        return val ? JSON.parse(val) : null;
    }

    async setCircuitBreakerState(state) {
        await this.setOption('sap_wc_circuit_breaker', JSON.stringify(state));
    }

    // ─── Cleanup ───────────────────────────────────────────────────────────────

    async truncatePluginTables() {
        const tables = [
            this.productMapTable,
            this.orderMapTable,
            this.customerMapTable,
            this.syncLogTable,
            this.eventQueueTable,
            this.deadLetterTable,
        ];
        for (const table of tables) {
            try {
                await this.query(`TRUNCATE TABLE ${table}`);
            } catch (err) {
                // Table might not exist yet
                console.warn(`Could not truncate ${table}: ${err.message}`);
            }
        }
    }

    async deleteTestProducts() {
        await this.query(
            `DELETE FROM ${this.table('posts')} WHERE post_title LIKE 'Test Product%' AND post_type = 'product'`
        );
    }

    async deleteTestOrders() {
        // For HPOS stores, orders are in wc_orders table
        try {
            await this.query(
                `DELETE FROM ${this.table('wc_orders')} WHERE id IN (
                    SELECT order_id FROM ${this.table('wc_orders_meta')}
                    WHERE meta_key = '_billing_email' AND meta_value = 'test@example.com'
                )`
            );
        } catch {
            // Fallback for legacy post-based orders
            await this.query(
                `DELETE FROM ${this.table('posts')} WHERE post_type = 'shop_order' AND ID IN (
                    SELECT post_id FROM ${this.table('postmeta')}
                    WHERE meta_key = '_billing_email' AND meta_value = 'test@example.com'
                )`
            );
        }
    }
}

module.exports = DbHelper;
