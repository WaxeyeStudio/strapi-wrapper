# Design Document: Comprehensive Error Handling

## Overview

This design enhances the error handling system in the Strapi Wrapper package to provide better debugging capabilities through contextual logging and appropriate error severity classification. The current implementation has several issues:

1. Inconsistent context logging - some methods include URL/status, others don't
2. All errors logged at "error" level regardless of HTTP status code
3. UnknownError used as a catch-all even when specific error information is available
4. Missing request context (method, headers, body) in error logs
5. Sensitive data (auth tokens) not sanitized from logs

The enhanced system will ensure developers have complete request/response context for debugging while maintaining appropriate log levels based on error severity.

## Architecture

### Error Handling Flow

```
HTTP Request → executeWithRetry() → Response Handler → Error Classification → Logging → Exception Throw
                                                              ↓
                                                    Context Builder
                                                    (URL, Method, Status, Body)
                                                              ↓
                                                    Sanitizer
                                                    (Remove sensitive data)
                                                              ↓
                                                    Logger
                                                    (Appropriate level)
```

### Key Principles

1. **Context-First Logging**: Every error log must include full request context
2. **Severity-Based Logging**: 5xx = error level, 4xx/3xx = debug level, 2xx = no error log
3. **Specific Over Generic**: Extract and use Strapi's error messages instead of "Unknown error"
4. **Security-Aware**: Sanitize authentication tokens and sensitive data from logs
5. **Consistent Structure**: All error logs follow the same context structure

## Components and Interfaces

### 1. Request Context Builder

A new protected method that builds comprehensive request context for logging:

```php
protected function buildRequestContext(
    string $method,
    string $url,
    ?Response $response = null,
    ?array $requestBody = null,
    ?array $additionalContext = []
): array
```

**Responsibilities:**
- Capture HTTP method, URL, and status code
- Include request body (sanitized)
- Include response body (truncated if needed)
- Add timestamp and additional context
- Return structured array for logging

**Context Structure:**
```php
[
    'timestamp' => '2025-11-19T10:30:45Z',
    'method' => 'POST',
    'url' => 'https://api.example.com/api/articles',
    'status' => 400,
    'request_body' => [...], // Sanitized
    'response_body' => '...', // Truncated if > 1000 chars
    'response_size' => 1234,
    'auth_method' => 'token',
    ...additionalContext
]
```

### 2. Data Sanitizer

A new protected method that removes sensitive information from log context:

```php
protected function sanitizeLogData(array $data): array
```

**Responsibilities:**
- Remove or mask authentication tokens
- Remove or mask passwords
- Remove or mask API keys
- Preserve structure for debugging
- Handle nested arrays recursively

**Sanitization Rules:**
- Keys matching 'token', 'jwt', 'authorization', 'password', 'secret' → Replace value with '[REDACTED]'
- Bearer tokens in headers → Mask all but last 4 characters
- Preserve all other data for debugging

### 3. Enhanced Logging Method

Enhance the existing `log()` method to handle context building and severity determination:

```php
protected function logError(
    string $message,
    string $method,
    string $url,
    ?Response $response = null,
    ?array $requestBody = null,
    ?array $additionalContext = []
): void
```

**Responsibilities:**
- Build request context using buildRequestContext()
- Sanitize context using sanitizeLogData()
- Determine appropriate log level based on status code
- Call Laravel Log facade with appropriate level
- Only log if debug logging is enabled

**Log Level Determination:**
- Status >= 500: 'error'
- Status 400-499: 'debug'
- Status 300-399: 'debug'
- Status < 300: No logging (success)

### 4. Error Message Extractor

A new protected method that extracts meaningful error messages from Strapi responses:

```php
protected function extractErrorMessage(Response $response, string $fallback = 'Unknown error'): string
```

**Responsibilities:**
- Parse JSON response body
- Extract error message from Strapi's error structure
- Handle multiple error formats (v4 vs v5)
- Combine multiple errors if present
- Return fallback only if no message found

**Strapi Error Formats:**

V4/V5 format:
```json
{
  "error": {
    "status": 400,
    "name": "ValidationError",
    "message": "Invalid data provided",
    "details": {...}
  }
}
```

Multiple errors format:
```json
{
  "error": {
    "message": "Multiple validation errors",
    "details": {
      "errors": [
        {"message": "Title is required"},
        {"message": "Content is too short"}
      ]
    }
  }
}
```

**Extraction Logic:**
1. Try to parse response as JSON
2. Look for `error.message` field
3. If multiple errors in `error.details.errors`, combine them
4. If no structured error, use raw response body (truncated)
5. Only use fallback if response is empty or unparseable

### 5. Exception Updates

Update all exception classes to accept and store request context:

**BaseException Enhancement:**
- Already has `$context` property and `getContext()` method
- No changes needed to BaseException

**Specific Exception Updates:**
- Update constructors to accept context parameter
- Pass context to parent BaseException
- Remove individual Log calls from exception constructors
- Logging will be handled before exception is thrown

## Data Models

### Request Context Model

```php
[
    'timestamp' => string,      // ISO 8601 timestamp
    'method' => string,         // HTTP method (GET, POST, etc.)
    'url' => string,           // Full request URL
    'status' => int|null,      // HTTP status code
    'request_body' => array|null,  // Sanitized request body
    'response_body' => string|null, // Response body (truncated)
    'response_size' => int|null,    // Original response size in bytes
    'auth_method' => string,    // Authentication method used
    'attempt' => int|null,      // Retry attempt number (if applicable)
    'max_attempts' => int|null, // Max retry attempts (if applicable)
]
```

### Sanitized Data Model

Same structure as input, but with sensitive values replaced:
```php
[
    'token' => '[REDACTED]',
    'password' => '[REDACTED]',
    'authorization' => 'Bearer ****xyz123',
    'other_field' => 'original value'
]
```

## Error Handling

### Error Classification

| Status Code Range | Log Level | Exception Type | Use Case |
|------------------|-----------|----------------|----------|
| 200-299 | none | none | Success |
| 300-399 | debug | none | Redirects (rare in API) |
| 400 | debug | BadRequest | Invalid request data |
| 401, 403 | debug | PermissionDenied | Authentication/authorization failure |
| 404 | debug | NotFoundError | Resource not found |
| 429 | warning | (retry) | Rate limiting - handled by retry logic |
| 500-599 | error | UnknownError | Server errors |
| Connection errors | error | ConnectionError | Network failures |

### Error Message Priority

When constructing error messages, use this priority:

1. **Extracted Strapi message** - Use extractErrorMessage() to get specific error from response
2. **HTTP status + URL** - Include for context
3. **"Unknown error"** - Only if response is empty AND status is 500+

### Logging Strategy

**Before Request:**
- Log at 'debug' level with method, URL, auth method
- Only if debug logging enabled

**After Successful Request:**
- Log at 'debug' level with method, URL, status, response size
- Only if debug logging enabled

**After Failed Request:**
- Build full request context
- Sanitize sensitive data
- Determine log level based on status code
- Log with appropriate level
- Throw appropriate exception with context

## Testing Strategy

### Unit Tests

1. **Context Builder Tests**
   - Test buildRequestContext() with various inputs
   - Verify all fields are populated correctly
   - Test with null response
   - Test with null request body

2. **Sanitizer Tests**
   - Test sanitizeLogData() removes tokens
   - Test sanitizeLogData() removes passwords
   - Test sanitizeLogData() handles nested arrays
   - Test sanitizeLogData() preserves non-sensitive data
   - Test Bearer token masking

3. **Error Message Extractor Tests**
   - Test extractErrorMessage() with V4 format
   - Test extractErrorMessage() with V5 format
   - Test extractErrorMessage() with multiple errors
   - Test extractErrorMessage() with empty response
   - Test extractErrorMessage() with non-JSON response
   - Test extractErrorMessage() with fallback

4. **Log Level Tests**
   - Test 500+ errors log at 'error' level
   - Test 400-499 errors log at 'debug' level
   - Test 300-399 responses log at 'debug' level
   - Test 200-299 responses don't log errors

5. **Integration Tests**
   - Test full request flow with mocked Strapi responses
   - Verify context is passed to exceptions
   - Verify sensitive data is sanitized in logs
   - Test retry logic still works with new logging

### Test Data

Mock Strapi error responses for different scenarios:
- 400 with validation error
- 401 with authentication error
- 404 with not found error
- 500 with server error
- 500 with empty body
- Connection timeout
- Multiple validation errors

## Implementation Notes

### Backward Compatibility

- All changes are internal to StrapiWrapper class
- Public API remains unchanged
- Exception types remain the same
- Existing exception handling code will continue to work
- New context data available via `getContext()` on exceptions

### Configuration

No new configuration needed. Uses existing `log_enabled` setting.

### Performance Considerations

- Context building only happens on errors (not success path)
- Sanitization is shallow (one level deep) for performance
- Response body truncation prevents memory issues
- No additional HTTP requests or external calls

### Migration Path

1. Update BaseException and specific exceptions (already support context)
2. Add new helper methods (buildRequestContext, sanitizeLogData, extractErrorMessage)
3. Update existing error handling in getRequestActual()
4. Update existing error handling in postRequest()
5. Update existing error handling in postMultipartRequest()
6. Update existing error handling in loginStrapi()
7. Update retry logic logging in executeWithRetry()
8. Remove individual Log calls from exception constructors

### Edge Cases

1. **Response body is binary data**: Truncate and indicate "[Binary data]"
2. **Response body is extremely large**: Truncate at 1000 characters with "[truncated]" indicator
3. **JSON parsing fails**: Use raw response body as error message
4. **Multiple nested errors**: Flatten and combine with semicolons
5. **No response (connection error)**: Context will have null response fields
6. **Retry attempts**: Include attempt number in context for retry scenarios

## Security Considerations

1. **Token Sanitization**: All authentication tokens must be redacted or masked
2. **Password Protection**: Passwords never logged, even in sanitized form
3. **PII Protection**: Consider sanitizing email addresses and user identifiers
4. **Log Storage**: Ensure logs are stored securely and access is restricted
5. **Debug Mode**: Sanitization applies even when debug logging is enabled

## Future Enhancements

1. **Structured Logging**: Consider JSON-formatted logs for better parsing
2. **Log Aggregation**: Add correlation IDs for tracking requests across services
3. **Metrics**: Track error rates by status code and endpoint
4. **Alerting**: Integrate with monitoring systems for 500-level errors
5. **Request ID**: Add unique request ID to all logs for tracing
