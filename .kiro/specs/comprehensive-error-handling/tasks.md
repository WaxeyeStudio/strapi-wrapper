# Implementation Plan

- [x] 1. Add helper methods for context building and data sanitization
  - [x] 1.1 Implement buildRequestContext() method in StrapiWrapper
    - Create protected method that accepts method, URL, response, request body, and additional context
    - Build structured array with timestamp, method, URL, status, request/response bodies, and auth method
    - Handle null response and null request body cases
    - Truncate response body to 1000 characters if longer, adding "[truncated]" indicator
    - _Requirements: 1.1, 1.2, 1.3, 1.4_
  
  - [x] 1.2 Implement sanitizeLogData() method in StrapiWrapper
    - Create protected method that recursively sanitizes arrays
    - Replace values for keys matching 'token', 'jwt', 'authorization', 'password', 'secret' with '[REDACTED]'
    - Mask Bearer tokens to show only last 4 characters (e.g., 'Bearer ****xyz123')
    - Handle nested arrays recursively
    - Preserve all non-sensitive data
    - _Requirements: 1.5_
  
  - [x] 1.3 Implement extractErrorMessage() method in StrapiWrapper
    - Create protected method that accepts Response and fallback string
    - Parse JSON response body safely (handle parse errors)
    - Extract error message from Strapi v4/v5 format (error.message)
    - Handle multiple errors in error.details.errors array by combining them
    - Return raw response body (truncated) if JSON parsing fails
    - Only return fallback if response is empty AND status is 500+
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5_

- [x] 2. Enhance logging method with severity-based logging
  - [x] 2.1 Create logError() method in StrapiWrapper
    - Create protected method accepting message, method, URL, response, request body, and additional context
    - Call buildRequestContext() to build context
    - Call sanitizeLogData() to sanitize context
    - Determine log level: status >= 500 = 'error', status 400-499 = 'debug', status 300-399 = 'debug'
    - Call Log::log() with determined level, message, and sanitized context
    - Only log if $this->debugLoggingEnabled is true
    - _Requirements: 2.1, 2.2, 2.3, 2.4_

- [ ] 3. Update exception classes to remove internal logging
  - [ ] 3.1 Update BadRequest exception constructor
    - Remove parent constructor's $writeToLog parameter (set to false)
    - Keep context parameter support
    - Logging will be handled before exception is thrown
    - _Requirements: 2.1, 2.2_
  
  - [ ] 3.2 Update PermissionDenied exception constructor
    - Remove parent constructor's $writeToLog parameter (set to false)
    - Keep context parameter support
    - _Requirements: 2.1, 2.2_
  
  - [ ] 3.3 Update NotFoundError exception constructor
    - Remove the Log::debug() call from constructor
    - Remove parent constructor's $writeToLog parameter (set to false)
    - Keep context parameter support
    - _Requirements: 2.3_
  
  - [ ] 3.4 Update UnknownError exception constructor
    - Update to accept context parameter
    - Remove parent constructor's $writeToLog parameter (set to false)
    - Pass context to parent BaseException
    - _Requirements: 2.1_
  
  - [ ] 3.5 Update ConnectionError exception constructor
    - Update to accept context parameter
    - Remove parent constructor's $writeToLog parameter (set to false)
    - Pass context to parent BaseException
    - _Requirements: 2.1_

- [ ] 4. Refactor getRequestActual() error handling
  - [ ] 4.1 Update 400 error handling in getRequestActual()
    - Extract error message using extractErrorMessage()
    - Build context using buildRequestContext()
    - Call logError() with 'debug' level before throwing
    - Update BadRequest exception message to use extracted message
    - Pass context to BadRequest exception
    - _Requirements: 1.1, 1.2, 1.3, 2.2, 3.2_
  
  - [ ] 4.2 Update 404 error handling in getRequestActual()
    - Extract error message using extractErrorMessage()
    - Build context using buildRequestContext()
    - Call logError() with 'debug' level before throwing
    - Update NotFoundError exception message to use extracted message
    - Pass context to NotFoundError exception
    - _Requirements: 1.1, 1.2, 1.3, 2.3, 3.2_
  
  - [ ] 4.3 Update 401/403 error handling in getRequestActual()
    - Extract error message using extractErrorMessage()
    - Build context using buildRequestContext()
    - Call logError() with 'debug' level before throwing
    - Update PermissionDenied exception message to use extracted message
    - Pass context to PermissionDenied exception
    - _Requirements: 1.1, 1.2, 1.3, 2.2, 3.2_
  
  - [ ] 4.4 Update UnknownError handling in getRequestActual()
    - Extract error message using extractErrorMessage()
    - Build context using buildRequestContext()
    - Call logError() with 'error' level (status >= 500) before throwing
    - Update UnknownError exception message to use extracted message
    - Only use "Unknown error" if extractErrorMessage returns fallback
    - Pass context to UnknownError exception
    - _Requirements: 1.1, 1.2, 1.3, 2.1, 3.1, 3.2_

- [ ] 5. Refactor postRequest() error handling
  - [ ] 5.1 Update 400 error handling in postRequest()
    - Extract error message using extractErrorMessage()
    - Build context using buildRequestContext() with POST method and request body
    - Call logError() with 'debug' level before throwing
    - Update BadRequest exception message to use extracted message
    - Pass context to BadRequest exception
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 2.2, 3.2_
  
  - [ ] 5.2 Update UnknownError handling in postRequest()
    - Extract error message using extractErrorMessage()
    - Build context using buildRequestContext() with POST method and request body
    - Call logError() with appropriate level (error for 500+, debug for others)
    - Update UnknownError exception message to use extracted message
    - Pass context to UnknownError exception
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 2.1, 2.2, 3.1_

- [ ] 6. Refactor postMultipartRequest() error handling
  - [ ] 6.1 Update 400 error handling in postMultipartRequest()
    - Remove existing Log::error() call
    - Extract error message using extractErrorMessage()
    - Build context using buildRequestContext() with POST method
    - Call logError() with 'debug' level before throwing
    - Update BadRequest exception message to use extracted message
    - Pass context to BadRequest exception
    - _Requirements: 1.1, 1.2, 1.3, 2.2, 3.2_
  
  - [ ] 6.2 Update UnknownError handling in postMultipartRequest()
    - Extract error message using extractErrorMessage()
    - Build context using buildRequestContext() with POST method
    - Call logError() with appropriate level based on status code
    - Update UnknownError exception message to use extracted message
    - Pass context to UnknownError exception
    - _Requirements: 1.1, 1.2, 1.3, 2.1, 2.2, 3.1_

- [ ] 7. Refactor loginStrapi() error handling
  - [ ] 7.1 Update PermissionDenied handling in loginStrapi()
    - Extract error message using extractErrorMessage()
    - Build context using buildRequestContext()
    - Call logError() with 'debug' level before throwing
    - Update PermissionDenied exception message to use extracted message
    - Pass context to PermissionDenied exception
    - _Requirements: 1.1, 1.2, 1.3, 2.2, 3.2_
  
  - [ ] 7.2 Update BadRequest handling in loginStrapi()
    - Extract error message using extractErrorMessage()
    - Build context using buildRequestContext()
    - Call logError() with 'debug' level before throwing
    - Update BadRequest exception message to use extracted message
    - Pass context to BadRequest exception
    - _Requirements: 1.1, 1.2, 1.3, 2.2, 3.2_
  
  - [ ] 7.3 Update UnknownError handling in loginStrapi()
    - Extract error message using extractErrorMessage()
    - Build context using buildRequestContext()
    - Call logError() with 'error' level before throwing
    - Update UnknownError exception message to use extracted message
    - Pass context to UnknownError exception
    - _Requirements: 1.1, 1.2, 1.3, 2.1, 3.1_

- [ ] 8. Update executeWithRetry() logging
  - [ ] 8.1 Enhance retry attempt logging
    - Update warning logs for retryable errors to use logError() method
    - Include full request context in retry logs
    - Ensure URL is included in all retry-related log messages
    - Keep log level as 'warning' for retry attempts
    - _Requirements: 1.1, 1.2, 1.3_
  
  - [ ] 8.2 Update connection error logging in executeWithRetry()
    - Build context for connection errors (response will be null)
    - Call logError() with 'error' level for connection errors
    - Include attempt number and max attempts in context
    - _Requirements: 1.1, 1.2, 3.5_
