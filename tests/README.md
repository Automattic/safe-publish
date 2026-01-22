# Compliant Content Publisher - Test Suite

This directory contains comprehensive unit tests for the Compliant Content Publisher WordPress plugin.

> **Note**: JavaScript/TypeScript tests are documented in [src/README.md](src/README.md)

## Test Structure

```
tests/
├── unit/                          # Unit tests (PHPUnit)
│   ├── bootstrap.php             # Test bootstrap and setup
│   ├── stubs.php                 # WordPress function stubs
│   ├── test-utils.php            # Testing utility functions
│   ├── PluginTest.php            # Main plugin class tests
│   ├── URLValidatorTest.php      # URL validation tests
│   ├── VIPSafeAuthTest.php       # Authentication tests
│   ├── HTTPClientTest.php        # HTTP client tests
│   ├── ExternalPostsAPITest.php  # External API tests
│   ├── CCPAPITest.php            # REST API tests
│   └── RESTBaseTest.php          # REST base class tests
├── integration/                   # Integration tests (WP Test Suite)
│   └── bootstrap.php             # Integration test bootstrap
├── e2e/                          # End-to-end tests (Playwright)
│   └── settings/                 # Settings page tests
└── src/                          # JavaScript/TypeScript tests (Vitest)
    ├── vitest.setup.ts           # Test setup and configuration
    ├── utils.test.ts             # Utility function tests
    ├── constants.test.ts         # Constants tests
    ├── types.test.ts             # TypeScript types tests
    ├── actions.test.tsx          # Action modal tests
    └── api/
        └── diff.test.ts          # Diff API tests
```

## Running Tests

### All Unit Tests

```bash
composer test
```

### Unit Tests with Coverage

```bash
composer test-coverage
```

### Specific Test File

```bash
vendor/bin/phpunit tests/unit/PluginTest.php
```

### Specific Test Method

```bash
vendor/bin/phpunit --filter test_plugin_initializes tests/unit/PluginTest.php
```

### Run Tests with Detailed Output

```bash
vendor/bin/phpunit --verbose
```

## Test Coverage

The test suite provides comprehensive coverage for:

### Core Plugin Components

- **Plugin Class** (`PluginTest.php`)
  - Plugin initialization
  - Component instantiation
  - API getter methods

### API & Integration

- **External Posts API** (`ExternalPostsAPITest.php`)
  - Post fetching and validation
  - Post type fetching
  - Connection testing
  - Media import handling
  - WebP support
  - Fresh content fetching

- **CCP REST API** (`CCPAPITest.php`)
  - Serialization/deserialization
  - Data normalization
  - Deep diff functionality
  - Diff preview generation
  - Meta and term updates

- **HTTP Client** (`HTTPClientTest.php`)
  - Request handling
  - SSL verification
  - Authentication integration
  - User agent generation
  - Environment detection

### Security & Validation

- **URL Validator** (`URLValidatorTest.php`)
  - URL format validation
  - HTTPS enforcement (VIP)
  - Domain whitelisting
  - URL sanitization

- **VIP Safe Auth** (`VIPSafeAuthTest.php`)
  - Shared secret authentication
  - HMAC signature generation
  - Authorization validation
  - Secret generation
  - Timestamp verification

- **REST Base** (`RESTBaseTest.php`)
  - Base REST controller functionality
  - Route registration
  - Request handling

## Test Requirements

### PHP Requirements

- PHP 8.1 or higher
- PHPUnit 9.x
- Required PHP extensions:
  - json
  - mbstring
  - libxml
  - dom

### Dependencies

All test dependencies are managed via Composer:

```json
{
  "require-dev": {
    "phpunit/phpunit": "^9",
    "mockery/mockery": "^1.6",
    "wp-phpunit/wp-phpunit": "^6.7",
    "yoast/phpunit-polyfills": "^4.0",
    "php-stubs/wordpress-stubs": "^6.6",
    "php-stubs/wordpress-tests-stubs": "^6.7"
  }
}
```

## Writing New Tests

### Test Class Template

```php
<?php
declare(strict_types=1);

namespace CCP\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test Description
 * Brief description of what this test covers
 */
class MyTest extends TestCase {

    private $subject;

    protected function setUp(): void {
        parent::setUp();
        // Setup test subject
    }

    protected function tearDown(): void {
        // Cleanup
        parent::tearDown();
    }

    public function test_something(): void {
        // Arrange
        // Act
        // Assert
        $this->assertTrue(true);
    }
}
```

### Best Practices

1. **Use Descriptive Test Names**
   - Prefix with `test_`
   - Use snake_case
   - Describe what is being tested
   - Example: `test_url_validator_rejects_invalid_urls`

2. **Follow AAA Pattern**
   - **Arrange**: Set up test data
   - **Act**: Execute the code being tested
   - **Assert**: Verify the results

3. **One Assertion Per Test**
   - Keep tests focused and atomic
   - Makes failures easier to debug
   - Use data providers for multiple cases

4. **Use Type Declarations**
   - Declare return types (`: void`)
   - Use strict types (`declare(strict_types=1)`)

5. **Clean Up Resources**
   - Use `tearDown()` for cleanup
   - Ensure tests don't affect each other

## Continuous Integration

Tests are automatically run on:

- Pull requests
- Commits to main branches
- Pre-deployment checks

### CI Configuration

Tests should pass with:

- No failures
- No errors
- No warnings
- Code coverage > 80%

## Troubleshooting

### Common Issues

**Issue**: Tests fail with "Class not found"

```bash
# Solution: Regenerate autoloader
composer dump-autoload
```

**Issue**: WordPress functions not found

```bash
# Solution: Check stubs.php is loaded in bootstrap.php
```

**Issue**: Coverage not generated

```bash
# Solution: Install xdebug
pecl install xdebug
```

**Issue**: Tests run slowly

```bash
# Solution: Run specific test files or use filters
vendor/bin/phpunit --filter ClassName
```

## Code Coverage

View HTML coverage report:

```bash
composer test-coverage
open coverage/phpunit/html/index.html
```

## Additional Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [WordPress Plugin Unit Tests](https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/)
- [VIP Code Review Standards](https://docs.wpvip.com/technical-references/code-review/)

## Contributing

When adding new features:

1. Write tests first (TDD)
2. Ensure all tests pass
3. Maintain or improve coverage
4. Update this README if needed
