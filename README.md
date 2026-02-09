# SAP WooCommerce Sync

[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue.svg)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0%2B-purple.svg)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](LICENSE)
[![SAP](https://img.shields.io/badge/SAP-Business%20One-0FAAFF.svg)](https://www.sap.com/products/business-one.html)

**Enterprise-grade, event-driven synchronization between SAP Business One and WooCommerce** with queue-based guaranteed delivery, circuit breaker pattern, and comprehensive admin dashboard.

---

## Features

### Bi-Directional Sync
- **Inventory Sync** (SAP → WooCommerce): Real-time stock level updates
- **Order Sync** (WooCommerce → SAP): Automatic Sales Order creation
- **Customer Sync**: Auto-create SAP Business Partners from WooCommerce customers
- **Product Mapping**: Intelligent 4-strategy matching algorithm

### Enterprise Reliability
- **Guaranteed Delivery Queue**: Persistent database-backed event queue
- **Circuit Breaker Pattern**: Prevents cascading failures when SAP is unavailable
- **Dead Letter Queue**: Captures failed events for manual review and retry
- **Exponential Backoff**: Smart retry with increasing delays (1min → 5min → 15min → 1hr → 2hr)

### Admin Dashboard
- Real-time sync status monitoring
- Product mapping interface with search and filters
- Comprehensive logging with request/response payloads
- One-click manual sync and connection testing

### Developer Friendly
- WP-CLI commands for automation
- Webhook support for real-time events
- Extensible via WordPress filters and actions
- Clean PSR-4 autoloaded codebase

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              WooCommerce Store                               │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐                   │
│  │   Products   │    │    Orders    │    │  Customers   │                   │
│  └──────┬───────┘    └──────┬───────┘    └──────┬───────┘                   │
│         │                   │                   │                            │
│         ▼                   ▼                   ▼                            │
│  ┌────────────────────────────────────────────────────────────────────┐     │
│  │                    SAP WooCommerce Sync Plugin                      │     │
│  │  ┌─────────────────────────────────────────────────────────────┐   │     │
│  │  │                      Event Queue                             │   │     │
│  │  │  ┌─────────┐  ┌─────────────┐  ┌──────────────────────┐     │   │     │
│  │  │  │ Pending │──│ Processing  │──│ Completed/Dead Letter│     │   │     │
│  │  │  └─────────┘  └─────────────┘  └──────────────────────┘     │   │     │
│  │  └─────────────────────────────────────────────────────────────┘   │     │
│  │                              │                                      │     │
│  │  ┌───────────────────────────┴───────────────────────────┐         │     │
│  │  │                   Circuit Breaker                      │         │     │
│  │  │         CLOSED ←──→ OPEN ←──→ HALF-OPEN               │         │     │
│  │  └───────────────────────────────────────────────────────┘         │     │
│  │                              │                                      │     │
│  │  ┌───────────────────────────┴───────────────────────────┐         │     │
│  │  │                    SAP Client                          │         │     │
│  │  │  • Session Management  • Request Caching               │         │     │
│  │  │  • Auto-Retry          • Error Handling                │         │     │
│  │  └───────────────────────────────────────────────────────┘         │     │
│  └────────────────────────────────────────────────────────────────────┘     │
│                                    │                                         │
└────────────────────────────────────┼─────────────────────────────────────────┘
                                     │ HTTPS
                                     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         SAP Business One                                     │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐                   │
│  │    Items     │    │ Sales Orders │    │  Customers   │                   │
│  └──────────────┘    └──────────────┘    └──────────────┘                   │
│                                                                              │
│  Service Layer REST API (OData v2)                                          │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Data Flow

```
SAP → WooCommerce (Inventory)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
1. Cron triggers inventory sync (every 5 minutes)
2. Fetch Items with warehouse stock from SAP
3. Calculate: Available = InStock - Committed
4. Update WooCommerce product stock levels
5. Log results and update last sync timestamp

WooCommerce → SAP (Orders)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
1. Order placed in WooCommerce
2. Event added to persistent queue
3. Queue worker processes event:
   a. Create/find SAP Business Partner
   b. Create Sales Order with line items
   c. Create Down Payment Invoice (if prepaid)
4. Update order meta with SAP document references
5. On failure: retry with exponential backoff
6. After max retries: move to dead letter queue
```

### Product Matching Pipeline

```
┌─────────────────────────────────────────────────────────────────┐
│                    Product Matching Pipeline                     │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  WooCommerce Product                    SAP Item                 │
│  ┌────────────────┐                    ┌────────────────┐       │
│  │ SKU: ABC-123   │                    │ ItemCode: ABC123│       │
│  │ Name: Widget X │                    │ ItemName: WidgetX│      │
│  │ Barcode: 123.. │                    │ Barcode: 12345..│       │
│  └───────┬────────┘                    └────────┬────────┘       │
│          │                                      │                │
│          └──────────────┬───────────────────────┘                │
│                         ▼                                        │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ Strategy 1: SKU Match (Exact)                           │    │
│  │ WC SKU === SAP ItemCode                                 │────┼──→ Match!
│  └─────────────────────────────────────────────────────────┘    │
│                         │ No Match                               │
│                         ▼                                        │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ Strategy 2: Barcode Match                               │    │
│  │ WC SKU or Meta === SAP Barcode                          │────┼──→ Match!
│  └─────────────────────────────────────────────────────────┘    │
│                         │ No Match                               │
│                         ▼                                        │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ Strategy 3: Title Match (Normalized)                    │    │
│  │ normalize(WC Name) === normalize(SAP Name)              │────┼──→ Match!
│  └─────────────────────────────────────────────────────────┘    │
│                         │ No Match                               │
│                         ▼                                        │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ Strategy 4: Fuzzy Title Match (85% threshold)           │    │
│  │ similar_text(WC Name, SAP Name) >= 85%                  │────┼──→ Match!
│  └─────────────────────────────────────────────────────────┘    │
│                         │ No Match                               │
│                         ▼                                        │
│                    Unmapped Product                              │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## Requirements

| Requirement | Version |
|-------------|---------|
| WordPress | 5.8+ |
| WooCommerce | 5.0+ |
| PHP | 7.4+ |
| SAP Business One | 9.3+ (Service Layer) |
| MySQL | 5.7+ / MariaDB 10.2+ |

---

## Installation

### 1. Download and Install

```bash
# Clone the repository
git clone https://github.com/rasandilikshana/sap-woocommerce-sync.git

# Or download and extract to wp-content/plugins/
```

Upload to `wp-content/plugins/sap-woocommerce-sync/` and activate via WordPress admin.

### 2. Configure Encryption Key

Add to your `wp-config.php` (generate with `openssl rand -hex 32`):

```php
define('SAP_WC_ENCRYPTION_KEY', 'your-64-character-hex-string-here');
```

### 3. Configure SAP Connection

Navigate to **WooCommerce → SAP Sync** and enter:

| Setting | Description | Example |
|---------|-------------|---------|
| SAP Service Layer URL | Full URL to SAP B1 Service Layer | `https://sap.example.com:50000/b1s/v2/` |
| Company Database | SAP company database name | `SBODemoUS` |
| Username | SAP B1 user with API access | `manager` |
| Password | SAP B1 password (encrypted at rest) | `********` |
| Default Warehouse | Warehouse code for stock sync | `01` |

### 4. Test Connection

Click **Test Connection** to verify SAP connectivity.

### 5. Initial Product Mapping

```bash
# Via WP-CLI (recommended for large catalogs)
wp sap-sync map-products

# Or via admin UI: WooCommerce → SAP Sync → Map Products
```

---

## Configuration

### SAP Document Settings

| Setting | Description | Default |
|---------|-------------|---------|
| Default Warehouse | Stock source warehouse | `01` |
| Shipping Item Code | SAP item for shipping charges | (configure in settings) |
| Shipping Tax Code | Tax code for shipping | `VAT` |
| Credit Card Account | G/L account for online payments | (configure in settings) |

### Sync Behavior

| Setting | Description | Default |
|---------|-------------|---------|
| Enable Inventory Sync | Pull stock from SAP | Enabled |
| Enable Order Sync | Push orders to SAP | Enabled |
| Enable Webhooks | Accept SAP webhook events | Disabled |
| Immediate Sync | Process queue immediately after order | Enabled |
| Log Retention | Days to keep sync logs | 30 |

### Payment Method Mapping

The plugin automatically maps WooCommerce payment methods to SAP:

| WooCommerce | SAP Payment Method |
|-------------|-------------------|
| `cod` | Cash On Delivery |
| `bacs` | Bank Transfer |
| `cheque` | Cheque |
| `paypal`, `stripe`, `square` | Online Transfer |

Extend via filter:

```php
add_filter('sap_wc_payment_method_map', function($map) {
    $map['my_gateway'] = 'Custom Payment';
    return $map;
});
```

---

## WP-CLI Commands

```bash
# Map WooCommerce products to SAP items
wp sap-sync map-products

# Sync inventory from SAP
wp sap-sync sync-inventory

# Process event queue
wp sap-sync process-queue

# Check sync status
wp sap-sync status

# Retry failed events from dead letter queue
wp sap-sync retry-dead-letters
```

---

## Webhooks

The plugin exposes a webhook endpoint for real-time SAP events:

```
POST /wp-json/sap-wc/v1/webhook
```

### Supported Events

| Event | Description |
|-------|-------------|
| `item.stock_changed` | Inventory level changed |
| `item.updated` | Item details modified |
| `order.status_changed` | SAP order status update |

### Authentication

Webhooks are authenticated via HMAC-SHA256 signature in the `X-SAP-Signature` header:

```php
$signature = hash_hmac('sha256', $payload, $webhook_secret);
```

Configure the webhook secret in **WooCommerce → SAP Sync → Webhook Settings**.

---

## Database Schema

The plugin creates 7 tables:

| Table | Purpose |
|-------|---------|
| `wp_sap_wc_product_map` | Product ↔ SAP Item mappings |
| `wp_sap_wc_order_map` | Order ↔ SAP Document mappings |
| `wp_sap_wc_customer_map` | Customer ↔ SAP Business Partner mappings |
| `wp_sap_wc_sync_log` | Activity and error logs |
| `wp_sap_wc_event_queue` | Pending sync events |
| `wp_sap_wc_dead_letter_queue` | Failed events for review |
| `wp_sap_wc_migrations` | Database version tracking |

---

## Hooks & Filters

### Actions

```php
// After successful order sync to SAP
do_action('sap_wc_order_synced', $order_id, $sap_doc_entry);

// After inventory sync completes
do_action('sap_wc_inventory_synced', $updated_count, $skipped_count);

// Before SAP API request
do_action('sap_wc_before_api_request', $endpoint, $method, $data);
```

### Filters

```php
// Modify SAP order data before creation
add_filter('sap_wc_order_data', function($data, $order) {
    $data['Comments'] = 'Custom comment';
    return $data;
}, 10, 2);

// Customize payment method mapping
add_filter('sap_wc_payment_method_map', function($map) {
    $map['custom_gateway'] = 'Custom Payment';
    return $map;
});

// Modify SAP API timeout
add_filter('sap_wc_api_timeout', function($timeout) {
    return 60; // seconds
});
```

---

## Troubleshooting

### Connection Issues

1. **SSL Certificate Errors**
   ```php
   // Add to wp-config.php (development only!)
   define('SAP_WC_SSL_VERIFY', false);
   ```

2. **Session Timeout**
   - The plugin auto-refreshes sessions at 80% of timeout
   - Check SAP Service Layer session settings

3. **Rate Limiting**
   - Built-in rate limiting: 5 connection tests/minute
   - Reduce manual sync frequency if hitting limits

### Sync Issues

1. **Products Not Mapping**
   - Check SKU format matches SAP ItemCode
   - Review fuzzy match threshold (default: 85%)
   - Use admin UI to manually map products

2. **Orders Failing**
   - Check Logs page for detailed error messages
   - Verify customer data (address, phone, email)
   - Ensure SAP user has document creation rights

3. **Stock Not Updating**
   - Verify warehouse code matches SAP
   - Check product is mapped to SAP item
   - Review cron schedule: `wp cron event list`

### Circuit Breaker

When SAP is unavailable, the circuit breaker protects your site:

| State | Behavior |
|-------|----------|
| CLOSED | Normal operation |
| OPEN | All requests fail fast (30s cooldown) |
| HALF-OPEN | Single test request allowed |

Check circuit state in **WooCommerce → SAP Sync → Status**.

---

## Security

- **Credentials**: AES-256-CBC encrypted at rest
- **Webhooks**: HMAC-SHA256 signature verification
- **Admin Access**: Requires `manage_woocommerce` capability
- **Rate Limiting**: Prevents brute force and DoS
- **SQL Injection**: All queries use prepared statements
- **XSS Prevention**: All output escaped

---

## Contributing

Contributions are welcome! Please read [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

### Development Setup

```bash
# Clone repository
git clone https://github.com/rasandilikshana/sap-woocommerce-sync.git

# Install test dependencies
cd sap-woocommerce-sync
npm install

# Copy environment template
cp .env.example .env
# Edit .env with your test environment details

# Run E2E tests
npm test
```

---

## License

This project is licensed under the GPL-2.0-or-later License - see the [LICENSE](LICENSE) file for details.

---

## Support

- **Issues**: [GitHub Issues](https://github.com/rasandilikshana/sap-woocommerce-sync/issues)
- **Discussions**: [GitHub Discussions](https://github.com/rasandilikshana/sap-woocommerce-sync/discussions)

---

## Acknowledgments

- Built with [WordPress](https://wordpress.org/) and [WooCommerce](https://woocommerce.com/)
- SAP Business One Service Layer REST API
- Inspired by enterprise integration patterns

---

**Made with dedication for the WooCommerce + SAP community**
