<?php

namespace SilentWeb\StrapiWrapper\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SilentWeb\StrapiWrapper\StrapiWrapperServiceProvider;

class TestCase extends Orchestra
{
    public static function version4Tests()
    {
        self::commonTests();
        config()->set('strapi-wrapper.url', 'http://localhost:1337/api');
        config()->set('strapi-wrapper.version', 4);
    }

    public static function commonTests()
    {
        config()->set('strapi-wrapper.username', 'website');
        config()->set('strapi-wrapper.password', 'password');
    }

    public function getEnvironmentSetUp($app)
    {
        $this->version3Tests();
    }

    public static function version3Tests()
    {
        self::commonTests();
        config()->set('strapi-wrapper.url', 'http://localhost:1338');
        config()->set('strapi-wrapper.version', 3);
    }

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            StrapiWrapperServiceProvider::class,
        ];
    }
}
