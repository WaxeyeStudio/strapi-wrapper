<?php

namespace SilentWeb\StrapiWrapper;


use Exception;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use SilentWeb\StrapiWrapper\Exceptions\UnknownError;

class StrapiCollection extends StrapiWrapper
{
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
    private array|null $populate = [];
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
     * @param int  $failCode
     * @param bool $cache
     *
     * @return mixed
     */
    public function findOneOrFail(int $failCode = 404, bool $cache = true): mixed
    {
        try {
            if ($result = $this->findOne($cache)) {
                return $result;
            }
        } catch (Exception $ex) {
            Log::error($ex);
        }

        abort($failCode);
    }

    /**
     * @throws JsonException
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
        if (isset($result[0])) {
            // We also want to store in item cache if required by item ID
            if ($cache) {
                // First we work on the assumption that there is an "id" field.
                // Or we generate one based on the data
                $id = $result[0]['id'] ?? md5(json_encode($result, JSON_THROW_ON_ERROR));
                Cache::put($id, $url, Config::get('strapi-wrapper.cache'));

                $index = Cache::get($this->type . '_items', []);
                $index[] = $id;
                Cache::put($this->type . '_items', array_unique($index), Config::get('strapi-wrapper.cache'));
            }

            return $result[0];
        }
        return null;
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        $url = $this->generateQueryUrl($this->type,
            $this->sortBy,
            $this->sortOrder,
            $this->limit,
            $this->page,
            $this->getPopulateQuery());

        if (count($this->fields) > 0) {
            $filters = [];
            foreach ($this->fields as $field) {
                $fieldUrl = $field->url();
                if ($fieldUrl) {
                    $filters[] = $fieldUrl;
                }
            }
            $url .= '&' . implode('&', ($filters));
        }

        if ($this->includeDrafts) {
            $url .= '&publicationState=preview';
        }

        return $url;
    }

    private function getPopulateQuery(): string
    {
        if (empty($this->populate)) {
            if ($this->deep > 0) {
                return 'populate=deep,' . $this->deep;
            }

            if (is_null($this->populate)) {
                return "";
            }

            return 'populate=*';
        }

        $string = [];
        foreach ($this->populate as $key => $value) {
            if (is_numeric($key)) {
                $string[] = "populate[$value][populate]=*";
            } else {
                $string[] = "populate[$key][populate]=$value";
            }
        }
        return implode('&', $string);
    }

    /**
     * @param bool $cache
     *
     * @return array|mixed
     */
    public function query(bool $cache = true): array|null
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
            throw new UnknownError(" - Strapi returned no data");
        }

        // Store index in cache so that we can clear entire collection (for when tags are not supported)
        if ($cache) {
            $this->storeCacheIndex($request);
        }

        return $response;
    }

    private function storeCacheIndex($requestUrl): void
    {
        $index = Cache::get($this->type, []);
        $index[] = $requestUrl;
        Cache::put($this->type, array_unique($index), Config::get('strapi-wrapper.cache'));
    }

    public function put(int $recordId, array $contents): PromiseInterface|Response
    {
        $putUrl = $this->apiUrl . '/' . $this->type . '/' . $recordId;

        if ($this->authMethod === "public") {
            return Http::timeout($this->timeout)->put($putUrl, $contents);
        }

        return Http::timeout($this->timeout)->withToken($this->getToken())->put($putUrl, $contents);
    }

    /**
     * Process the response from the server
     * Note that strapi version 3 is not supported
     *
     * @param array $response
     *
     * @return array|null
     */
    private function processRequestResponse(array $response): array|null
    {
        if (!$response) {
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
     * @param      $id
     * @param int  $errorCode
     * @param bool $cache
     *
     * @return array|null
     */
    public function findOneByIdOrFail($id, int $errorCode = 404, bool $cache = true): ?array
    {
        $data = $this->findOneById($id, $cache);
        if (!$data) {
            abort($errorCode);
        }
        return $data;
    }

    /**
     * @param int  $id
     * @param bool $cache
     *
     * @return array|null
     */
    public function findOneById(int $id, bool $cache = true): array|null
    {
        // In a default strapi instance, the id can be fetched from /collection-name/id
        $url = $this->apiUrl . '/' . $this->type . '/' . $id . '?' . $this->getPopulateQuery();
        // So we will try this first
        $data = null;
        try {
            $data = $this->getRequest($url, $cache);
            $data = $this->processRequestResponse($data);
        } catch (Exception $e) {
            // This hasn't worked, so lets try querying the main collection
            Log::debug('Custom query failed first attempt', $e->getTrace());
            try {
                $currentFilters = $this->fields;
                $this->clearAllFilters();
                $this->field('id')->filter($id);
                $data = $this->findOne($cache);
            } catch (Exception $e) {
                // Still failed, so we return null;
                Log::debug('Custom query failed second attempt', $e->getTrace());
                $this->fields = $currentFilters;
                return null;
            }
        }

        return $data;
    }

    /**
     * @param bool $refresh
     *
     * @return $this
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
     * @param string $fieldName
     *
     * @return mixed|StrapiField
     */
    public function field(string $fieldName): mixed
    {
        if (!isset($this->filters[$fieldName])) {
            $this->fields[$fieldName] = new StrapiField($fieldName, $this);
        }
        return $this->fields[$fieldName];
    }

    /**
     * Query using a custom endpoint and fetch results
     *
     * @param string $customType
     * @param bool   $cache
     *
     * @return mixed
     */
    public function getCustom(string $customType, bool $cache = true): mixed
    {
        $usualType = $this->type;
        $this->type .= $customType;
        $response = $this->query($cache);
        $this->type = $usualType;
        if (count($response) === 1 && isset($response[0])) {
            return $response[0];
        }
        return $response;
    }

    /**
     * @param $id
     *
     * @return array|null
     * @deprecated since version 0.2.7, use findOneById() instead
     */
    public function getOneById($id): array|null
    {
        return $this->findOneById($id);
    }

    /**
     * @throws JsonException
     * @deprecated since version 0.2.7, use findOne() instead
     */
    public function getOne($cache = true)
    {
        return $this->findOne($cache);
    }

    /**
     * @param int $limit
     *
     * @return $this
     */
    public function recent(int $limit = 20): static
    {
        $this->sortBy = $this->apiVersion === 3 ? 'published_at' : 'publishedAt';
        $this->limit = $limit;
        $this->page = 0;
        return $this;
    }

    /**
     * @return array
     * @deprecated since version 0.2.7, use collection() instead
     */
    public function getCollection(): array
    {
        return $this->collection;
    }

    /**
     * Returns the last query results
     * @return array
     */
    public function collection(): array
    {
        return $this->collection;
    }

    /**
     * Gets the metadata for the last query performed
     * @return array
     */
    public function meta(): array
    {
        return $this->meta;
    }

    /**
     * @param array $options
     *
     * @return $this
     */
    public function setOptions(array $options): StrapiCollection
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
     * Returned collection will have any file/image urls as absolutely set (if using local file store)
     * @return $this
     */
    public function absolute(bool $setting = true): StrapiCollection
    {
        $this->absoluteUrl = $setting;
        return $this;
    }

    /**
     * Returned collection will have any file/image fields squashed, as such just the url is returned.
     * @return $this
     */
    public function squash(bool $setting = true): StrapiCollection
    {
        $this->squashImage = $setting;
        return $this;
    }

    /**
     * Will tell the CMS to sort by the field indicated using the last set order.
     * An array can also be passed eg ['id', 'name'] or ['id', ['name','DESC'] to sort by multiple fields
     * To change the order use $->order($sortBy, $ascending) method
     *
     * @param string|array $sortBy
     *
     * @return $this
     */
    public function sort(string|array $sortBy): StrapiCollection
    {
        $this->sortBy = $sortBy;
        return $this;
    }

    /**
     * @param string $limit
     *
     * @return $this
     */
    public function limit(string $limit): StrapiCollection
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * @param int $page
     *
     * @return $this
     */
    public function page(int $page): StrapiCollection
    {
        $this->page = $page;
        return $this;
    }

    /**
     * @param array $fields
     *
     * @return PromiseInterface|Response
     */
    public function post(array $fields): PromiseInterface|Response
    {
        $url = $this->generatePostUrl($this->type);
        return $this->postRequest($url, $fields);
    }

    /**
     * @param array $fields
     * @param array $files
     *
     * @return PromiseInterface|Response
     * @throws JsonException
     */
    public function postFiles(array $fields, array $files): PromiseInterface|Response
    {
        $url = $this->generatePostUrl($this->type);
        $multipart = [
            'multipart' => [],
            'data' => json_encode($fields, JSON_THROW_ON_ERROR),
        ];
        foreach ($files as $field => $file) {
            if (is_array($file) && !isset($file['path'])) {
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
     * If not specifying which fields to populate, will try to populate $depth levels when performing a get request
     * note, this requires https://github.com/Barelydead/strapi-plugin-populate-deep to be installed on the strapi
     * server
     *
     * @param int $depth
     *
     * @return $this
     */
    public function deep(int $depth = 1): StrapiCollection
    {
        $this->deep = $depth;
        return $this;
    }

    /**
     * @return int
     */
    public function apiVersion(): int
    {
        return $this->apiVersion;
    }

    /**
     * Will tell the CMS to sort by the field indicated and adjust the order.
     * To just change the sort field use $->sort($sortBy) method
     *
     * @param string|array $sortBy
     * @param bool         $ascending
     *
     * @return $this
     */
    public function order(string|array $sortBy, bool $ascending = false): StrapiCollection
    {
        $this->sortBy = $sortBy;
        $this->sortOrder = $ascending ? 'ASC' : 'DESC';
        return $this;
    }

    /**
     * @param array $populateQuery
     *
     * @return $this
     */
    public function populate(array $populateQuery = []): StrapiCollection
    {
        $this->populate = $populateQuery;
        return $this;
    }

    public function flatten(bool $flatten = true): StrapiCollection
    {
        $this->flatten = $flatten;
        return $this;
    }

    /**
     * Clear any cached item for the collection
     *
     * @param bool $includingItems
     *
     * @return void
     */
    public function clearCollectionCache(bool $includingItems = false): void
    {
        $this->clearCache($this->type);
        if ($includingItems) {
            $this->clearCache($this->type . '_items');
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
     * @param $itemId
     *
     * @return void
     */
    public function clearItemCache($itemId): void
    {
        $cache = Cache::pull($this->type . '_items', []);
        if (isset($cache[$itemId])) {
            Cache::forget($cache[$itemId]);
            unset($cache[$itemId]);
        }
        Cache::put($this->type . '_items', $cache, Config::get('strapi-wrapper.cache'));
    }

    public function delete(int $recordId): PromiseInterface|Response
    {
        $deleteUrl = $this->apiUrl . '/' . $this->type . '/' . $recordId;

        if ($this->authMethod === "public") {
            return Http::timeout($this->timeout)->delete($deleteUrl);
        }

        return Http::timeout($this->timeout)->withToken($this->getToken())->delete($deleteUrl);
    }
}
