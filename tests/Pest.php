<?php

use SilentWeb\StrapiWrapper\StrapiCollection;
use SilentWeb\StrapiWrapper\Tests\TestCase;
use function PHPUnit\Framework\assertTrue;

uses(TestCase::class)->in(__DIR__);

it('get articles from strapi', function () {
    // Using the Strapi V3 Blog Quickstart, there should be some public articles
    $blog = new StrapiCollection('articles');

    $posts = $blog->recent(1)->absolute()->squash()->get(false);
    assertTrue(count($posts) > 0);
});

it('get protected articles from strapi', function () {
    // Starting with Strapi V3 Blog Quickstart, there should be some articles
    // Creating a copy of them in a secure "protected-articles" content type, we
    // should be able to access them
    config()->set('strapi-wrapper.auth', 'password');

    $blog = new StrapiCollection('protected-articles');

    $posts = $blog->recent(1)->absolute()->squash()->get(false);
    assertTrue(count($posts) > 0);
});

it('version 4 get blog posts from strapi', function () {
    TestCase::version4Tests();
    $blog = new StrapiCollection('blog-posts');
    $posts = $blog->recent(4)->absolute()->squash()->get(false);
    dump($posts);
    assertTrue(count($posts) > 0);
});
