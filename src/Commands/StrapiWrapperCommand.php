<?php

namespace SilentWeb\StrapiWrapper\Commands;

use Illuminate\Console\Command;

class StrapiWrapperCommand extends Command
{
    public $signature = 'strapi-wrapper';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
