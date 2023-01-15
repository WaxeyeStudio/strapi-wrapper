<?php

namespace SilentWeb\StrapiWrapper;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class StrapiUploads extends StrapiWrapper
{
    public function __construct()
    {
        parent::__construct();
        if ($this->apiVersion === 4 && Str::endsWith($this->apiUrl, '/api')) {
            $this->apiUrl .= '/upload';
        }
    }

    public function delete(int $imageId): PromiseInterface|Response
    {
        if ($this->authMethod === 'public') {
            $response = Http::timeout($this->timeout)->delete($this->apiUrl . '/files/' . $imageId);
        } else {
            $response = Http::timeout($this->timeout)->withToken($this->getToken())->delete($this->apiUrl . '/files/' . $imageId);
        }

        return $response;
    }
}
