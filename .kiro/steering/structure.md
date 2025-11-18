# Project Structure

## Directory Organization

```
src/
├── Commands/          # Artisan console commands
├── Exceptions/        # Custom exception classes for error handling
├── Facades/           # Laravel facade for StrapiWrapper
├── StrapiCollection.php    # Main query builder for collections
├── StrapiField.php         # Field filtering and querying
├── StrapiFilter.php        # Filter operations
├── StrapiUploads.php       # File upload handling
├── StrapiWrapper.php       # Base class with HTTP client logic
└── StrapiWrapperServiceProvider.php  # Laravel service provider

config/
└── strapi-wrapper.php      # Package configuration file

tests/
├── Pest.php                # Pest configuration and example tests
└── TestCase.php            # Base test case
```

## Architecture Patterns

### Inheritance Hierarchy
- `StrapiWrapper` (base): HTTP client, authentication, request handling
- `StrapiCollection` (extends StrapiWrapper): Query building, data transformation
- `StrapiUploads` (extends StrapiWrapper): File upload operations

### Key Classes

**StrapiWrapper**: Core HTTP client handling authentication, token management, request/response processing, and error handling.

**StrapiCollection**: Fluent interface for querying Strapi collections with methods for filtering, sorting, pagination, population, and caching.

**StrapiField**: Represents filterable fields in queries.

**Exceptions**: Custom exception hierarchy (BaseException → specific errors like BadRequest, NotFoundError, PermissionDenied, etc.)

## Code Conventions

- PSR-4 autoloading with `SilentWeb\StrapiWrapper` namespace
- 4 spaces for indentation (see .editorconfig)
- Type hints for method parameters and return types
- Fluent method chaining pattern for query building
- Protected methods for internal logic, public for API
- Caching using Laravel's Cache facade with configurable timeouts
