<?php

namespace SilentWeb\StrapiWrapper;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

class StrapiUploads extends StrapiWrapper
{
    public function __construct()
    {
        parent::__construct();
        if (($this->apiVersion === 4 || $this->apiVersion === 5) && Str::endsWith($this->apiUrl, '/api')) {
            $this->apiUrl .= '/upload';
        }
    }

    public function delete(int $imageId): PromiseInterface|Response
    {
        $client = $this->httpClient();

        if ($this->authMethod !== 'public') {
            $client = $client->withToken($this->getToken());
        }

        return $client->delete($this->apiUrl.'/files/'.$imageId);
    }
}
