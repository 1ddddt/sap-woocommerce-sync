# Contributing to SAP WooCommerce Sync

Thank you for your interest in contributing to SAP WooCommerce Sync! This document provides guidelines and instructions for contributing.

## Code of Conduct

Be respectful, constructive, and professional. We're all here to make this plugin better for the community.

## How Can I Contribute?

### Reporting Bugs

Before creating a bug report, please check existing issues to avoid duplicates.

**When creating a bug report, include:**
- Clear, descriptive title
- Step-by-step reproduction instructions
- Expected vs actual behavior
- Screenshots (if applicable)
- Environment details:
  - WordPress version
  - WooCommerce version
  - PHP version
  - SAP Business One version
  - Plugin version

**Template:**
```markdown
**Describe the bug**
A clear description of what the bug is.

**To Reproduce**
Steps to reproduce:
1. Go to '...'
2. Click on '...'
3. See error

**Expected behavior**
What you expected to happen.

**Environment:**
- WordPress: 6.4
- WooCommerce: 8.5
- PHP: 8.1
- SAP B1: 10.0
- Plugin: 2.0.3

**Additional context**
Any other relevant information.
```

### Suggesting Features

Feature requests are welcome! Please:
- Use a clear, descriptive title
- Provide detailed explanation of the feature
- Explain why this would be useful
- Include examples of how it would work

### Pull Requests

**Before submitting:**
1. Fork the repository
2. Create a new branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Test thoroughly
5. Commit with clear messages
6. Push to your fork
7. Open a Pull Request

**PR Guidelines:**
- Follow existing code style
- Add/update tests if applicable
- Update documentation
- Keep changes focused (one feature/fix per PR)
- Reference related issues

**Code Style:**
- Follow WordPress Coding Standards
- Use PSR-4 autoloading conventions
- Add PHPDoc blocks for classes and methods
- Use meaningful variable names
- Keep functions focused and small

## Development Setup

### Prerequisites
- WordPress development environment
- WooCommerce installed
- PHP 7.4+
- Node.js (for running tests)
- SAP Business One test instance (or mock server)

### Local Setup

```bash
# Clone your fork
git clone https://github.com/YOUR-USERNAME/sap-woocommerce-sync.git
cd sap-woocommerce-sync

# Install test dependencies
npm install

# Copy environment template
cp .env.example .env
# Edit .env with your test environment details

# Create encryption key
echo "define('SAP_WC_ENCRYPTION_KEY', '$(openssl rand -hex 32)');" >> wp-config.php
```

### Running Tests

```bash
# Run all E2E tests
npm test

# Run specific test
npm run test:spec -- tests/e2e/specs/03-product-mapping.spec.js

# Run with browser visible
npm run test:headed
```

### Testing Guidelines

**When adding features:**
- Write E2E tests for user-facing functionality
- Test edge cases
- Verify error handling

**Test coverage should include:**
- Happy path scenarios
- Error conditions
- Edge cases
- SAP API failures
- Database failures

## Architecture Guidelines

### Code Organization

```
sap-woocommerce-sync/
├── includes/
│   ├── admin/           # Admin pages and UI
│   ├── api/             # SAP API client
│   ├── cli/             # WP-CLI commands
│   ├── constants/       # Configuration constants
│   ├── exceptions/      # Custom exceptions
│   ├── handlers/        # WordPress hook handlers
│   ├── helpers/         # Utility classes
│   ├── interfaces/      # Interface contracts
│   ├── queue/           # Event queue system
│   ├── repositories/    # Data access layer
│   ├── security/        # Encryption, rate limiting
│   ├── sync/            # Sync logic
│   ├── validation/      # Input validation
│   └── webhooks/        # Webhook handling
├── assets/              # JS, CSS
├── templates/           # PHP templates
└── tests/               # E2E tests
```

### Design Patterns

This plugin uses several design patterns:
- **Repository Pattern**: Data access abstraction
- **Strategy Pattern**: Product matching algorithms
- **Circuit Breaker**: SAP failure protection
- **Singleton**: Plugin instance management
- **Observer**: WordPress hooks

### Best Practices

**Database Access:**
```php
// ✅ Good: Use repositories
$repo = new Product_Map_Repository();
$mapping = $repo->find_by_wc_id($product_id);

// ❌ Bad: Direct $wpdb usage in business logic
global $wpdb;
$wpdb->get_results("SELECT * FROM ...");
```

**Error Handling:**
```php
// ✅ Good: Use typed exceptions
throw new SAP_API_Exception('Connection failed', $response);

// ❌ Bad: Generic exceptions
throw new Exception('Error');
```

**Configuration:**
```php
// ✅ Good: Use Config class
$warehouse = Config::warehouse();

// ❌ Bad: Hardcoded values
$warehouse = 'WEB-GEN';
```

**Logging:**
```php
// ✅ Good: Structured logging
$logger->error('Order sync failed', [
    'entity_type' => 'order',
    'entity_id' => $order_id,
    'error' => $e->getMessage(),
]);

// ❌ Bad: Unstructured logging
error_log('Order failed');
```

## Security Guidelines

- **Never** commit credentials or API keys
- **Always** sanitize user input
- **Always** escape output
- **Always** use nonces for forms
- **Always** check user capabilities
- Use prepared statements for database queries
- Encrypt sensitive data (passwords, API keys)

## Documentation

When adding features:
- Update README.md if user-facing
- Add PHPDoc blocks to classes/methods
- Document filters and actions
- Update CHANGELOG.md

## Git Commit Messages

Write clear, descriptive commit messages:

```
feat: Add support for variable products

- Implement variation sync to SAP
- Add variation mapping UI
- Update product matcher for variations

Closes #123
```

**Commit prefixes:**
- `feat:` New feature
- `fix:` Bug fix
- `docs:` Documentation changes
- `style:` Code style changes (formatting)
- `refactor:` Code refactoring
- `test:` Adding/updating tests
- `chore:` Maintenance tasks

## Release Process

Maintainers handle releases:
1. Update version in `sap-wc-sync.php` and `class-config.php`
2. Update CHANGELOG.md
3. Create git tag
4. Create GitHub release
5. Optional: Submit to WordPress.org (future)

## Questions?

- Open a GitHub Discussion
- Tag issues with `question` label
- Check existing documentation first

## License

By contributing, you agree that your contributions will be licensed under GPL-2.0-or-later.

---

**Thank you for contributing to SAP WooCommerce Sync!**
