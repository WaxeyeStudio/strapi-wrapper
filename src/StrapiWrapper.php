<?php

namespace SilentWeb\StrapiWrapper;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SilentWeb\StrapiWrapper\Exceptions\BadRequest;
use SilentWeb\StrapiWrapper\Exceptions\PermissionDenied;
use SilentWeb\StrapiWrapper\Exceptions\UnknownAuthMethod;
use SilentWeb\StrapiWrapper\Exceptions\UnknownError;
use Throwable;

class StrapiWrapper
{
    protected int $apiVersion;
    protected int $cacheTimeout;
    protected array $squashedData = [];
    private string $apiUrl;
    private string $imageUrl;
    private string $username;
    private string $password;
    private string $token;
    private string $authMethod;

    protected function __construct()
    {
        $this->apiUrl = config('strapi-wrapper.url');
        $this->imageUrl = config('strapi-wrapper.uploadUrl');
        $this->apiVersion = config('strapi-wrapper.version');
        $this->username = config('strapi-wrapper.username');
        $this->password = config('strapi-wrapper.password');
        $this->token = config('strapi-wrapper.token');
        $this->authMethod = config('strapi-wrapper.auth');
        $this->cacheTimeout = config('strapi-wrapper.cache');

        $allowedAuthMethods = ['public', 'password'];
        if ($this->apiVersion >= 4) $allowedAuthMethods[] = 'token';
        if (!in_array($this->authMethod, $allowedAuthMethods, true)) throw new UnknownAuthMethod();
    }

    protected function generateQueryUrl(string $type, string $sortBy, string $sortOrder, int $limit, int $page, string $customQuery = ''): string
    {
        $concat = str_contains($type, '?') ? '&' : '?';
        if ($this->apiVersion === 3) {
            return $this->apiUrl . '/' . $type . $concat . '_sort=' . $sortBy . ':' . $sortOrder . '&_limit=' . $limit . '&_start=' . $page;
        }

        return $this->apiUrl . '/' . $type . $concat . 'sort=' . $sortBy . ':' . $sortOrder . '&pagination[pageSize]=' . $limit . '&pagination[page]=' . $page . $customQuery;
    }

    protected function generatePostUrl(string $type): string
    {
        return $this->apiUrl . '/' . $type;
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
            $response = Http::get($request);
        } else {
            $response = Http::withToken($this->getToken())->get($request);
        }

        if ($response->ok()) return $response->json();
        else throw new UnknownError();
    }

    private function getToken($preventLoop = false): string
    {
        if ($this->authMethod === 'token') return $this->token;
        else {
            $token = Cache::remember('strapi-token', 600, function () {
                return self::loginStrapi();
            });
        }

        try {
            $decodedToken = json_decode(base64_decode(str_replace('_', '/', str_replace('-', '+', explode('.', $token)[1]))), false);

            if ($decodedToken->exp < time()) {
                Cache::forget('strapi-token');
                if ($preventLoop) abort(503);
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
            $login = Http::post($this->apiUrl . '/auth/local', [
                "identifier" => $this->username,
                "password" => $this->password
            ]);
        } catch (Throwable $th) {
            throw new UnknownError($th);
        } finally {
            if ($login && $login->ok()) {
                return $login->json()['jwt'];
            }

            throw new PermissionDenied();
        }
    }

    protected function postRequest($query, $content): PromiseInterface|Response
    {
        if ($this->authMethod !== 'public') {
            if ($this->apiVersion === 4) {
                $content = ['data' => $content];
            }

            $response = Http::withToken($this->getToken())->post($query, $content);

        } else {
            $response = Http::post($query, $content);
        }

        if (!$response->ok()) {
            if ($response->status() === 400) {
                throw new BadRequest($query . ' ' . $response->body(), 400);
            }

            throw new UnknownError('Error posting to strapi on ' . $query, $response->status());
        }
        return $response;
    }

    protected function postMultipartRequest($query, $content): PromiseInterface|Response
    {
        if ($this->authMethod !== 'public') {
            $client = Http::withToken($this->getToken())->asMultipart();
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
                    throw new BadRequest($query . ' ' . $response->json(), 400);
                }

                throw new UnknownError('Error posting to strapi on ' . $query, $response->status());
            }
            return $response;
        }

        // TODO: implementation of multipart request for non authenticated requests
        throw new UnknownError('Not authenticated');
    }

    protected function convertToAbsoluteUrls($array): array
    {
        foreach ($array as $key => $item) {
            // If value is array, call func on self to check values
            if (is_array($item)) $array[$key] = $this->convertToAbsoluteUrls($item);

            // If value is null or empty, stop
            if (!is_string($item) || empty($item)) continue;


            // If this is an image/file key - replace with URL in front
            if ($key === 'url' && isset($array['ext']) && str_starts_with($item, '/')) $array[$key] = $this->imageUrl . $array[$key];
            else {
                // NB: By default strapi returns markdown, but popular editor plugins make the return HTML

                // If HTML Text
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
