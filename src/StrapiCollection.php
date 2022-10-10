<?php

namespace SilentWeb\StrapiWrapper;


use Exception;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use JsonException;
use SilentWeb\StrapiWrapper\Exceptions\UnknownError;

class StrapiCollection extends StrapiWrapper
{
    private array $collection = [];
    private array $meta = [];

    private string $type;
    private string $sortBy = 'id';
    private string $sortOrder = 'DESC';
    private int $limit = 100;
    private int $page = 0;

    private bool $squashImage;
    private bool $absoluteUrl;
    private bool $convertMarkdown = false;
    private array $fields = [];
    private array $populate = [];
    private int $deep;

    public function __construct(string $type)
    {
        parent::__construct();
        $this->type = $type;
        $this->deep = config('strapi-wrapper.populateDeep');
        $this->squashImage = config('strapi-wrapper.squashImage');
        $this->absoluteUrl = config('strapi-wrapper.absoluteUrl');
    }

    public function getOneOrFail($failCode = 404)
    {
        try {
            if ($result = $this->getOne()) {
                return $result;
            }
        } catch (Exception $ex) {
            Log::error($ex);
            throw new UnknownError($ex);
        }

        abort($failCode);
    }

    /**
     * @throws JsonException
     */
    public function getOne($cache = true)
    {
        // Limit request to 1 item, but remember the collection limit
        $oldLimit = $this->limit;
        $this->limit = 1;

        // We want to store the cache for individual items separately
        $url = md5($this->getUrl());
        if ($cache) {
            $result = Cache::remember($url, Config::get('strapi-wrapper.cache'), function () {
                return $this->get(false);
            });
        } else {
            $result = $this->get(false);
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

        return $url;
    }

    private function getPopulateQuery(): string
    {
        if (empty($this->populate)) {
            if ($this->deep > 0) {
                return '&populate=deep,' . $this->deep;
            }
            return '&populate=*';
        }

        $string = [];
        foreach ($this->populate as $key => $value) {
            if (is_numeric($key)) {
                $string[] = "populate[$value][populate]=*";
            } else {
                $string[] = "populate[$key][populate]=$value";
            }
        }
        return '&' . implode('&', $string);
    }

    public function get($cache = true)
    {
        $url = $this->getUrl();
        $data = $this->getRequest($url, $cache);

        if (empty($data)) {
            throw new UnknownError(" - Strapi returned no data");
        }

        // Store index in cache so that we can clear entire collection (for when tags are not supported)
        if ($cache) {
            $this->storeCacheIndex($url);
        }

        if ($this->apiVersion === 3) {
            $this->meta = ['response' => time()];
        } else {
            $data = $this->squashDataFields($data);
            if (!isset($data['meta'])) {
                $this->meta = ['response' => time()];
            } else if (!is_null($data['meta'])) {
                $this->meta = array_merge(['response' => time()], $data['meta']);
                unset($data['meta']);
            }
        }

        if ($this->absoluteUrl) {
            $data = $this->convertToAbsoluteUrls($data);
        }

        if ($this->squashImage) {
            $data = $this->convertImageFields($data);
        }

        $this->collection = $data;

        return $this->collection;
    }

    private function storeCacheIndex($requestUrl): void
    {
        $index = Cache::get($this->type, []);
        $index[] = $requestUrl;
        Cache::put($this->type, array_unique($index), Config::get('strapi-wrapper.cache'));
    }

    public function getOneByIdOrFail($id, $errorCode = 404): ?array
    {
        $data = $this->getOneById($id);
        if (!$data) {
            abort($errorCode);
        }
        return $data;
    }

    public function getOneById($id): array|null
    {
        $currentFilters = $this->fields;
        $this->clearAllFilters();

        // In a default strapi instance, the id can be fetched from /collection-name/id
        // So we will try this first
        $data = null;
        try {
            $data = $this->getCustom('/' . $id);
        } catch (Exception $e) {
            // This hasn't worked, so lets try querying the main collection
            try {
                $this->field('id')->filter('$eq', $id);
                $data = $this->getOne();
            } catch (Exception $e) {
                // Still failed, so we return null;
                $this->fields = $currentFilters;
                return null;
            }
        }

        $this->fields = $currentFilters;
        return $data;
    }

    public function clearAllFilters(bool $refresh = false): static
    {
        $this->fields = [];
        if ($refresh) {
            $this->get(false);
        }
        return $this;
    }

    /**
     * Query using a custom endpoint and fetch results
     * @param string $customType
     * @return mixed
     */
    public function getCustom(string $customType): mixed
    {
        $usualType = $this->type;
        $this->type .= $customType;
        $response = $this->get();
        $this->type = $usualType;
        if (count($response) === 1 && isset($response[0])) {
            return $response[0];
        }
        return $response;
    }

    public function field(string $fieldName)
    {
        if (!isset($this->filters[$fieldName])) {
            $this->fields[$fieldName] = new StrapiField($fieldName, $this);
        }
        return $this->fields[$fieldName];
    }

    /**
     * @param int $limit
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
     */
    public function getCollection(): array
    {
        return $this->collection;
    }

    /**
     * @return array
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    public function setOptions(array $options): StrapiCollection
    {
        $configurableOptions = [
            'absoluteUrl', 'squashImage',
            'sortBy', 'sortOrder', 'limit', 'page'
        ];

        foreach ($options as $key => $value) {
            if (in_array($key, $configurableOptions, true)) {
                $this->$key = $value;
            }
        }
        return $this;
    }

    public function absolute(): StrapiCollection
    {
        $this->absoluteUrl = true;
        return $this;
    }

    public function squash(): StrapiCollection
    {
        $this->squashImage = true;
        return $this;
    }

    /**
     * Will tell the CMS to sort by the field indicated using the last set order.
     * To change the order use $->order($sortBy, $ascending) method
     * @param string $sortBy
     * @return $this
     */
    public function sort(string $sortBy): StrapiCollection
    {
        $this->sortBy = $sortBy;
        return $this;
    }

    public function limit(string $limit): StrapiCollection
    {
        $this->limit = $limit;
        return $this;
    }

    public function page(int $page): StrapiCollection
    {
        $this->page = $page;
        return $this;
    }

    public function post(array $fields): PromiseInterface|Response
    {
        $url = $this->generatePostUrl($this->type);
        return $this->postRequest($url, $fields);
    }

    public function postFiles(array $fields, array $files): PromiseInterface|Response
    {
        $url = $this->generatePostUrl($this->type);
        $multipart = [
            'multipart' => [],
            'data' => json_encode($fields, JSON_THROW_ON_ERROR),
        ];
        foreach ($files as $field => $file) {
            $multipart['multipart'][] = [
                'name' => $field,
                'contents' => fopen($file['path'], 'rb'),
                'filename' => $file['name'] ?? null
            ];
        }

        return $this->postMulitpartRequest($url, $multipart);
    }

    public function apiVersion()
    {
        return $this->apiVersion;
    }

    /**
     * Will tell the CMS to sort by the field indicated and adjust the order.
     * To just change the sort field use $->sort($sortBy) method
     * @param string $sortBy
     * @param bool $ascending
     * @return $this
     */
    public function order(string $sortBy, bool $ascending = false): StrapiCollection
    {
        $this->sortBy = $sortBy;
        $this->sortOrder = $ascending ? 'ASC' : 'DESC';
        return $this;
    }

    public function populate(array $populateQuery = []): StrapiCollection
    {
        $this->populate = $populateQuery;
        return $this;
    }

    // Clear the entire collection cache (excluding items called with ->getOne())

    public function clearCollectionCache(bool $includingItems = false): void
    {
        $this->clearCache($this->type);
        if ($includingItems) {
            $this->clearCache($this->type . '_items');
        }
    }

    // Clear any cached item for the collection

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

    public function clearItemCache($itemId): void
    {
        $cache = Cache::pull($this->type . '_items', []);
        if (isset($cache[$itemId])) {
            Cache::forget($cache[$itemId]);
            unset($cache[$itemId]);
        }
        Cache::put($this->type . '_items', $cache, Config::get('strapi-wrapper.cache'));
    }
}
