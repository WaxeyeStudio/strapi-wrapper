<?php

namespace SilentWeb\StrapiWrapper;

use Exception;
use GuzzleHttp\Promise\PromiseInterface;
use http\Exception\RuntimeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SilentWeb\StrapiWrapper\Exceptions\BadRequest;
use SilentWeb\StrapiWrapper\Exceptions\ConnectionError;
use SilentWeb\StrapiWrapper\Exceptions\PermissionDenied;
use SilentWeb\StrapiWrapper\Exceptions\UnknownAuthMethod;
use SilentWeb\StrapiWrapper\Exceptions\UnknownError;
use Throwable;

class StrapiWrapper
{
    protected const DEFAULT_RECORD_LIMIT = 100;
    protected int $apiVersion;
    protected int $cacheTimeout;
    protected array $squashedData = [];
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

        $allowedAuthMethods = ['public', 'password', 'token'];
        if ($this->apiVersion <= 3) {
            throw new ConnectionError('API version not supported');
        }

        if (!in_array($this->authMethod, $allowedAuthMethods, true)) {
            throw new UnknownAuthMethod();
        }
    }

    public function setTimeout(int $timeout): StrapiWrapper
    {
        if ($timeout) {
            $this->timeout = $timeout;
        }

        return $this;
    }

    public function strapiPost($query, $content, $files = null): PromiseInterface|Response
    {

        if ($files) {
            return $this->postMultipartRequest($this->apiUrl . $query, [
                'data' => $content,
                'files' => $files,
            ]);
        }

        return $this->postRequest($this->apiUrl . $query, $content);
    }

    protected function postMultipartRequest($query, $content): PromiseInterface|Response
    {
        if ($this->authMethod !== 'public') {
            $client = $this->httpClient()->withToken($this->getToken())->asMultipart();
            foreach ($content['multipart'] as $file) {
                $name = 'files';
                if ($file['name'] !== 'files') {
                    $name .= '.' . $file['name'];
                }

                $client->attach($name, $file['contents'], $file['filename'] ?? null);
            }

            $response = $client->post($query, ['data' => $content['data']]);
            if (!$response->ok()) {
                if ($response->status() === 400) {
                    $json = $response->json();
                    $this->log("Post error", 'error', $json);
                    throw new BadRequest($query . ' ' . $json['error']['name'] . ' - ' . $json['error']['message'], 400);
                }

                throw new UnknownError('Error posting to strapi on ' . $query, $response->status());
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

    protected function getToken($preventLoop = false): string
    {
        if ($this->authMethod === 'token') {
            return $this->token;
        }

        $token = Cache::remember('strapi-token', 600, function () {
            return self::loginStrapi();
        });

        try {
            $decodedToken = json_decode(base64_decode(str_replace('_', '/', str_replace('-', '+', explode('.', $token)[1]))), false, 512, JSON_THROW_ON_ERROR);

            if ($decodedToken->exp < time()) {
                Cache::forget('strapi-token');
                if ($preventLoop)
                    abort(503);
                return self::getToken(1);
            }
        } catch (Throwable $th) {
            throw new UnknownError('Issue with fetching token ' . $th);
        }
        return $token;
    }

    private function loginStrapi()
    {
        $login = null;
        try {
            $login = $this->httpClient()->post($this->apiUrl . '/auth/local', [
                "identifier" => $this->username,
                "password" => $this->password,
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
                    throw new PermissionDenied();
                }

                if ($login->status() === 400) {
                    throw new BadRequest("Bad request in strapi login", 400, $login->toException());
                }

                throw new UnknownError($login->toException());
            }

            throw new RuntimeException("Error initialising strapi wrapper");
        }
    }

    protected function log($message, $level = 'error', $context = [])
    {
        if ($this->debugLoggingEnabled) {
            Log::log($level, $message, $context);
        }

    }

    protected function postRequest($query, $content): PromiseInterface|Response
    {
        if ($this->apiVersion >= 4) {
            $content = ['data' => $content];
        }

        $postClient = $this->httpClient();
        if ($this->authMethod !== 'public') {
            $postClient = $postClient->withToken($this->getToken());
        }

        $response = null;
        try {
            $response = $postClient->post($query, $content);
        } catch (Exception $exception) {
            throw new UnknownError($exception);
        }

        if ($response && !$response->ok() && !$response->created()) {
            if ($response->status() === 400) {
                throw new BadRequest($query . ' ' . $response->body(), 400);
            }

            throw new UnknownError('Error posting to strapi on ' . $query, $response->status());
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
        if ($this->authMethod === 'public') {
            $response = $this->httpClient()->get($request);
        } else {
            $response = $this->httpClient()->withToken($this->getToken())->get($request);
        }

        if ($response->ok()) {
            return $response->json();
        }

        if ($response->status() === 400) {
            throw new BadRequest($request . ' ' . $response->body(), 400);
        }

        throw new UnknownError($response->body());
    }

    protected function generateQueryUrl(string $type, string|array $sortBy, string $sortOrder, int $limit, int $page, string $customQuery = ''): string
    {
        $concat = str_contains($type, '?') ? '&' : '?';
        $url = [$this->generateSortUrl($sortBy, $sortOrder)];

        if ($this->apiVersion === 3) {
            if ($limit !== self::DEFAULT_RECORD_LIMIT) {
                $url[] = '_limit=' . $limit;
            }

            $url[] = '_start=' . $page;
        }

        if ($this->apiVersion === 4 || $this->apiVersion === 5) {
            if ($limit !== self::DEFAULT_RECORD_LIMIT) {
                $url[] = 'pagination[pageSize]=' . $limit;
            }
            if ($page !== 1) {
                $url[] = 'pagination[page]=' . $page;
            }

            $url[] = $customQuery;
        }

        $url = array_filter($url);

        return $this->apiUrl . '/' . $type . $concat . implode('&', $url);
    }

    protected function generateSortUrl(string|array $sortBy = [], $defaultSortOrder = 'DESC'): ?string
    {
        if (empty($sortBy)) {
            return null;
        }

        $key = $this->apiVersion === 3 ? '_sort' : 'sort';


        if (!is_array($sortBy)) {
            return $key . '=' . $sortBy . ':' . $defaultSortOrder;
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

            $string[] = $key . '[' . $index . ']=' . $sort . ':' . $order;
        }
        return implode('&', $string);
    }

    protected function generatePostUrl(string $type): string
    {
        return $this->apiUrl . '/' . $type;
    }

    protected function convertToAbsoluteUrls($array): array
    {
        foreach ($array as $key => $item) {
            // If value is array, call func on self to check values
            if (is_array($item)) {
                $array[$key] = $this->convertToAbsoluteUrls($item);
            }

            // If value is null or empty, stop
            if (!is_string($item) || empty($item)) {
                continue;
            }


            // If this is an image/file key - replace with URL in front
            if ($key === 'url' && isset($array['ext']) && str_starts_with($item, '/')) {
                $array[$key] = $this->imageUrl . $array[$key];
            } else {
                // NB: By default strapi returns markdown, but popular editor plugins make the return HTML

                // If HTML Text
                /** @noinspection RegExpDuplicateCharacterInClass */
                $html_pattern = '/<img([^>]*) src=[\'|"][^http|ftp|https]([^"|^\']*)\"/';
                $html_rewrite = "<img\${1} src=\"" . $this->imageUrl . "/\${2}\"";

                // If Markdown text
                $markdown_pattern = '/!\[(.*)]\((.*)\)/';
                $markdown_rewrite = '![$1](' . $this->imageUrl . '$2)';
                $array[$key] = preg_replace([$html_pattern, $markdown_pattern], [$html_rewrite, $markdown_rewrite], $item);
            }
        }

        return $array;
    }

    protected function convertImageFields($array, $parent = null): array
    {
        if (!$parent) {
            // Reset squash data
            $this->squashedData = [];
            $parent = '';
        }

        foreach ($array as $key => $item) {
            if (is_array($item)) {
                if (array_key_exists('url', $item) && array_key_exists('mime', $item)) {
                    $array[$key] = $item['url'];
                    if (!is_numeric($key)) {
                        // To make things easier, for non array images we can store attributes alongside
                        $array[$key . '_squash'] = $item;
                    }

                    // We also should store the squashed data separately
                    $this->squashedData[$parent . $key] = $item;
                } else {
                    $array[$key] = $this->convertImageFields($item, $parent . $key . '.');
                }
            }
        }

        return $array;
    }

    protected function squashDataFields($array): array|null
    {
        // Check if this response is an array, if not there is nothing to squash
        if (!is_array($array)) {
            return [$array];
        }

        $modifiedArray = [];
        foreach ($array as $key => $item) {
            if (is_array($item)) {
                if ($key === "attributes" || $key === "data") {
                    if ($key === "data" && isset($modifiedArray['id'], $item['id'])) {
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
