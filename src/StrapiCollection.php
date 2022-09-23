<?php

namespace SilentWeb\StrapiWrapper;


use Exception;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
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

    private bool $squashImage = false;
    private bool $absoluteUrl = false;
    private bool $convertMarkdown = false;
    private array $fields = [];
    private array $populate = [];

    public function __construct(string $type)
    {
        parent::__construct();
        $this->type = $type;
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

    public function getOne($cache = true)
    {
        $oldLimit = $this->limit;
        $this->limit = 1;
        $result = $this->get($cache);
        $this->limit = $oldLimit;
        if (isset($result[0])) return $result[0];
        return null;
    }

    public function get($cache = true)
    {
        $url = $this->generateQueryUrl(
            $this->type,
            $this->sortBy,
            $this->sortOrder,
            $this->limit,
            $this->page,
            $this->getPopulateQuery()
        );

        if (count($this->fields) > 0) {
            $filters = [];
            foreach ($this->fields as $field) {
                $filters[] = $field->url();
            }
            $url .= '&' . implode('&', ($filters));
        }

        $data = $this->getRequest($url, $cache);

        if (empty($data)) {
            throw new UnknownError(" - Strapi returned no data");
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

    private function getPopulateQuery(): string
    {
        if (empty($this->populate)) {
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

    /**
     * Query using a custom endpoint and fetch results
     * @param string $customType
     * @return array|null
     */
    public function getCustom(string $customType): array|null
    {
        $usualType = $this->type;
        $this->type = $customType;
        $response = $this->get(false);
        $this->type = $usualType;
        return $response;
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

    public function field(string $fieldName)
    {
        if (!isset($this->filters[$fieldName])) {
            $this->fields[$fieldName] = new StrapiField($fieldName, $this);
        }
        return $this->fields[$fieldName];
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
}
