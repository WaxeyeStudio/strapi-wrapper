<?php

namespace SilentWeb\StrapiWrapper\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \SilentWeb\StrapiWrapper\StrapiWrapper
 */
class StrapiWrapper extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'strapi-wrapper';
    }
}
