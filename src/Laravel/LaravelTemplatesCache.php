<?php

namespace Keepsuit\Liquid\Laravel;

use Illuminate\Contracts\Cache\Repository;
use Keepsuit\Liquid\Contracts\LiquidTemplatesCache;
use Keepsuit\Liquid\ParsedTemplate;
use Keepsuit\Liquid\Template;
use RuntimeException;

/** Shared parsed-template cache. Cache storage must be application-controlled. */
final class LaravelTemplatesCache implements LiquidTemplatesCache
{
    private ?string $generation = null;

    public function __construct(
        private readonly Repository $store,
        private readonly string $namespace,
        private readonly int $ttl = 3600,
    ) {
        if ($ttl < 1) {
            throw new \InvalidArgumentException('Template cache TTL must be positive.');
        }
    }

    public function generation(): string
    {
        if ($this->generation !== null) {
            return $this->generation;
        }
        $key = $this->markerKey();
        $value = $this->store->get($key);
        if ($value === null) {
            // A positive TTL gives Redis an atomic SET NX path. Marker expiry is
            // safe: a new generation makes any older compiled artifacts unreachable.
            $this->store->add($key, bin2hex(random_bytes(16)), 315_360_000);
            $value = $this->store->get($key);
        }
        if (! is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new RuntimeException('Unable to initialize the Liquid cache namespace.');
        }

        return $this->generation = $value;
    }

    public function set(string $name, Template $template): void
    {
        if (! $template instanceof ParsedTemplate) {
            throw new \InvalidArgumentException('Shared template caching requires a parsed template.');
        }
        if (! $this->store->put($this->key($name), serialize($template), $this->ttl)) {
            throw new RuntimeException('Unable to store the Liquid template.');
        }
    }

    public function get(string $name): ?Template
    {
        $value = $this->store->get($this->key($name));
        if (! is_string($value)) {
            return null;
        }
        try {
            // Laravel cache stores themselves deserialize PHP values. This is a
            // trusted application cache, never a transport for untrusted payloads.
            $template = @unserialize($value);

            return $template instanceof ParsedTemplate ? $template : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    public function remove(string $name): void
    {
        $this->store->forget($this->key($name));
    }

    public function clear(): void
    {
        $generation = bin2hex(random_bytes(16));
        if (! $this->store->put($this->markerKey(), $generation, 315_360_000)) {
            throw new RuntimeException('Unable to invalidate the Liquid cache namespace.');
        }
        $this->generation = $generation;
    }

    private function markerKey(): string
    {
        return 'liquid:templates:'.hash('sha256', $this->namespace).':generation';
    }

    private function key(string $name): string
    {
        return $this->markerKey().':'.$this->generation().':'.hash('sha256', $name);
    }
}
