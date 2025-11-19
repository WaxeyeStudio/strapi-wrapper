<?php

namespace SilentWeb\StrapiWrapper;

use Exception;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use JsonException;
use SilentWeb\StrapiWrapper\Exceptions\UnknownError;

class StrapiCollection extends StrapiWrapper
{
    private const POPULATE_NONE = 'none';
    private const POPULATE_ALL = 'all';
    private const POPULATE_CUSTOM = 'custom';

    private array $collection = [];

    private array $meta = [];

    private string $type;

    private string|array $sortBy = [];

    private string $sortOrder = 'DESC';

    private int $limit = 100;

    private int $page = 0;

    private bool $squashImage;

    private bool $absoluteUrl;

    private array $fields = [];

    private string $populateMode = self::POPULATE_ALL;

    private array $populateFields = [];

    private int $deep;

    private bool $includeDrafts = false;

    private bool $flatten = true;

    public function __construct(string $type)
    {
        parent::__construct();
        $this->type = $type;
        $this->deep = config('strapi-wrapper.populateDeep');
        $this->squashImage = config('strapi-wrapper.squashImage');
        $this->absoluteUrl = config('strapi-wrapper.absoluteUrl');
        $this->limit = StrapiWrapper::DEFAULT_RECORD_LIMIT;
        $this->page = 1;
    }

    /**
     * Find a single record matching the current query filters or abort with HTTP error.
     *
     * This method is useful in Laravel routes/controllers where you want to automatically
     * return a 404 (or other error code) if no matching record is found.
     *
     * @param  int  $failCode  The HTTP status code to abort with if no record is found (default: 404)
     * @param  bool  $cache  Whether to use cached results (default: true)
     * @return mixed The first matching record
     *
     * @example
     * // In a controller - automatically returns 404 if post not found
     * $post = StrapiWrapper::collection('posts')
     *     ->field('slug')->filter($slug)
     *     ->findOneOrFail();
     */
    public function findOneOrFail(int $failCode = 404, bool $cache = true): mixed
    {
        try {
            if ($result = $this->findOne($cache)) {
                return $result;
            }
        } catch (Exception $ex) {
            $this->log($ex->getMessage(), 'error', [
                'exception' => get_class($ex),
                'trace' => $ex->getTraceAsString(),
            ]);
        }

        abort($failCode);
    }

    /**
     * Find a single record matching the current query filters.
     *
     * Returns the first record that matches the applied filters, sorting, and populate settings.
     * The result is automatically cached and indexed for efficient retrieval by ID.
     *
     * @param  bool  $cache  Whether to use cached results (default: true)
     * @return array|null The first matching record, or null if no records found
     *
     * @throws JsonException If JSON encoding fails during cache operations
     *
     * @example
     * // Find a post by slug
     * $post = StrapiWrapper::collection('posts')
     *     ->field('slug')->filter('my-post')
     *     ->findOne();
     * @example
     * // Find most recent published article with author populated
     * $article = StrapiWrapper::collection('articles')
     *     ->populate(['author'])
     *     ->order('publishedAt', false)
     *     ->findOne();
     */
    public function findOne($cache = true)
    {
        // Limit request to 1 item, but remember the collection limit
        $oldLimit = $this->limit;
        $this->limit = 1;

        // We want to store the cache for individual items separately
        $url = md5($this->getUrl());
        if ($cache) {
            $result = Cache::remember($url, Config::get('strapi-wrapper.cache'), function () {
                return $this->query(false);
            });
        } else {
            $result = $this->query(false);
        }

        // Return limit to collection default
        $this->limit = $oldLimit;

        // Squash the results if required.
        if (! empty($result)) {
            // We also want to store in item cache if required by item ID
            if ($cache) {
                // First we work on the assumption that there is an "id" field.
                // Or we generate one based on the data
                $id = $result[0]['id'] ?? md5(json_encode($result, JSON_THROW_ON_ERROR));
                Cache::put($id, $url, Config::get('strapi-wrapper.cache'));

                $index = Cache::get($this->type.'_items', []);
                $index[] = $id;
                Cache::put($this->type.'_items', array_unique($index), Config::get('strapi-wrapper.cache'));
            }

            return $result[0];
        }

        return null;
    }

    /**
     * Build the complete query URL based on current filters, sorting, pagination, and populate settings.
     *
     * Constructs the full Strapi API URL including all query parameters for filters, sorting,
     * pagination, population, and draft status. This is useful for debugging or logging the
     * exact API request being made.
     *
     * @return string The complete query URL with all parameters
     *
     * @example
     * $url = StrapiWrapper::collection('posts')
     *     ->field('status')->filter('published')
     *     ->sort('createdAt')
     *     ->limit(10)
     *     ->getUrl();
     * // Returns: "https://api.example.com/posts?sort=createdAt:DESC&pagination[pageSize]=10&filters[status][$eq]=published"
     */
    public function getUrl(): string
    {
        $url = $this->generateQueryUrl($this->type,
            $this->sortBy,
            $this->sortOrder,
            $this->limit,
            $this->page,
            $this->getPopulateQuery());

        $filters = [];
        if (count($this->fields) > 0) {
            foreach ($this->fields as $field) {
                $fieldUrl = $field->url();
                if ($fieldUrl) {
                    $filters[] = $fieldUrl;
                }
            }
        }

        if ($this->includeDrafts) {
            if ($this->apiVersion >= 5) {
                $filters[] = 'status=draft';
            } else {
                $filters[] = 'publicationState=preview';
            }
        }

        if (! empty($filters)) {
            $url .= '&'.implode('&', ($filters));
        }

        return $url;
    }

    private function getPopulateQuery(): string
    {
        // Explicit no-populate mode
        if ($this->populateMode === self::POPULATE_NONE) {
            return '';
        }

        // Custom populate with specific fields
        if ($this->populateMode === self::POPULATE_CUSTOM) {
            $string = [];
            foreach ($this->populateFields as $key => $value) {
                if (is_numeric($key)) {
                    $string[] = "populate[$value][populate]=*";
                } else {
                    $string[] = "populate[$key][populate]=$value";
                }
            }

            return implode('&', $string);
        }

        // POPULATE_ALL mode - use deep populate if configured, otherwise populate=*
        // Only compatible with v4 and v5
        // V4 - https://github.com/Barelydead/strapi-plugin-populate-deep
        if ($this->apiVersion === 4 && $this->deep > 0) {
            return 'populate=deep,'.$this->deep;
        }

        // V5 - https://github.com/NEDDL/strapi-v5-plugin-populate-deep
        if ($this->apiVersion === 5 && $this->deep > 0) {
            return 'pLevel='.$this->deep;
        }

        return 'populate=*';
    }

    /**
     * Execute the query and return all matching records.
     *
     * Performs the API request with all configured filters, sorting, pagination, and populate
     * settings. Results are processed (flattened, URL conversion, image squashing) based on
     * configuration and stored in the collection property.
     *
     * @param  bool  $cache  Whether to use cached results (default: true)
     * @return array|null Array of matching records, or empty array if none found
     *
     * @example
     * // Get all published posts with pagination
     * $posts = StrapiWrapper::collection('posts')
     *     ->field('status')->filter('published')
     *     ->limit(20)
     *     ->page(1)
     *     ->query();
     * @example
     * // Get fresh data without cache
     * $posts = StrapiWrapper::collection('posts')->query(false);
     */
    public function query(bool $cache = true): ?array
    {
        $url = $this->getUrl();
        $data = $this->getRequest($url, $cache);
        $this->collection = $this->processRequestResponse($data) ?? [];

        return $this->collection;
    }

    protected function getRequest($request, $cache = true)
    {
        $response = parent::getRequest($request, $cache);

        if (empty($response)) {
            throw new UnknownError(' - Strapi returned no data');
        }

        // Store index in cache so that we can clear entire collection (for when tags are not supported)
        if ($cache) {
            $this->storeCacheIndex($request);
        }

        return $response;
    }

    private function storeCacheIndex($requestUrl): void
    {
        $maxSize = config('strapi-wrapper.cache_index_max_size', 1000);
        $index = Cache::get($this->type, []);
        $index[] = $requestUrl;
        $index = array_unique($index);

        // If exceeds max size, remove oldest entries (first elements)
        if (count($index) > $maxSize) {
            $index = array_slice($index, -$maxSize);
        }

        Cache::put($this->type, $index, Config::get('strapi-wrapper.cache'));
    }

    /**
     * Update an existing record in the collection.
     *
     * Sends a PUT request to update the specified record with new data. Authentication
     * is automatically handled based on the configured auth method.
     *
     * @param  int  $recordId  The ID of the record to update
     * @param  array  $contents  The data to update (field names and values)
     * @return PromiseInterface|Response The HTTP response from Strapi
     *
     * @example
     * // Update a post's title and content
     * StrapiWrapper::collection('posts')->put(5, [
     *     'title' => 'Updated Title',
     *     'content' => 'New content here'
     * ]);
     */
    public function put(int $recordId, array $contents): PromiseInterface|Response
    {
        $putUrl = $this->apiUrl.'/'.$this->type.'/'.$recordId;

        if ($this->authMethod === 'public') {
            return $this->httpClient()->put($putUrl, $contents);
        }

        return $this->httpClient()->withToken($this->getToken())->put($putUrl, $contents);
    }

    /**
     * Process the response from the server
     * Note that strapi version 3 is not supported
     */
    private function processRequestResponse(array $response): ?array
    {
        if (! $response) {
            throw new UnknownError(' - Strapi response unknown format');
        }

        $data = $response;

        if ($this->flatten) {
            $data = $this->squashDataFields($data);
        }

        if (empty($data['meta'])) {
            $this->meta = ['response' => time()];
        } else {
            $this->meta = array_merge(['response' => time()], $data['meta']);
            unset($data['meta']);
        }

        if ($this->absoluteUrl) {
            $data = $this->convertToAbsoluteUrls($data);
        }

        if ($this->squashImage) {
            $data = $this->convertImageFields($data);
        }

        return $data;
    }

    /**
     * Find a record by ID or abort with HTTP error.
     *
     * Retrieves a single record by its ID, automatically aborting with the specified
     * HTTP status code if the record is not found. Useful in Laravel routes/controllers.
     *
     * @param  int  $id  The ID of the record to find
     * @param  int  $errorCode  The HTTP status code to abort with if not found (default: 404)
     * @param  bool  $cache  Whether to use cached results (default: true)
     * @return array|null The matching record
     *
     * @example
     * // In a controller - automatically returns 404 if post not found
     * $post = StrapiWrapper::collection('posts')->findOneByIdOrFail($id);
     */
    public function findOneByIdOrFail($id, int $errorCode = 404, bool $cache = true): ?array
    {
        $data = $this->findOneById($id, $cache);
        if (! $data) {
            abort($errorCode);
        }

        return $data;
    }

    /**
     * Find a single record by its ID.
     *
     * Attempts to retrieve a record using the direct ID endpoint (/collection/id) first,
     * which is more efficient. If that fails (e.g., custom routes), falls back to querying
     * the collection with an ID filter. Respects current populate settings.
     *
     * @param  int  $id  The ID of the record to find
     * @param  bool  $cache  Whether to use cached results (default: true)
     * @return array|null The matching record, or null if not found
     *
     * @example
     * // Get a post by ID with author populated
     * $post = StrapiWrapper::collection('posts')
     *     ->populate(['author'])
     *     ->findOneById(5);
     * @example
     * // Get fresh data without cache
     * $post = StrapiWrapper::collection('posts')->findOneById(5, false);
     */
    public function findOneById(int $id, bool $cache = true): ?array
    {
        // In a default strapi instance, the id can be fetched from /collection-name/id
        $url = $this->apiUrl.'/'.$this->type.'/'.$id.'?'.$this->getPopulateQuery();
        // So we will try this first
        $data = null;
        try {
            $data = $this->getRequest($url, $cache);
            $data = $this->processRequestResponse($data);
        } catch (Exception $e) {
            // This hasn't worked, so lets try querying the main collection
            $this->log('Custom query failed first attempt: '.$e->getMessage(), 'debug', [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            try {
                $currentFilters = $this->fields;
                $this->clearAllFilters();
                $this->field('id')->filter($id);
                $data = $this->findOne($cache);
            } catch (Exception $e) {
                // Still failed, so we return null;
                $this->log('Custom query failed second attempt: '.$e->getMessage(), 'debug', [
                    'exception' => get_class($e),
                    'trace' => $e->getTraceAsString(),
                ]);
                $this->fields = $currentFilters;

                return null;
            }
        }

        return $data;
    }

    /**
     * Remove all field filters from the current query.
     *
     * Clears all filters that were added via field()->filter() calls. Optionally
     * re-executes the query immediately to refresh results without filters.
     *
     * @param  bool  $refresh  Whether to immediately re-query without cache (default: false)
     * @return static The collection instance for method chaining
     *
     * @example
     * // Clear filters and continue building query
     * $collection = StrapiWrapper::collection('posts')
     *     ->field('status')->filter('draft')
     *     ->clearAllFilters()
     *     ->field('author')->filter(5);
     * @example
     * // Clear filters and immediately refresh results
     * $collection->clearAllFilters(true);
     */
    public function clearAllFilters(bool $refresh = false): static
    {
        $this->fields = [];
        if ($refresh) {
            $this->query(false);
        }

        return $this;
    }

    /**
     * Get a field object for filtering.
     *
     * Returns a StrapiField instance for the specified field name, which can then be used
     * to apply filters. Field instances are cached, so calling this multiple times with
     * the same field name returns the same instance.
     *
     * @param  string  $fieldName  The name of the field to filter on (supports dot notation for nested fields)
     * @return StrapiField The field instance for applying filters
     *
     * @example
     * // Filter by single field
     * $posts = StrapiWrapper::collection('posts')
     *     ->field('status')->filter('published')
     *     ->query();
     * @example
     * // Filter by nested field using dot notation
     * $posts = StrapiWrapper::collection('posts')
     *     ->field('author.name')->filter('John Doe')
     *     ->query();
     * @example
     * // Multiple filters on different fields
     * $posts = StrapiWrapper::collection('posts')
     *     ->field('status')->filter('published')
     *     ->field('category')->filter('news')
     *     ->query();
     */
    public function field(string $fieldName): mixed
    {
        if (! isset($this->fields[$fieldName])) {
            $this->fields[$fieldName] = new StrapiField($fieldName, $this);
        }

        return $this->fields[$fieldName];
    }

    /**
     * Query using a custom endpoint appended to the collection type.
     *
     * Allows querying custom Strapi endpoints by appending a path to the collection type.
     * Useful for custom controllers or actions. If the response contains a single item,
     * returns that item directly instead of an array.
     *
     * @param  string  $customType  The custom path to append to the collection type
     * @param  bool  $cache  Whether to use cached results (default: true)
     * @return mixed Single record if response has one item, otherwise array of records
     *
     * @example
     * // Query a custom endpoint like /posts/featured
     * $featured = StrapiWrapper::collection('posts')->getCustom('/featured');
     * @example
     * // Query with filters on custom endpoint
     * $result = StrapiWrapper::collection('posts')
     *     ->field('category')->filter('tech')
     *     ->getCustom('/by-category');
     */
    public function getCustom(string $customType, bool $cache = true): mixed
    {
        $usualType = $this->type;
        $this->type .= $customType;
        $response = $this->query($cache);
        $this->type = $usualType;
        if (! empty($response) && count($response) === 1) {
            return $response[0];
        }

        return $response;
    }

    /**
     * @deprecated since version 0.2.7, use findOneById() instead
     */
    public function getOneById($id): ?array
    {
        return $this->findOneById($id);
    }

    /**
     * @throws JsonException
     *
     * @deprecated since version 0.2.7, use findOne() instead
     */
    public function getOne($cache = true)
    {
        return $this->findOne($cache);
    }

    /**
     * Configure query to fetch the most recently published records.
     *
     * Convenience method that sets sorting by publishedAt field in descending order
     * and applies the specified limit. Automatically uses the correct field name
     * based on Strapi API version.
     *
     * @param  int  $limit  Maximum number of records to return (default: 20)
     * @return static The collection instance for method chaining
     *
     * @example
     * // Get 10 most recent posts
     * $posts = StrapiWrapper::collection('posts')
     *     ->recent(10)
     *     ->query();
     * @example
     * // Get recent posts with filters
     * $posts = StrapiWrapper::collection('posts')
     *     ->field('status')->filter('published')
     *     ->recent(5)
     *     ->query();
     */
    public function recent(int $limit = 20): static
    {
        $this->sortBy = $this->apiVersion === 3 ? 'published_at' : 'publishedAt';
        $this->limit = $limit;
        $this->page = 0;

        return $this;
    }

    /**
     * @deprecated since version 0.2.7, use collection() instead
     */
    public function getCollection(): array
    {
        return $this->collection;
    }

    /**
     * Get the results from the last executed query.
     *
     * Returns the collection of records from the most recent query() call.
     * This is useful when you want to access results multiple times without re-querying.
     *
     * @return array Array of records from the last query
     *
     * @example
     * $wrapper = StrapiWrapper::collection('posts')->query();
     * $posts = $wrapper->collection(); // Get the results
     * $count = count($posts); // Work with results multiple times
     */
    public function collection(): array
    {
        return $this->collection;
    }

    /**
     * Get metadata from the last executed query.
     *
     * Returns pagination and other metadata from the most recent query, including
     * total count, page info, and response timestamp. Useful for implementing
     * pagination controls in your application.
     *
     * @return array Metadata including pagination info and response timestamp
     *
     * @example
     * $wrapper = StrapiWrapper::collection('posts')->limit(10)->page(1)->query();
     * $meta = $wrapper->meta();
     * // $meta contains: ['pagination' => ['total' => 50, 'page' => 1, ...], 'response' => timestamp]
     */
    public function meta(): array
    {
        return $this->meta;
    }

    /**
     * Set multiple query options at once.
     *
     * Allows bulk configuration of query settings using an associative array.
     * Only recognized option keys are applied; unknown keys are ignored.
     *
     * @param  array  $options  Associative array of option names and values
     * @return static The collection instance for method chaining
     *
     * Available options:
     * - absoluteUrl (bool): Convert relative URLs to absolute
     * - squashImage (bool): Simplify image fields to URL strings
     * - includeDrafts (bool): Include draft/unpublished content
     * - sortBy (string|array): Field(s) to sort by
     * - sortOrder (string): Sort direction ('ASC' or 'DESC')
     * - limit (int): Maximum records to return
     * - page (int): Page number for pagination
     * - deep (int): Populate depth level
     * - flatten (bool): Flatten data/attributes wrappers
     *
     * @example
     * $posts = StrapiWrapper::collection('posts')
     *     ->setOptions([
     *         'limit' => 20,
     *         'sortBy' => 'createdAt',
     *         'sortOrder' => 'DESC',
     *         'absoluteUrl' => true
     *     ])
     *     ->query();
     */
    public function setOptions(array $options): static
    {
        $configurableOptions = [
            'absoluteUrl', 'squashImage', 'includeDrafts',
            'sortBy', 'sortOrder', 'limit', 'page', 'deep',
            'flatten',
        ];

        foreach ($options as $key => $value) {
            if (in_array($key, $configurableOptions, true)) {
                $this->$key = $value;
            }
        }

        return $this;
    }

    /**
     * Enable or disable conversion of relative URLs to absolute URLs.
     *
     * When enabled, all relative file/image URLs in the response will be converted
     * to absolute URLs by prepending the configured upload URL. Useful when Strapi
     * uses local file storage and you need full URLs in your application.
     *
     * @param  bool  $setting  Whether to convert to absolute URLs (default: true)
     * @return static The collection instance for method chaining
     *
     * @example
     * // Enable absolute URL conversion
     * $posts = StrapiWrapper::collection('posts')
     *     ->absolute()
     *     ->query();
     * // Image URLs will be: https://example.com/uploads/image.jpg
     * @example
     * // Disable absolute URL conversion
     * $posts = StrapiWrapper::collection('posts')
     *     ->absolute(false)
     *     ->query();
     * // Image URLs will be: /uploads/image.jpg
     */
    public function absolute(bool $setting = true): static
    {
        $this->absoluteUrl = $setting;

        return $this;
    }

    /**
     * Enable or disable simplification of image/file fields to URL strings.
     *
     * When enabled, image/file objects (with url, mime, dimensions, etc.) are simplified
     * to just the URL string. Full metadata is preserved in a parallel field with '_squash'
     * suffix for non-array fields. This makes it easier to work with image URLs directly.
     *
     * @param  bool  $setting  Whether to squash image fields (default: true)
     * @return static The collection instance for method chaining
     *
     * @example
     * // With squash enabled
     * $post = StrapiWrapper::collection('posts')->squash()->findOne();
     * // $post['avatar'] = '/uploads/pic.jpg'
     * // $post['avatar_squash'] = ['url' => '...', 'mime' => 'image/jpeg', 'width' => 100, ...]
     * @example
     * // With squash disabled
     * $post = StrapiWrapper::collection('posts')->squash(false)->findOne();
     * // $post['avatar'] = ['url' => '/uploads/pic.jpg', 'mime' => 'image/jpeg', 'width' => 100, ...]
     */
    public function squash(bool $setting = true): static
    {
        $this->squashImage = $setting;

        return $this;
    }

    /**
     * Set the field(s) to sort results by.
     *
     * Configures sorting using the currently set sort order (default: DESC).
     * Supports single field or multiple fields with individual sort directions.
     * To change both field and order at once, use order() method instead.
     *
     * @param  string|array  $sortBy  Field name or array of fields to sort by
     * @return static The collection instance for method chaining
     *
     * @example
     * // Sort by single field (uses current sort order)
     * $posts = StrapiWrapper::collection('posts')
     *     ->sort('createdAt')
     *     ->query();
     * @example
     * // Sort by multiple fields with same order
     * $posts = StrapiWrapper::collection('posts')
     *     ->sort(['priority', 'createdAt'])
     *     ->query();
     * @example
     * // Sort by multiple fields with different orders
     * $posts = StrapiWrapper::collection('posts')
     *     ->sort([['priority', 'ASC'], ['createdAt', 'DESC']])
     *     ->query();
     */
    public function sort(string|array $sortBy): static
    {
        $this->sortBy = $sortBy;

        return $this;
    }

    /**
     * Set the maximum number of records to return.
     *
     * Configures the page size for pagination. Use with page() method to implement
     * pagination in your application.
     *
     * @param  int  $limit  Maximum number of records to return
     * @return static The collection instance for method chaining
     *
     * @example
     * // Get 20 records per page
     * $posts = StrapiWrapper::collection('posts')
     *     ->limit(20)
     *     ->page(1)
     *     ->query();
     */
    public function limit(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * Set the page number for pagination.
     *
     * Configures which page of results to return. Use with limit() method to control
     * page size. Page numbering starts at 1.
     *
     * @param  int  $page  The page number to retrieve (1-based)
     * @return static The collection instance for method chaining
     *
     * @example
     * // Get second page of results (records 21-40)
     * $posts = StrapiWrapper::collection('posts')
     *     ->limit(20)
     *     ->page(2)
     *     ->query();
     */
    public function page(int $page): static
    {
        $this->page = $page;

        return $this;
    }

    /**
     * Create a new record in the collection.
     *
     * Sends a POST request to create a new record with the provided data.
     * Authentication is automatically handled based on the configured auth method.
     *
     * @param  array  $fields  The data for the new record (field names and values)
     * @return PromiseInterface|Response The HTTP response from Strapi
     *
     * @example
     * // Create a new post
     * $response = StrapiWrapper::collection('posts')->post([
     *     'title' => 'New Post',
     *     'content' => 'Post content here',
     *     'status' => 'draft'
     * ]);
     */
    public function post(array $fields): PromiseInterface|Response
    {
        $url = $this->generatePostUrl($this->type);

        return $this->postRequest($url, $fields);
    }

    /**
     * Create a new record with file uploads.
     *
     * Sends a multipart POST request to create a record with both data fields and file uploads.
     * Handles single files or arrays of files per field. Authentication is automatically handled.
     *
     * @param  array  $fields  The data fields for the new record
     * @param  array  $files  Associative array of field names to file arrays (with 'path' and optional 'name' keys)
     * @return PromiseInterface|Response The HTTP response from Strapi
     *
     * @throws JsonException If JSON encoding of fields fails
     *
     * @example
     * // Create post with single image
     * $response = StrapiWrapper::collection('posts')->postFiles(
     *     ['title' => 'Post with Image', 'content' => 'Content'],
     *     ['cover' => ['path' => '/path/to/image.jpg', 'name' => 'cover.jpg']]
     * );
     * @example
     * // Create post with multiple gallery images
     * $response = StrapiWrapper::collection('posts')->postFiles(
     *     ['title' => 'Gallery Post'],
     *     ['gallery' => [
     *         ['path' => '/path/to/img1.jpg', 'name' => 'img1.jpg'],
     *         ['path' => '/path/to/img2.jpg', 'name' => 'img2.jpg']
     *     ]]
     * );
     */
    public function postFiles(array $fields, array $files): PromiseInterface|Response
    {
        $url = $this->generatePostUrl($this->type);
        $multipart = [
            'multipart' => [],
            'data' => json_encode($fields, JSON_THROW_ON_ERROR),
        ];
        foreach ($files as $field => $file) {
            if (is_array($file) && ! isset($file['path'])) {
                foreach ($file as $f) {
                    $multipart['multipart'][] = $this->prepInputFileArray($field, $f);
                }
            } else {
                $multipart['multipart'][] = $this->prepInputFileArray($field, $file);
            }
        }

        return $this->postMultipartRequest($url, $multipart);
    }

    private function prepInputFileArray(string $name, array $file): array
    {
        return [
            'name' => $name,
            'contents' => fopen($file['path'], 'rb'),
            'filename' => $file['name'] ?? null,
        ];
    }

    /**
     * Set the depth level for automatic relation population.
     *
     * When not using custom populate() configuration, this sets how many levels deep
     * to automatically populate relations. Requires the populate-deep plugin to be
     * installed on your Strapi server.
     *
     * Required plugins:
     * - Strapi v4: https://github.com/Barelydead/strapi-plugin-populate-deep
     * - Strapi v5: https://github.com/NEDDL/strapi-v5-plugin-populate-deep
     *
     * @param  int  $depth  Number of relation levels to populate (default: 1)
     * @return static The collection instance for method chaining
     *
     * @example
     * // Populate 2 levels deep (e.g., post -> author -> avatar)
     * $posts = StrapiWrapper::collection('posts')
     *     ->deep(2)
     *     ->query();
     * @example
     * // Disable deep population
     * $posts = StrapiWrapper::collection('posts')
     *     ->deep(0)
     *     ->query();
     */
    public function deep(int $depth = 1): static
    {
        $this->deep = $depth;

        return $this;
    }

    /**
     * Get the configured Strapi API version.
     *
     * Returns the API version (4 or 5) that this instance is configured to use.
     * Useful for version-specific logic in your application.
     *
     * @return int The Strapi API version (4 or 5)
     *
     * @example
     * $collection = StrapiWrapper::collection('posts');
     * if ($collection->apiVersion() === 5) {
     *     // Use v5-specific features
     * }
     */
    public function apiVersion(): int
    {
        return $this->apiVersion;
    }

    /**
     * Set both the sort field and sort direction.
     *
     * Configures sorting by specifying both the field(s) to sort by and the sort direction.
     * This is a convenience method that combines sort() and sort order configuration.
     * To only change the field without affecting order, use sort() method instead.
     *
     * @param  string|array  $sortBy  Field name or array of fields to sort by
     * @param  bool  $ascending  Whether to sort in ascending order (default: false for descending)
     * @return static The collection instance for method chaining
     *
     * @example
     * // Sort by createdAt descending (most recent first)
     * $posts = StrapiWrapper::collection('posts')
     *     ->order('createdAt', false)
     *     ->query();
     * @example
     * // Sort by title ascending (A-Z)
     * $posts = StrapiWrapper::collection('posts')
     *     ->order('title', true)
     *     ->query();
     * @example
     * // Sort by multiple fields descending
     * $posts = StrapiWrapper::collection('posts')
     *     ->order(['priority', 'createdAt'], false)
     *     ->query();
     */
    public function order(string|array $sortBy, bool $ascending = false): static
    {
        $this->sortBy = $sortBy;
        $this->sortOrder = $ascending ? 'ASC' : 'DESC';

        return $this;
    }

    /**
     * Configure which relations to populate in the query results.
     *
     * Specifies which related fields should be included in the response. Empty array
     * populates all relations (populate=*), while specific fields can be listed for
     * selective population. Overrides deep population settings when used.
     *
     * @param  array  $populateQuery  Array of relation field names to populate, or empty for all
     * @return static The collection instance for method chaining
     *
     * @example
     * // Populate all relations
     * $posts = StrapiWrapper::collection('posts')
     *     ->populate([])
     *     ->query();
     * @example
     * // Populate specific relations
     * $posts = StrapiWrapper::collection('posts')
     *     ->populate(['author', 'category', 'tags'])
     *     ->query();
     * @example
     * // Populate nested relations
     * $posts = StrapiWrapper::collection('posts')
     *     ->populate(['author' => 'avatar', 'category'])
     *     ->query();
     */
    public function populate(array $populateQuery = []): static
    {
        if (empty($populateQuery)) {
            $this->populateMode = self::POPULATE_ALL;
            $this->populateFields = [];
        } else {
            $this->populateMode = self::POPULATE_CUSTOM;
            $this->populateFields = $populateQuery;
        }

        return $this;
    }

    /**
     * Explicitly disable population of relations in the query results.
     *
     * Prevents any relations from being populated, overriding both populate() and deep()
     * settings. Useful when you only need the main record data without any related records.
     *
     * @return static The collection instance for method chaining
     *
     * @example
     * // Get posts without any populated relations
     * $posts = StrapiWrapper::collection('posts')
     *     ->noPopulate()
     *     ->query();
     * @example
     * // Override previous populate settings
     * $posts = StrapiWrapper::collection('posts')
     *     ->populate(['author'])
     *     ->noPopulate()  // This takes precedence
     *     ->query();
     */
    public function noPopulate(): static
    {
        $this->populateMode = self::POPULATE_NONE;
        $this->populateFields = [];

        return $this;
    }

    /**
     * Enable or disable flattening of Strapi's data/attributes wrapper structure.
     *
     * When enabled (default), removes the nested 'data' and 'attributes' wrappers from
     * Strapi v4/v5 responses, providing direct access to field values. Disable if you
     * need to preserve the original Strapi response structure.
     *
     * @param  bool  $flatten  Whether to flatten the response structure (default: true)
     * @return static The collection instance for method chaining
     *
     * @example
     * // With flatten enabled (default)
     * $post = StrapiWrapper::collection('posts')->flatten()->findOne();
     * // Access: $post['title']
     * @example
     * // With flatten disabled
     * $post = StrapiWrapper::collection('posts')->flatten(false)->findOne();
     * // Access: $post['data']['attributes']['title']
     */
    public function flatten(bool $flatten = true): static
    {
        $this->flatten = $flatten;

        return $this;
    }

    /**
     * Clear cached data for this collection.
     *
     * Removes all cached query results for this collection type. Optionally also clears
     * the individual item cache index. Useful after creating, updating, or deleting records
     * to ensure fresh data on next query.
     *
     * @param  bool  $includingItems  Whether to also clear individual item cache (default: false)
     *
     * @example
     * // Clear collection cache after creating a new post
     * StrapiWrapper::collection('posts')->post(['title' => 'New Post']);
     * StrapiWrapper::collection('posts')->clearCollectionCache();
     * @example
     * // Clear both collection and item caches
     * StrapiWrapper::collection('posts')->clearCollectionCache(true);
     */
    public function clearCollectionCache(bool $includingItems = false): void
    {
        $this->clearCache($this->type);
        if ($includingItems) {
            $this->clearCache($this->type.'_items');
        }
    }

    private function clearCache($key): void
    {
        $cache = Cache::get($key, []);
        if (is_array($cache)) {
            foreach ($cache as $value) {
                Cache::forget($value);
            }
        }

        Cache::forget($key);
    }

    /**
     * Clear cached data for a specific item by ID.
     *
     * Removes the cached data for a single record, identified by its ID. Useful after
     * updating a specific record to ensure fresh data on next retrieval.
     *
     * @param  int|string  $itemId  The ID of the item to clear from cache
     *
     * @example
     * // Clear cache for specific post after updating
     * StrapiWrapper::collection('posts')->put(5, ['title' => 'Updated']);
     * StrapiWrapper::collection('posts')->clearItemCache(5);
     */
    public function clearItemCache($itemId): void
    {
        $cache = Cache::pull($this->type.'_items', []);
        if (isset($cache[$itemId])) {
            Cache::forget($cache[$itemId]);
            unset($cache[$itemId]);
        }
        Cache::put($this->type.'_items', $cache, Config::get('strapi-wrapper.cache'));
    }

    /**
     * Delete a record from the collection.
     *
     * Sends a DELETE request to remove the specified record. Authentication is
     * automatically handled based on the configured auth method.
     *
     * @param  int  $recordId  The ID of the record to delete
     * @return PromiseInterface|Response The HTTP response from Strapi
     *
     * @example
     * // Delete a post and clear its cache
     * $response = StrapiWrapper::collection('posts')->delete(5);
     * if ($response->successful()) {
     *     StrapiWrapper::collection('posts')->clearItemCache(5);
     * }
     */
    public function delete(int $recordId): PromiseInterface|Response
    {
        $deleteUrl = $this->apiUrl.'/'.$this->type.'/'.$recordId;

        if ($this->authMethod === 'public') {
            return $this->httpClient()->delete($deleteUrl);
        }

        return $this->httpClient()->withToken($this->getToken())->delete($deleteUrl);
    }
}
