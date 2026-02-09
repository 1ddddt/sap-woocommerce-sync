/**
 * SAP Service Layer Mock Server
 *
 * Express server that simulates SAP B1 Service Layer REST API.
 * Maintains in-memory state for items, orders, customers, and documents.
 * Tests can manipulate state via /__test__/* control endpoints.
 *
 * SAP API behavior simulated:
 * - Session-based auth via /Login endpoint
 * - OData pagination ($top, $skip, $select, $filter)
 * - 20-item hard page limit
 * - ItemWarehouseInfoCollection inline expansion
 * - Document creation with DocEntry/DocNum auto-generation
 */
const express = require('express');

// ─── In-Memory State ───────────────────────────────────────────────────────────

let state = getInitialState();

function getInitialState() {
    return {
        sessionId: null,
        items: [],
        orders: [],
        deliveryNotes: [],
        invoices: [],
        creditMemos: [],
        dpInvoices: [],
        payments: [],
        customers: [],
        docEntrySeq: 1000,
        docNumSeq: 5000,
        failMode: null,         // null | 'timeout' | 'error' | 'intermittent'
        failCounter: 0,
        failAfterN: 0,          // For intermittent: fail after N successful calls
        requestLog: [],         // Log all requests for assertion
    };
}

function nextDocEntry() {
    return state.docEntrySeq++;
}

function nextDocNum() {
    return state.docNumSeq++;
}

// ─── OData Helpers ─────────────────────────────────────────────────────────────

function applyOData(collection, query) {
    let result = [...collection];

    // $filter (basic support for eq, and)
    if (query.$filter) {
        const filters = query.$filter.split(' and ').map(f => f.trim());
        result = result.filter(item => {
            return filters.every(filter => {
                // Handle: Field eq 'Value'
                const eqMatch = filter.match(/(\w+)\s+eq\s+'([^']+)'/);
                if (eqMatch) {
                    return String(item[eqMatch[1]]) === eqMatch[2];
                }
                // Handle: contains(Field,'Value')
                const containsMatch = filter.match(/contains\((\w+),'([^']+)'\)/);
                if (containsMatch) {
                    return String(item[containsMatch[1]] || '').includes(containsMatch[2]);
                }
                // Handle: startswith(Field,'Value')
                const startsMatch = filter.match(/startswith\((\w+),'([^']+)'\)/);
                if (startsMatch) {
                    return String(item[startsMatch[1]] || '').startsWith(startsMatch[2]);
                }
                return true;
            });
        });
    }

    // $select (project fields)
    if (query.$select) {
        const fields = query.$select.split(',').map(f => f.trim());
        result = result.map(item => {
            const projected = {};
            fields.forEach(f => {
                if (item[f] !== undefined) projected[f] = item[f];
            });
            return projected;
        });
    }

    const total = result.length;

    // $skip
    const skip = parseInt(query.$skip) || 0;
    result = result.slice(skip);

    // $top (max 20, SAP hard limit)
    const top = Math.min(parseInt(query.$top) || 20, 20);
    result = result.slice(0, top);

    return { value: result, 'odata.count': total };
}

// ─── Express App ───────────────────────────────────────────────────────────────

function createApp() {
    const app = express();
    app.use(express.json({ limit: '10mb' }));

    // Request logging middleware
    app.use((req, res, next) => {
        if (!req.path.startsWith('/__test__')) {
            state.requestLog.push({
                method: req.method,
                path: req.path,
                query: req.query,
                body: req.body,
                timestamp: Date.now(),
            });
        }
        next();
    });

    // Failure simulation middleware
    app.use((req, res, next) => {
        if (req.path.startsWith('/__test__') || req.path === '/b1s/v1/Login') {
            return next();
        }

        if (state.failMode === 'timeout') {
            // Don't respond — simulate timeout
            return;
        }

        if (state.failMode === 'error') {
            return res.status(500).json({
                error: { code: -1, message: { value: 'Simulated SAP error' } },
            });
        }

        if (state.failMode === 'intermittent') {
            state.failCounter++;
            if (state.failCounter > state.failAfterN) {
                state.failCounter = 0;
                return res.status(500).json({
                    error: { code: -2, message: { value: 'Intermittent SAP failure' } },
                });
            }
        }

        next();
    });

    // ─── Auth ──────────────────────────────────────────────────────────────────

    app.post('/b1s/v1/Login', (req, res) => {
        const { CompanyDB, UserName, Password } = req.body;
        if (!CompanyDB || !UserName) {
            return res.status(401).json({
                error: { code: -1, message: { value: 'Invalid credentials' } },
            });
        }
        state.sessionId = `mock-session-${Date.now()}`;
        res.json({ SessionId: state.sessionId });
    });

    app.post('/b1s/v1/Logout', (req, res) => {
        state.sessionId = null;
        res.status(204).send();
    });

    // ─── Items (Products) ──────────────────────────────────────────────────────

    app.get('/b1s/v1/Items', (req, res) => {
        const result = applyOData(state.items, req.query);
        res.json(result);
    });

    app.get('/b1s/v1/Items(:code)', (req, res) => {
        const item = state.items.find(i => `'${i.ItemCode}'` === req.params.code);
        if (!item) {
            return res.status(404).json({
                error: { code: -1, message: { value: 'Item not found' } },
            });
        }
        res.json(item);
    });

    app.patch('/b1s/v1/Items(:code)', (req, res) => {
        const idx = state.items.findIndex(i => `'${i.ItemCode}'` === req.params.code);
        if (idx === -1) {
            return res.status(404).json({
                error: { code: -1, message: { value: 'Item not found' } },
            });
        }
        state.items[idx] = { ...state.items[idx], ...req.body };
        res.status(204).send();
    });

    // ─── Orders (Sales Orders) ─────────────────────────────────────────────────

    app.get('/b1s/v1/Orders', (req, res) => {
        const result = applyOData(state.orders, req.query);
        res.json(result);
    });

    app.post('/b1s/v1/Orders', (req, res) => {
        const docEntry = nextDocEntry();
        const docNum = nextDocNum();
        const order = {
            DocEntry: docEntry,
            DocNum: docNum,
            ...req.body,
        };
        state.orders.push(order);
        res.status(201).json(order);
    });

    app.get('/b1s/v1/Orders(:id)', (req, res) => {
        const id = parseInt(req.params.id);
        const order = state.orders.find(o => o.DocEntry === id);
        if (!order) {
            return res.status(404).json({
                error: { code: -1, message: { value: 'Order not found' } },
            });
        }
        res.json(order);
    });

    app.patch('/b1s/v1/Orders(:id)', (req, res) => {
        const id = parseInt(req.params.id);
        const idx = state.orders.findIndex(o => o.DocEntry === id);
        if (idx === -1) return res.status(404).json({ error: { message: { value: 'Not found' } } });
        state.orders[idx] = { ...state.orders[idx], ...req.body };
        res.status(204).send();
    });

    // ─── Delivery Notes ────────────────────────────────────────────────────────

    app.post('/b1s/v1/DeliveryNotes', (req, res) => {
        const docEntry = nextDocEntry();
        const docNum = nextDocNum();
        const dn = { DocEntry: docEntry, DocNum: docNum, ...req.body };
        state.deliveryNotes.push(dn);
        res.status(201).json(dn);
    });

    app.get('/b1s/v1/DeliveryNotes', (req, res) => {
        res.json(applyOData(state.deliveryNotes, req.query));
    });

    // ─── Invoices (A/R Invoice) ────────────────────────────────────────────────

    app.post('/b1s/v1/Invoices', (req, res) => {
        const docEntry = nextDocEntry();
        const docNum = nextDocNum();
        const inv = { DocEntry: docEntry, DocNum: docNum, ...req.body };
        state.invoices.push(inv);
        res.status(201).json(inv);
    });

    app.get('/b1s/v1/Invoices', (req, res) => {
        res.json(applyOData(state.invoices, req.query));
    });

    // ─── Credit Memos (A/R Credit Memo) ────────────────────────────────────────

    app.post('/b1s/v1/CreditNotes', (req, res) => {
        const docEntry = nextDocEntry();
        const docNum = nextDocNum();
        const cm = { DocEntry: docEntry, DocNum: docNum, ...req.body };
        state.creditMemos.push(cm);
        res.status(201).json(cm);
    });

    // ─── Down Payment Invoices ─────────────────────────────────────────────────

    app.post('/b1s/v1/DownPayments', (req, res) => {
        const docEntry = nextDocEntry();
        const docNum = nextDocNum();
        const dp = { DocEntry: docEntry, DocNum: docNum, ...req.body };
        state.dpInvoices.push(dp);
        res.status(201).json(dp);
    });

    // ─── Incoming Payments ─────────────────────────────────────────────────────

    app.post('/b1s/v1/IncomingPayments', (req, res) => {
        const docEntry = nextDocEntry();
        const payment = { DocEntry: docEntry, ...req.body };
        state.payments.push(payment);
        res.status(201).json(payment);
    });

    // ─── Business Partners (Customers) ─────────────────────────────────────────

    app.get('/b1s/v1/BusinessPartners', (req, res) => {
        const result = applyOData(state.customers, req.query);
        res.json(result);
    });

    app.post('/b1s/v1/BusinessPartners', (req, res) => {
        const customer = { ...req.body };
        state.customers.push(customer);
        res.status(201).json(customer);
    });

    app.get('/b1s/v1/BusinessPartners(:code)', (req, res) => {
        const customer = state.customers.find(c => `'${c.CardCode}'` === req.params.code);
        if (!customer) {
            return res.status(404).json({
                error: { code: -1, message: { value: 'Business Partner not found' } },
            });
        }
        res.json(customer);
    });

    // ─── Test Control Endpoints ────────────────────────────────────────────────

    // Reset all state
    app.post('/__test__/reset', (req, res) => {
        state = getInitialState();
        res.json({ status: 'reset' });
    });

    // Seed items
    app.post('/__test__/seed/items', (req, res) => {
        const items = Array.isArray(req.body) ? req.body : [req.body];
        state.items.push(...items);
        res.json({ added: items.length, total: state.items.length });
    });

    // Seed customers
    app.post('/__test__/seed/customers', (req, res) => {
        const customers = Array.isArray(req.body) ? req.body : [req.body];
        state.customers.push(...customers);
        res.json({ added: customers.length, total: state.customers.length });
    });

    // Get current state (for assertions)
    app.get('/__test__/state', (req, res) => {
        res.json({
            items: state.items.length,
            orders: state.orders.length,
            deliveryNotes: state.deliveryNotes.length,
            invoices: state.invoices.length,
            creditMemos: state.creditMemos.length,
            dpInvoices: state.dpInvoices.length,
            payments: state.payments.length,
            customers: state.customers.length,
            requestLog: state.requestLog.length,
        });
    });

    // Get orders (for test assertions)
    app.get('/__test__/orders', (req, res) => {
        res.json(state.orders);
    });

    // Get delivery notes
    app.get('/__test__/delivery-notes', (req, res) => {
        res.json(state.deliveryNotes);
    });

    // Get invoices
    app.get('/__test__/invoices', (req, res) => {
        res.json(state.invoices);
    });

    // Get credit memos
    app.get('/__test__/credit-memos', (req, res) => {
        res.json(state.creditMemos);
    });

    // Get payments
    app.get('/__test__/payments', (req, res) => {
        res.json(state.payments);
    });

    // Get request log (for verifying API calls)
    app.get('/__test__/request-log', (req, res) => {
        const { method, path: pathFilter } = req.query;
        let log = state.requestLog;
        if (method) log = log.filter(r => r.method === method.toUpperCase());
        if (pathFilter) log = log.filter(r => r.path.includes(pathFilter));
        res.json(log);
    });

    // Clear request log
    app.post('/__test__/request-log/clear', (req, res) => {
        state.requestLog = [];
        res.json({ status: 'cleared' });
    });

    // Set failure mode
    app.post('/__test__/fail-mode', (req, res) => {
        const { mode, afterN } = req.body;
        state.failMode = mode || null;
        state.failAfterN = afterN || 0;
        state.failCounter = 0;
        res.json({ failMode: state.failMode, failAfterN: state.failAfterN });
    });

    // Update item stock (simulate SAP stock change)
    app.post('/__test__/stock-change', (req, res) => {
        const { itemCode, inStock, committed } = req.body;
        const item = state.items.find(i => i.ItemCode === itemCode);
        if (!item) return res.status(404).json({ error: 'Item not found' });

        if (item.ItemWarehouseInfoCollection) {
            item.ItemWarehouseInfoCollection.forEach(wh => {
                if (wh.WarehouseCode === (req.body.warehouse || 'WEB-GEN')) {
                    if (inStock !== undefined) wh.InStock = inStock;
                    if (committed !== undefined) wh.Committed = committed;
                }
            });
        }
        res.json({ itemCode, updated: true });
    });

    return app;
}

// ─── Server Lifecycle ──────────────────────────────────────────────────────────

function startServer(port = 3001) {
    return new Promise((resolve, reject) => {
        const app = createApp();
        const server = app.listen(port, () => {
            console.log(`✅ SAP Mock Server running on port ${port}`);
            resolve(server);
        });
        server.on('error', reject);
    });
}

// Allow standalone execution
if (require.main === module) {
    const port = Number(process.env.SAP_MOCK_PORT) || 3001;
    startServer(port).catch(err => {
        console.error('Failed to start mock server:', err);
        process.exit(1);
    });
}

module.exports = { startServer, createApp };
