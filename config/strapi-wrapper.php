<?php
// config for SilentWeb/StrapiWrapper
$strapiUrl = rtrim(env('STRAPI_URL', 'http://localhost:1337'), '/');
$uploadUrl = env('STRAPI_IMAGES', '');
$apiVersion = env('STRAPI_VERSION', '3'); // VERSION 3 or 4
if ($uploadUrl === '') {
    if ($apiVersion === "4") {
        $uploadUrl = preg_replace('/\/api$/', '', $strapiUrl);
    } else $uploadUrl = $strapiUrl;
}

return [
    'url' => $strapiUrl,
    'auth' => env('STRAPI_AUTH', 'public'), // VALID OPTIONS ARE 'public', 'password', 'token'
    'version' => $apiVersion,
    'cache' => env('STRAPI_CACHE', 3600),

    'username' => env('STRAPI_USER', ''),
    'password' => env('STRAPI_PASS', ''),

    // Token is only supported in V4 of strapi
    'token' => env('STRAPI_TOKEN', ''),

    // URL for Images (if different from API url)
    'uploadUrl' => $uploadUrl
];
