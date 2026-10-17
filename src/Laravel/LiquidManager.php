<?php

namespace Keepsuit\Liquid\Laravel;

use Composer\InstalledVersions;
use Illuminate\Contracts\Cache\Factory;
use Keepsuit\Liquid\Contracts\LiquidExtension;
use Keepsuit\Liquid\Environment;
use Keepsuit\Liquid\EnvironmentFactory;
use Keepsuit\Liquid\Filters\FiltersProvider;
use Keepsuit\Liquid\Render\ResourceLimits;
use Keepsuit\Liquid\Tag;
use Keepsuit\Liquid\TemplatesCache\CompiledTemplatesCache;
use Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache;

/**
 * @phpstan-type Config array{namespace: string, strict_variables: bool, strict_filters: bool, rethrow_errors: bool, cache: array{driver: string, store: ?string, path: string, prefix: string, version: string, ttl: int}, limits: array{render_length: ?int, render_score: ?int, assign_score: ?int, cumulative_render_score: ?int, cumulative_assign_score: ?int}, extensions: list<class-string<LiquidExtension>>, tags: list<class-string<Tag>>, filters: list<class-string<FiltersProvider>>}
 */
final class LiquidManager
{
    /** @var array<string, Environment> */
    private array $environments = [];

    /** @param Config $config */
    public function __construct(
        private readonly LaravelFileSystem $filesystem,
        private readonly Factory $cache,
        private readonly array $config,
    ) {}

    public function environment(?string $namespace = null): Environment
    {
        $namespace ??= $this->config['namespace'];
        if (isset($this->environments[$namespace])) {
            return $this->environments[$namespace];
        }

        return $this->createEnvironment($namespace, $this->filesystem);
    }

    /**
     * The caller must authorize the complete snapshot before passing it here.
     * Cache identity includes its content and access policy; names never fall back to Laravel views.
     *
     * @param  array<string, string>|\Keepsuit\Liquid\FileSystems\TemplateSnapshot  $templates
     */
    public function untrustedEnvironment(array|\Keepsuit\Liquid\FileSystems\TemplateSnapshot $templates, string $namespace, ?\Keepsuit\Liquid\Security\TemplatePolicy $policy = null): Environment
    {
        $policy ??= new \Keepsuit\Liquid\Security\TemplatePolicy;
        if (is_array($templates)) {
            $policy->assertTemplates($templates);
            ksort($templates);
            $fingerprint = hash('sha256', serialize($templates));
            $filesystem = new \Keepsuit\Liquid\FileSystems\ArrayFileSystem($templates);
        } else {
            $fingerprint = $templates->fingerprint();
            $filesystem = $templates;
        }
        $identity = 'untrusted:'.hash('sha256', serialize([$namespace, $fingerprint, $policy]));

        return $this->environments[$identity] ?? $this->createEnvironment($identity,
            $filesystem, $policy);
    }

    private function createEnvironment(string $namespace, \Keepsuit\Liquid\Contracts\LiquidFileSystem $filesystem, ?\Keepsuit\Liquid\Security\TemplatePolicy $policy = null): Environment
    {
        $config = $this->config['cache'];
        $identity = $this->identity($namespace);
        $shared = $config['store'] === null ? null : new LaravelTemplatesCache($this->cache->store($config['store']), $identity, $config['ttl']);
        $path = $config['path'].'/'.hash('sha256', $identity);
        $templatesCache = match ($config['driver']) {
            'memory' => new MemoryTemplatesCache,
            'compiled' => $shared === null ? new CompiledTemplatesCache($path) : new LaravelCompiledTemplatesCache($shared, $path),
            'cache' => $shared ?? throw new \InvalidArgumentException('Set liquid.cache.store for shared template caching.'),
            default => throw new \InvalidArgumentException('Unknown Liquid cache driver.'),
        };
        $limits = $this->config['limits'];
        $factory = ($policy === null ? EnvironmentFactory::new() : EnvironmentFactory::forUntrustedTemplates($policy))
            ->setFilesystem($filesystem)->setTemplatesCache($templatesCache)
            ->setStrictVariables($this->config['strict_variables'])->setStrictFilters($policy !== null || $this->config['strict_filters'])
            ->setRethrowErrors($policy !== null || $this->config['rethrow_errors']);
        $configuredLimits = new ResourceLimits($limits['render_length'], $limits['render_score'], $limits['assign_score'], $limits['cumulative_render_score'], $limits['cumulative_assign_score']);
        $factory->setResourceLimits($policy === null ? $configuredLimits : ResourceLimits::forUntrustedTemplates()->withLowerLimits($configuredLimits));
        foreach ($policy === null ? $this->config['extensions'] : [] as $extension) {
            $factory->addExtension(new $extension);
        }
        foreach ($policy === null ? $this->config['tags'] : [] as $tag) {
            $factory->registerTag($tag);
        }
        foreach ($policy === null ? $this->config['filters'] : [] as $filters) {
            $factory->registerFilters($filters);
        }

        return $this->environments[$namespace] = $factory->build();
    }

    /** @param array<string, mixed> $data */
    public function render(string $name, array $data = [], ?string $namespace = null): string
    {
        $environment = $this->environment($namespace);

        return $environment->parseTemplate($name)->render($environment->newRenderContext(data: $data));
    }

    /** @param array<string, mixed> $data */
    public function renderPath(string $path, array $data = [], ?string $namespace = null): string
    {
        return $this->render($this->filesystem->nameForPath($path), $data, $namespace);
    }

    /** @param array<string, mixed> $data */
    public function renderString(string $source, array $data = [], ?string $namespace = null): string
    {
        $environment = $this->environment($namespace);

        return $environment->parseString($source)->render($environment->newRenderContext(data: $data));
    }

    /** @param array<string, mixed> $data @return \Generator<int, string> */
    public function stream(string $name, array $data = [], ?string $namespace = null): \Generator
    {
        $environment = $this->environment($namespace);

        return $environment->parseTemplate($name)->stream($environment->newRenderContext(data: $data));
    }

    /** @param array<string, mixed> $data @return \Generator<int, string> */
    public function streamString(string $source, array $data = [], ?string $namespace = null): \Generator
    {
        $environment = $this->environment($namespace);

        return $environment->parseString($source)->stream($environment->newRenderContext(data: $data));
    }

    public function clear(?string $namespace = null): void
    {
        $this->environment($namespace)->templatesCache->clear();
        unset($this->environments[$namespace ?? $this->config['namespace']]);
    }

    private function identity(string $namespace): string
    {
        $reference = InstalledVersions::isInstalled('0x15f/liquid') ? InstalledVersions::getReference('0x15f/liquid') : null;

        return json_encode(['liquid-format-3', $reference ?? 'development', PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
            $this->config['cache']['prefix'], $this->config['cache']['version'], $namespace,
            hash('sha256', serialize([$this->config['extensions'], $this->config['tags'], $this->config['filters']]))], JSON_THROW_ON_ERROR);
    }
}
