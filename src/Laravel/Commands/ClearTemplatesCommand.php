<?php

namespace Keepsuit\Liquid\Laravel\Commands;

use Illuminate\Console\Command;
use Keepsuit\Liquid\Laravel\LiquidManager;

final class ClearTemplatesCommand extends Command
{
    protected $signature = 'liquid:clear {--namespace= : Template namespace to invalidate}';

    protected $description = 'Invalidate Liquid templates without flushing unrelated application cache entries';

    public function handle(LiquidManager $manager): int
    {
        $namespace = $this->option('namespace');
        $manager->clear(is_string($namespace) ? $namespace : null);
        $this->components->info('Liquid template cache invalidated.');

        return self::SUCCESS;
    }
}
