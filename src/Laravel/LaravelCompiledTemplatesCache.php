<?php

namespace Keepsuit\Liquid\Laravel;

use Keepsuit\Liquid\Contracts\LiquidTemplatesCache;
use Keepsuit\Liquid\Template;
use Keepsuit\Liquid\TemplatesCache\CompiledTemplatesCache;

/** Local OPcache-compatible PHP artifacts backed by a shared parsed-template cache. */
final class LaravelCompiledTemplatesCache implements LiquidTemplatesCache
{
    private ?CompiledTemplatesCache $local = null;

    private ?string $generation = null;

    public function __construct(
        private readonly LaravelTemplatesCache $shared,
        private readonly string $directory,
    ) {}

    public function set(string $name, Template $template): void
    {
        $this->local()->set($name, $template);
        $this->shared->set($name, $template);
    }

    public function get(string $name): ?Template
    {
        $local = $this->local();
        $template = $local->get($name);
        if ($template !== null) {
            return $template;
        }
        $template = $this->shared->get($name);
        if ($template === null) {
            return null;
        }
        $local->set($name, $template);

        return $local->get($name);
    }

    public function has(string $name): bool
    {
        return $this->local()->has($name) || $this->shared->has($name);
    }

    public function remove(string $name): void
    {
        // Rotate the entire namespace: other hosts may still have this name as
        // compiled PHP. A shared generation invalidates those copies as well.
        $this->clear();
    }

    public function clear(): void
    {
        // Keep old generation files for requests still rendering that snapshot.
        // They can be pruned after old workers/requests have drained.
        $this->shared->clear();
        $this->local = null;
    }

    private function local(): CompiledTemplatesCache
    {
        $generation = $this->shared->generation();
        if ($this->local === null || $this->generation !== $generation) {
            $this->generation = $generation;
            $this->local = new CompiledTemplatesCache($this->directory.'/'.$generation, keepInMemory: false);
        }

        return $this->local;
    }
}
