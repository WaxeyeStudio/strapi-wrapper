<?php
// config for SilentWeb/StrapiWrapper
$strapiUrl = rtrim(env('STRAPI_URL', 'http://localhost:1337'), '/');
$uploadUrl = env('STRAPI_IMAGES', '');
$apiVersion = env('STRAPI_VERSION', '4'); // VERSION 4
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
    'uploadUrl' => $uploadUrl,

    // For V4, if the "populate deep" plugin is installed, you may want to enable this option
    // https://github.com/Barelydead/strapi-plugin-populate-deep
    'populateDeep' => env('STRAPI_DEEP', 0),

    'squashImage' => env('STRAPI_SQUASH', false),
    'absoluteUrl' => env('STRAPI_ABSOLUTE', false),

    // Wait time for curl
    'timeout' => env('STRAPI_TIMEOUT', 60),
];
