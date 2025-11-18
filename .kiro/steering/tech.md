# Technology Stack

## Language & Framework

- PHP 8.1, 8.2, or 8.3
- Laravel package (uses Spatie Laravel Package Tools)
- Designed as a Laravel service provider

## Dependencies

- **guzzlehttp/guzzle**: HTTP client for API requests
- **spatie/laravel-package-tools**: Package scaffolding and configuration
- **Laravel HTTP Client**: Wrapper around Guzzle for requests

## Development Dependencies

- **Pest**: Testing framework (preferred over PHPUnit)
- **Orchestra Testbench**: Laravel package testing
- **Spatie Laravel Ray**: Debugging tool

## Build & Test Commands

```bash
# Install dependencies
composer install

# Run tests
composer test
# or
vendor/bin/pest

# Run tests with coverage
composer test-coverage

# Static analysis (if configured)
composer analyse
```

## Configuration

Package configuration published to `config/strapi-wrapper.php` with environment variables:
- `STRAPI_URL`: Strapi API endpoint
- `STRAPI_AUTH`: Authentication method (public/password/token)
- `STRAPI_VERSION`: API version (4 or 5)
- `STRAPI_CACHE`: Cache timeout in seconds
- Additional auth, timeout, and SSL verification settings
