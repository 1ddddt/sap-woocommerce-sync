# Changelog

All notable changes to SAP WooCommerce Sync will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.3] - 2024-02-09

### Added
- Immediate order sync option via AJAX for faster processing
- Shipping charges sync to SAP orders
- Comprehensive E2E test suite with SAP mock server
- Circuit breaker pattern for SAP failure protection
- Dead letter queue for failed events
- HPOS (High-Performance Order Storage) compatibility

### Changed
- Complete architecture rewrite from v1
- Improved product matching with 4-strategy pipeline
- Enhanced error handling with typed exceptions
- Better logging with request/response payloads

### Fixed
- Session timeout issues with auto-refresh
- Race conditions in queue processing
- Stock calculation formula (InStock - Committed)

## [2.0.2] - Initial Release

### Added
- Event-driven synchronization architecture
- Bi-directional sync (SAP ↔ WooCommerce)
- Product mapping with multiple strategies
- Order sync (COD and Prepaid)
- Customer auto-creation
- Inventory sync with warehouse support
- Webhook support for real-time events
- WP-CLI commands
- Admin dashboard with monitoring
- Comprehensive logging system
- AES-256-CBC encryption for credentials
- Rate limiting protection

### Security
- HMAC-SHA256 webhook authentication
- SQL injection prevention
- XSS protection
- Nonce verification on forms

## [Unreleased]

### Planned
- Variable product support
- Multi-currency handling
- Multi-warehouse UI selection
- Bulk operations integration
- WooCommerce Subscriptions support

---

## Version History

- **2.0.x**: Complete rewrite with enterprise patterns
- **1.x**: Initial implementation (legacy)

## Migration Notes

### From v1 to v2
The v2 release is a complete rewrite. Key differences:
- New database schema (7 tables vs 3)
- Event queue system replaces direct API calls
- Circuit breaker adds resilience
- Improved product matching algorithms
- Enhanced security (encryption, rate limiting)

**Migration is not automatic.** You will need to:
1. Backup your database
2. Deactivate v1
3. Install v2
4. Reconfigure SAP connection
5. Re-map products
6. Initial inventory sync

---

For detailed release notes, see [GitHub Releases](https://github.com/rasandilikshana/sap-woocommerce-sync/releases).
