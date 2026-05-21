<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:clear-all-cache')]
#[Description('Clear all application cache (config, route, view, event, cache)')]
class ClearAllCache extends Command
{
    public function handle(): int
    {
        $commands = [
            'cache:clear',
            'config:clear',
            'route:clear',
            'view:clear',
            'event:clear',
        ];

        foreach ($commands as $command) {
            $this->call($command);
        }

        $this->info('All cache cleared.');

        return self::SUCCESS;
    }
}
