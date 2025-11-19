<?php

namespace SilentWeb\StrapiWrapper;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SilentWeb\StrapiWrapper\Exceptions\BadRequest;
use SilentWeb\StrapiWrapper\Exceptions\ConnectionError;
use SilentWeb\StrapiWrapper\Exceptions\NotFoundError;
use SilentWeb\StrapiWrapper\Exceptions\PermissionDenied;
use SilentWeb\StrapiWrapper\Exceptions\UnknownAuthMethod;
use SilentWeb\StrapiWrapper\Exceptions\UnknownError;
use Throwable;

class StrapiWrapper
{
    protected const DEFAULT_RECORD_LIMIT = 100;

    protected int $apiVersion;

    protected int $cacheTimeout;

    protected int $timeout;

    protected string $apiUrl;

    protected string $imageUrl;

    protected string $username;

    protected string $password;

    protected string $token;

    protected string $authMethod;

    protected bool $debugLoggingEnabled = false;

    public function __construct()
    {
        $this->apiUrl = config('strapi-wrapper.url');
        $this->imageUrl = config('strapi-wrapper.uploadUrl');
        $this->apiVersion = config('strapi-wrapper.version');
        $this->username = config('strapi-wrapper.username');
        $this->password = config('strapi-wrapper.password');
        $this->token = config('strapi-wrapper.token');
        $this->authMethod = config('strapi-wrapper.auth');
        $this->cacheTimeout = config('strapi-wrapper.cache');
        $this->timeout = config('strapi-wrapper.timeout');
        $this->debugLoggingEnabled = config('strapi-wrapper.log_enabled');

        $allowedVersions = [4, 5];
        if (! in_array($this->apiVersion, $allowedVersions, true)) {
            throw new ConnectionError(
                "API version {$this->apiVersion} is not supported. Supported versions: ".
                implode(', ', $allowedVersions)
            );
        }

        $allowedAuthMethods = ['public', 'password', 'token'];
        if (! in_array($this->authMethod, $allowedAuthMethods, true)) {
            throw new UnknownAuthMethod;
        }
    }

    public function setTimeout(int $timeout): StrapiWrapper
    {
        $this->timeout = $timeout;

        return $this;
    }

    public function strapiPost($query, $content, $files = null): PromiseInterface|Response
    {

        if ($files) {
            return $this->postMultipartRequest($this->apiUrl.$query, [
                'data' => $content,
                'files' => $files,
            ]);
        }

        return $this->postRequest($this->apiUrl.$query, $content);
    }

    protected function postMultipartRequest($query, $content): PromiseInterface|Response
    {
        if ($this->authMethod !== 'public') {
            $client = $this->httpClient()->withToken($this->getToken())->asMultipart();
            foreach ($content['multipart'] as $file) {
                $name = 'files';
                if ($file['name'] !== 'files') {
                    $name .= '.'.$file['name'];
                }

                $client->attach($name, $file['contents'], $file['filename'] ?? null);
            }

            $response = $client->post($query, ['data' => $content['data']]);
            if (! $response->ok()) {
                $context = [
                    'url' => $query,
                    'status' => $response->status(),
                    'response_body' => $response->body(),
                ];

                if ($response->status() === 400) {
                    $json = $response->json();
                    $this->log('Post error', 'error', $json);
                    throw new BadRequest($query.' '.$json['error']['name'].' - '.$json['error']['message'], 400, null, null, true, $context);
                }

                throw new UnknownError('Error posting to strapi on '.$query, $response->status(), null, null, true, $context);
            }

            return $response;
        }

        // TODO: implementation of multipart request for non authenticated requests
        throw new UnknownError('Not authenticated');
    }

    protected function httpClient(): PendingRequest
    {
        // Create client
        $http = Http::timeout($this->timeout);

        // Check verification
        if (config('strapi-wrapper.verify_ssl') === false) {
            $http = $http->withoutVerifying();
        }

        // Add compatibility header for V4->v5
        if ($this->apiVersion === 5 && config('strapi-wrapper.compatibility_mode') === true) {
            $http = $http->withHeaders(['Strapi-Response-Format' => 'v4']);
        }

        return $http;
    }

    protected function getToken(bool $preventLoop = false): string
    {
        if ($this->authMethod === 'token') {
            return $this->token;
        }

        $token = Cache::remember('strapi-token', config('strapi-wrapper.token_cache_ttl', 600), function () {
            return self::loginStrapi();
        });

        try {
            $decodedToken = json_decode(base64_decode(str_replace('_', '/', str_replace('-', '+', explode('.', $token)[1]))), false, 512, JSON_THROW_ON_ERROR);

            if ($decodedToken->exp < time()) {
                Cache::forget('strapi-token');
                if ($preventLoop) {
                    throw new UnknownError('Token refresh loop detected', 503);
                }

                return self::getToken(true);
            }
        } catch (Throwable $th) {
            throw new UnknownError('Issue with fetching token '.$th);
        }

        return $token;
    }

    protected function loginStrapi(): string
    {
        $login = null;
        try {
            $login = $this->httpClient()->post($this->apiUrl.'/auth/local', [
                'identifier' => $this->username,
                'password' => $this->password,
            ]);
        } catch (ConnectionException $e) {
            throw new ConnectionError($e);
        } catch (Throwable $th) {
            throw new UnknownError($th);
        } finally {
            if (isset($login)) {
                if ($login->ok()) {
                    return $login->json()['jwt'];
                }

                if ($login->status() === 403 || $login->status() === 401) {
                    throw new PermissionDenied;
                }

                if ($login->status() === 400) {
                    throw new BadRequest('Bad request in strapi login', 400, $login->toException());
                }

                throw new UnknownError($login->toException());
            }

            throw new \RuntimeException('Error initialising strapi wrapper');
        }
    }

    /**
     * Execute an HTTP request with automatic retry logic for transient failures.
     *
     * This method wraps HTTP requests with retry logic that handles transient failures
     * such as rate limiting (429), bad gateway (502), service unavailable (503), and
     * gateway timeout (504). It uses exponential backoff between retry attempts.
     *
     * @param  callable  $request  A callable that returns a Response object
     * @return Response The successful response
     *
     * @throws ConnectionError When connection errors occur after all retries
     * @throws UnknownError When all retry attempts are exhausted
     */
    protected function executeWithRetry(callable $request): Response
    {
        $maxAttempts = config('strapi-wrapper.retry_attempts', 3);
        $baseDelay = config('strapi-wrapper.retry_delay', 100);
        $retryStatuses = config('strapi-wrapper.retry_on_status', [429, 502, 503, 504]);

        $attempt = 0;
        $lastException = null;
        $lastResponse = null;

        while ($attempt < $maxAttempts) {
            try {
                $response = $request();

                // If successful or non-retryable error, return immediately
                if ($response->successful() || ! in_array($response->status(), $retryStatuses, true)) {
                    return $response;
                }

                // Retryable error - log and continue to retry logic
                $lastResponse = $response;
                $this->log(
                    "Retryable error {$response->status()} on attempt ".($attempt + 1),
                    'warning',
                    [
                        'attempt' => $attempt + 1,
                        'max_attempts' => $maxAttempts,
                        'status' => $response->status(),
                    ]
                );
            } catch (ConnectionException $e) {
                // Connection errors are retryable
                $lastException = $e;
                $this->log(
                    'Connection error on attempt '.($attempt + 1).': '.$e->getMessage(),
                    'warning',
                    [
                        'attempt' => $attempt + 1,
                        'max_attempts' => $maxAttempts,
                        'exception' => get_class($e),
                    ]
                );
            }

            $attempt++;

            // If not last attempt, wait with exponential backoff
            if ($attempt < $maxAttempts) {
                $delay = $baseDelay * pow(2, $attempt - 1); // Exponential backoff
                usleep($delay * 1000); // Convert to microseconds
            }
        }

        // All retries exhausted
        if ($lastException) {
            throw new ConnectionError($lastException);
        }

        if ($lastResponse) {
            // Return the last response so the calling code can handle the error
            return $lastResponse;
        }

        throw new UnknownError("Request failed after {$maxAttempts} attempts", 503);
    }

    protected function log($message, $level = 'error', $context = [])
    {
        if ($this->debugLoggingEnabled) {
            Log::log($level, $message, $context);
        }

    }

    /**
     * Build comprehensive request context for logging.
     *
     * Creates a structured array containing all relevant information about an HTTP request
     * and response for debugging purposes. Handles null responses and request bodies gracefully.
     *
     * @param  string  $method  The HTTP method (GET, POST, etc.)
     * @param  string  $url  The full request URL
     * @param  Response|null  $response  The HTTP response object (null for connection errors)
     * @param  array|null  $requestBody  The request body data (null for GET requests)
     * @param  array  $additionalContext  Additional context to include in the log
     * @return array Structured context array for logging
     */
    protected function buildRequestContext(
        string $method,
        string $url,
        ?Response $response = null,
        ?array $requestBody = null,
        array $additionalContext = []
    ): array {
        $context = [
            'timestamp' => now()->toIso8601String(),
            'method' => $method,
            'url' => $url,
            'auth_method' => $this->authMethod,
        ];

        // Add response information if available
        if ($response !== null) {
            $context['status'] = $response->status();
            $responseBody = $response->body();
            $context['response_size'] = strlen($responseBody);

            // Truncate response body if longer than 1000 characters
            if (strlen($responseBody) > 1000) {
                $context['response_body'] = substr($responseBody, 0, 1000).' [truncated]';
            } else {
                $context['response_body'] = $responseBody;
            }
        }

        // Add request body if provided
        if ($requestBody !== null) {
            $context['request_body'] = $requestBody;
        }

        // Merge additional context
        return array_merge($context, $additionalContext);
    }

    /**
     * Sanitize sensitive data from log context.
     *
     * Recursively processes arrays to remove or mask sensitive information like authentication
     * tokens, passwords, and API keys. Preserves the structure and all non-sensitive data
     * for debugging purposes.
     *
     * @param  array  $data  The data array to sanitize
     * @return array The sanitized array with sensitive values redacted
     */
    protected function sanitizeLogData(array $data): array
    {
        $sensitiveKeys = ['token', 'jwt', 'authorization', 'password', 'secret'];

        foreach ($data as $key => $value) {
            // Check if this is a sensitive key
            $keyLower = strtolower($key);
            $isSensitive = false;

            foreach ($sensitiveKeys as $sensitiveKey) {
                if (str_contains($keyLower, $sensitiveKey)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                // Special handling for Bearer tokens - mask all but last 4 characters
                if (is_string($value) && str_starts_with($value, 'Bearer ')) {
                    $token = substr($value, 7); // Remove 'Bearer ' prefix
                    if (strlen($token) > 4) {
                        $data[$key] = 'Bearer ****'.substr($token, -4);
                    } else {
                        $data[$key] = '[REDACTED]';
                    }
                } else {
                    $data[$key] = '[REDACTED]';
                }
            } elseif (is_array($value)) {
                // Recursively sanitize nested arrays
                $data[$key] = $this->sanitizeLogData($value);
            }
        }

        return $data;
    }

    /**
     * Extract meaningful error message from Strapi response.
     *
     * Attempts to parse the response body as JSON and extract error messages from Strapi's
     * error structure (v4/v5 format). Handles multiple error formats and combines multiple
     * errors when present. Falls back to raw response body or provided fallback message.
     *
     * @param  Response  $response  The HTTP response object
     * @param  string  $fallback  Fallback message if no error message can be extracted
     * @return string The extracted or fallback error message
     */
    protected function extractErrorMessage(Response $response, string $fallback = 'Unknown error'): string
    {
        $body = $response->body();

        // If response is empty and status is 500+, use fallback
        if (empty($body) && $response->status() >= 500) {
            return $fallback;
        }

        // Try to parse as JSON
        try {
            $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            // Check for Strapi v4/v5 error format: error.message
            if (isset($json['error']['message'])) {
                $message = $json['error']['message'];

                // Check for multiple errors in error.details.errors
                if (isset($json['error']['details']['errors']) && is_array($json['error']['details']['errors'])) {
                    $errorMessages = [];
                    foreach ($json['error']['details']['errors'] as $error) {
                        if (isset($error['message'])) {
                            $errorMessages[] = $error['message'];
                        }
                    }

                    if (! empty($errorMessages)) {
                        return $message.': '.implode('; ', $errorMessages);
                    }
                }

                return $message;
            }

            // If JSON parsed but no error.message, return raw body (truncated)
            if (strlen($body) > 1000) {
                return substr($body, 0, 1000).' [truncated]';
            }

            return $body;
        } catch (\JsonException $e) {
            // JSON parsing failed, return raw response body (truncated)
            if (strlen($body) > 1000) {
                return substr($body, 0, 1000).' [truncated]';
            }

            return $body ?: $fallback;
        }
    }

    protected function postRequest($query, $content): PromiseInterface|Response
    {
        if ($this->apiVersion >= 4) {
            $content = ['data' => $content];
        }

        // Log before making request
        $this->log("Making POST request to: {$query}", 'debug', [
            'method' => 'POST',
            'url' => $query,
            'auth_method' => $this->authMethod,
        ]);

        $postClient = $this->httpClient();
        if ($this->authMethod !== 'public') {
            $postClient = $postClient->withToken($this->getToken());
        }

        $response = $this->executeWithRetry(function () use ($postClient, $query, $content) {
            return $postClient->post($query, $content);
        });

        if ($response && ! $response->successful()) {
            $context = [
                'url' => $query,
                'status' => $response->status(),
                'response_body' => $response->body(),
            ];

            if ($response->status() === 400) {
                throw new BadRequest($query.' '.$response->body(), 400, null, null, true, $context);
            }

            throw new UnknownError('Error posting to strapi on '.$query, $response->status(), null, null, true, $context);
        }

        // Log successful response
        if ($response && $response->successful()) {
            $this->log("Successful POST request to: {$query}", 'debug', [
                'method' => 'POST',
                'url' => $query,
                'status' => $response->status(),
                'response_size' => strlen($response->body()),
            ]);
        }

        return $response;
    }

    protected function getRequest($request, $cache = true)
    {
        if ($cache) {
            return Cache::remember($request, $this->cacheTimeout, function () use ($request) {
                return $this->getRequestActual($request);
            });
        }

        return $this->getRequestActual($request);
    }

    private function getRequestActual($request)
    {
        // Log before making request
        $this->log("Making GET request to: {$request}", 'debug', [
            'method' => 'GET',
            'url' => $request,
            'auth_method' => $this->authMethod,
        ]);

        $response = $this->executeWithRetry(function () use ($request) {
            if ($this->authMethod === 'public') {
                return $this->httpClient()->get($request);
            } else {
                return $this->httpClient()->withToken($this->getToken())->get($request);
            }
        });

        if ($response->ok()) {
            // Log successful response
            $this->log("Successful GET request to: {$request}", 'debug', [
                'method' => 'GET',
                'url' => $request,
                'status' => $response->status(),
                'response_size' => strlen($response->body()),
            ]);

            return $response->json();
        }

        $context = [
            'url' => $request,
            'status' => $response->status(),
            'response_body' => $response->body(),
        ];

        if ($response->status() === 400) {
            throw new BadRequest($request.' '.$response->body(), 400, null, null, true, $context);
        }

        if ($response->status() === 404) {
            throw new NotFoundError($request.' '.$response->body(), 404, null, null, true, $context);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new PermissionDenied($request, $response->status(), null, null, true, $context);
        }

        throw new UnknownError('['.$response->status().'] '.$request."\n".$response->body(), $response->status(), null, null, true, $context);
    }

    protected function generateQueryUrl(string $type, string|array $sortBy, string $sortOrder, int $limit, int $page, string $customQuery = ''): string
    {
        $concat = str_contains($type, '?') ? '&' : '?';
        $url = [$this->generateSortUrl($sortBy, $sortOrder)];

        if ($this->apiVersion === 3) {
            if ($limit !== self::DEFAULT_RECORD_LIMIT) {
                $url[] = '_limit='.$limit;
            }

            $url[] = '_start='.$page;
        }

        if ($this->apiVersion === 4 || $this->apiVersion === 5) {
            if ($limit !== self::DEFAULT_RECORD_LIMIT) {
                $url[] = 'pagination[pageSize]='.$limit;
            }
            if ($page !== 1) {
                $url[] = 'pagination[page]='.$page;
            }

            $url[] = $customQuery;
        }

        $url = array_filter($url);

        return $this->apiUrl.'/'.$type.$concat.implode('&', $url);
    }

    protected function generateSortUrl(string|array $sortBy = [], $defaultSortOrder = 'DESC'): ?string
    {
        if (empty($sortBy)) {
            return null;
        }

        $key = $this->apiVersion === 3 ? '_sort' : 'sort';

        if (! is_array($sortBy)) {
            return $key.'='.$sortBy.':'.$defaultSortOrder;
        }

        $string = [];
        foreach ($sortBy as $index => $value) {
            if (is_array($value)) {
                // Two-dimensional with second sort value being the order
                $sort = $value[0];
                $order = $value[1] ?? $defaultSortOrder;
            } else {
                // One dimensional
                $sort = $value;
                $order = $defaultSortOrder;
            }

            $string[] = $key.'['.$index.']='.$sort.':'.$order;
        }

        return implode('&', $string);
    }

    protected function generatePostUrl(string $type): string
    {
        return $this->apiUrl.'/'.$type;
    }

    /**
     * Converts relative URLs to absolute URLs in Strapi response data.
     *
     * This method recursively processes arrays to convert relative image/file URLs to absolute URLs
     * by prepending the configured image URL. It handles three cases:
     * 1. Direct file objects with 'url' and 'ext' keys (e.g., uploaded files)
     * 2. HTML img tags with relative src attributes
     * 3. Markdown image syntax with relative paths
     *
     * @param  array  $array  The response array from Strapi API containing URLs to convert
     * @return array The processed array with all relative URLs converted to absolute URLs
     *
     * @example
     * // File object conversion
     * Input:  ['url' => '/uploads/image.jpg', 'ext' => '.jpg']
     * Output: ['url' => 'https://example.com/uploads/image.jpg', 'ext' => '.jpg']
     * @example
     * // HTML content conversion
     * Input:  ['content' => '<img src="/uploads/photo.jpg" alt="Photo">']
     * Output: ['content' => '<img src="https://example.com/uploads/photo.jpg" alt="Photo">']
     * @example
     * // Markdown content conversion
     * Input:  ['description' => '![Alt text](/uploads/image.jpg)']
     * Output: ['description' => '![Alt text](https://example.com/uploads/image.jpg)']
     */
    protected function convertToAbsoluteUrls(array $array): array
    {
        foreach ($array as $key => $item) {
            // If value is array, call func on self to check values
            if (is_array($item)) {
                $array[$key] = $this->convertToAbsoluteUrls($item);
            }

            // If value is null or empty, stop
            if (! is_string($item) || empty($item)) {
                continue;
            }

            // If this is an image/file key - replace with URL in front
            if ($key === 'url' && isset($array['ext']) && str_starts_with($item, '/')) {
                $array[$key] = $this->imageUrl.$array[$key];
            } else {
                // NB: By default strapi returns markdown, but popular editor plugins make the return HTML

                /**
                 * HTML Image Pattern: Converts relative URLs in HTML img tags to absolute URLs
                 *
                 * Pattern: /<img([^>]*) src=[\'|"][^http|ftp|https]([^"|^\']*)\"/
                 *
                 * Breakdown:
                 * - <img          : Matches the opening img tag
                 * - ([^>]*)       : Capture group 1 - captures any attributes before src (e.g., class, alt, id)
                 * - src=          : Matches the src attribute
                 * - [\'|"]        : Matches either single or double quote
                 * - [^http|ftp|https] : Negative character class - excludes URLs starting with h, t, p, f (to skip absolute URLs)
                 * - ([^"|^\']*)   : Capture group 2 - captures the relative URL path until quote is found
                 * - \"            : Matches the closing double quote
                 *
                 * Examples of what this pattern matches:
                 * - <img src="/uploads/image.jpg">           → Captures: "", "/uploads/image.jpg"
                 * - <img class="hero" src="/media/pic.png"> → Captures: "class=\"hero\"", "/media/pic.png"
                 * - <img alt="Photo" src='/files/doc.pdf'>  → Captures: "alt=\"Photo\"", "/files/doc.pdf"
                 *
                 * Examples of what this pattern DOES NOT match (skipped):
                 * - <img src="https://example.com/image.jpg"> (absolute URL with https)
                 * - <img src="http://cdn.com/photo.png">      (absolute URL with http)
                 * - <img src="ftp://server.com/file.jpg">     (absolute URL with ftp)
                 *
                 * Replacement: Prepends the configured imageUrl to the captured relative path
                 */
                /** @noinspection RegExpDuplicateCharacterInClass */
                $html_pattern = '/<img([^>]*) src=[\'|"][^http|ftp|https]([^"|^\']*)\"/';
                $html_rewrite = '<img${1} src="'.$this->imageUrl.'/${2}"';

                /**
                 * Markdown Image Pattern: Converts relative URLs in Markdown image syntax to absolute URLs
                 *
                 * Pattern: /!\[(.*)]\((.*)\)/
                 *
                 * Breakdown:
                 * - !             : Matches the exclamation mark that starts Markdown image syntax
                 * - \[            : Matches the opening square bracket (escaped)
                 * - (.*)          : Capture group 1 - captures the alt text (any characters)
                 * - ]             : Matches the closing square bracket
                 * - \(            : Matches the opening parenthesis (escaped)
                 * - (.*)          : Capture group 2 - captures the image URL/path (any characters)
                 * - \)            : Matches the closing parenthesis (escaped)
                 *
                 * Examples of what this pattern matches:
                 * - ![Alt text](/uploads/image.jpg)           → Captures: "Alt text", "/uploads/image.jpg"
                 * - ![Photo](/media/photo.png)                → Captures: "Photo", "/media/photo.png"
                 * - ![](/files/document.pdf)                  → Captures: "", "/files/document.pdf"
                 * - ![Hero image](https://example.com/img.jpg) → Captures: "Hero image", "https://example.com/img.jpg"
                 *
                 * Note: This pattern matches ALL Markdown images (both relative and absolute URLs).
                 * The replacement prepends imageUrl to all captured paths, so absolute URLs will
                 * become malformed (e.g., "https://cdn.com/https://example.com/image.jpg").
                 * Consider adding negative lookahead (?!http|https|ftp) after \( to skip absolute URLs.
                 *
                 * Replacement: Prepends the configured imageUrl to the captured path
                 */
                $markdown_pattern = '/!\[(.*)]\((.*)\)/';
                $markdown_rewrite = '![$1]('.$this->imageUrl.'$2)';
                $array[$key] = preg_replace([$html_pattern, $markdown_pattern], [$html_rewrite, $markdown_rewrite], $item);
            }
        }

        return $array;
    }

    /**
     * Converts image and file fields from object format to URL strings.
     *
     * This method recursively processes arrays looking for image/file objects (identified by having
     * both 'url' and 'mime' keys) and simplifies them to just the URL string. The full metadata is
     * preserved in a parallel key with '_squash' suffix for non-numeric keys, allowing access to
     * additional file information like dimensions, mime type, size, etc.
     *
     * @param  array  $array  The array to process for image field conversion
     * @param  string|null  $parent  The parent key path for nested fields (used internally for recursion)
     * @return array The processed array with image fields converted to URL strings
     *
     * @example
     * // Single image field conversion
     * Input:  ['avatar' => ['url' => '/uploads/pic.jpg', 'mime' => 'image/jpeg', 'width' => 100, 'height' => 100]]
     * Output: ['avatar' => '/uploads/pic.jpg', 'avatar_squash' => ['url' => '/uploads/pic.jpg', 'mime' => 'image/jpeg', 'width' => 100, 'height' => 100]]
     * @example
     * // Array of images (numeric keys - no _squash created)
     * Input:  ['gallery' => [0 => ['url' => '/img1.jpg', 'mime' => 'image/jpeg'], 1 => ['url' => '/img2.jpg', 'mime' => 'image/png']]]
     * Output: ['gallery' => [0 => '/img1.jpg', 1 => '/img2.jpg']]
     * @example
     * // Nested structure with mixed content
     * Input:  ['user' => ['name' => 'John', 'avatar' => ['url' => '/avatar.jpg', 'mime' => 'image/jpeg']]]
     * Output: ['user' => ['name' => 'John', 'avatar' => '/avatar.jpg', 'avatar_squash' => [...]]]
     */
    protected function convertImageFields($array, $parent = null): array
    {
        if (! $parent) {
            $parent = '';
        }

        foreach ($array as $key => $item) {
            if (is_array($item)) {
                if (array_key_exists('url', $item) && array_key_exists('mime', $item)) {
                    $array[$key] = $item['url'];
                    if (! is_numeric($key)) {
                        // To make things easier, for non array images we can store attributes alongside
                        $array[$key.'_squash'] = $item;
                    }
                } else {
                    $array[$key] = $this->convertImageFields($item, $parent.$key.'.');
                }
            }
        }

        return $array;
    }

    /**
     * Recursively flattens Strapi v4/v5 response structure by removing 'data' and 'attributes' wrapper fields.
     *
     * Strapi v4 and v5 wrap response data in nested 'data' and 'attributes' objects, making it cumbersome
     * to access actual content. This method flattens the structure by merging these wrapper fields into
     * their parent, providing direct access to the actual data fields. It handles ID conflicts by renaming
     * nested 'id' fields to 'data_id' when necessary.
     *
     * @param  array|null  $array  The response array from Strapi API to flatten
     * @return array The flattened array with 'data' and 'attributes' wrappers removed
     *
     * @example
     * // Simple attribute squashing
     * Input:  ['data' => ['id' => 1, 'attributes' => ['title' => 'Hello', 'content' => 'World']]]
     * Output: ['id' => 1, 'title' => 'Hello', 'content' => 'World']
     * @example
     * // Nested data with relationships
     * Input:  ['data' => ['id' => 1, 'attributes' => ['title' => 'Post', 'author' => ['data' => ['id' => 5, 'attributes' => ['name' => 'John']]]]]]
     * Output: ['id' => 1, 'title' => 'Post', 'author' => ['id' => 5, 'name' => 'John']]
     * @example
     * // Handling null input
     * Input:  null
     * Output: []
     * @example
     * // Handling non-array input
     * Input:  'simple string'
     * Output: ['simple string']
     * @example
     * // ID conflict resolution
     * Input:  ['id' => 1, 'data' => ['id' => 2, 'attributes' => ['name' => 'Test']]]
     * Output: ['id' => 1, 'data_id' => 2, 'name' => 'Test']
     */
    protected function squashDataFields($array): array
    {
        // Handle null input explicitly
        if ($array === null) {
            return [];
        }

        // Check if this response is an array, if not return wrapped in array
        if (! is_array($array)) {
            return [$array];
        }

        $modifiedArray = [];
        foreach ($array as $key => $item) {
            if (is_array($item)) {
                if ($key === 'attributes' || $key === 'data') {
                    if ($key === 'data' && isset($modifiedArray['id'], $item['id'])) {
                        $item['data_id'] = $item['id'];
                        unset($item['id']);
                    }

                    $modifiedArray = $this->squashDataFields(array_merge($modifiedArray, $item));
                } else {
                    if (empty(array_filter($item, static function ($a) {
                        return $a !== null;
                    }))) {
                        $modifiedArray[$key] = null;
                    } else {
                        $modifiedArray[$key] = $this->squashDataFields($item);
                    }
                }
            } else {
                $modifiedArray[$key] = $item;
            }
        }

        return $modifiedArray;
    }
}
