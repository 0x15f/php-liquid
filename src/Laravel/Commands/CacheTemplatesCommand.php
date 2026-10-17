<?php

namespace Keepsuit\Liquid\Laravel\Commands;

use Illuminate\Console\Command;
use Keepsuit\Liquid\Laravel\LiquidManager;

final class CacheTemplatesCommand extends Command
{
    protected $signature = 'liquid:cache {views* : Liquid view names to warm} {--namespace= : Template namespace}';

    protected $description = 'Warm named Liquid templates and their static snippets without rendering request data';

    public function handle(LiquidManager $manager): int
    {
        $namespace = $this->option('namespace');
        $environment = $manager->environment(is_string($namespace) ? $namespace : null);
        $names = $this->argument('views');
        if (! is_array($names) || $names === []) {
            $this->components->error('Provide at least one Liquid view name.');

            return self::INVALID;
        }
        foreach ($names as $name) {
            if (! is_string($name)) {
                return self::INVALID;
            }
            $environment->parseTemplate($name);
        }
        $this->components->info('Liquid templates warmed.');

        return self::SUCCESS;
    }
}
