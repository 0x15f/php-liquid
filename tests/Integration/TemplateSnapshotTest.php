<?php

use Keepsuit\Liquid\EnvironmentFactory;
use Keepsuit\Liquid\Exceptions\FileSystemException;
use Keepsuit\Liquid\FileSystems\ArrayFileSystem;
use Keepsuit\Liquid\FileSystems\TemplateSnapshot;

final class SnapshotCapabilityTag extends Keepsuit\Liquid\Tag
{
    public static function tagName(): string
    {
        return 'snapshot_capability';
    }

    public function parse(Keepsuit\Liquid\Parse\TagParseContext $context): static
    {
        return $this;
    }

    public function render(Keepsuit\Liquid\Render\RenderContext $context): string
    {
        return 'approved capability';
    }
}

test('authorized manifest sources are lazy and skipped on compiled cache hits', function () {
    $sources = ['page' => '{% render "card" %}', 'card' => 'Bag', 'unused' => 'Unused'];
    $manifest = array_map(fn (string $source) => hash('sha256', $source), $sources);
    $loads = [];
    $snapshot = new TemplateSnapshot($manifest, function (string $name, int $maxBytes) use ($sources, &$loads): string {
        expect($maxBytes)->toBe(262144);
        $loads[] = $name;

        return $sources[$name];
    });
    $cache = testCompiledTemplatesCache();
    $first = EnvironmentFactory::forUntrustedTemplates()->setFilesystem($snapshot)->setTemplatesCache($cache)->build();
    expect($loads)->toBe([]);
    expect($first->parseTemplate('page')->render($first->newRenderContext()))->toBe('Bag');
    expect($loads)->toBe(['page', 'card']);
    $second = EnvironmentFactory::forUntrustedTemplates()->setFilesystem($snapshot)->setTemplatesCache($cache)->build();
    expect(implode('', iterator_to_array($second->parseTemplate('page')->stream($second->newRenderContext()))))->toBe('Bag');
    expect($loads)->toBe(['page', 'card']);
    expect(fn () => $second->parseTemplate('private'))->toThrow(FileSystemException::class);
    expect($loads)->toBe(['page', 'card']);
});

test('a source mismatch cannot populate an authorized revision cache', function () {
    $snapshot = new TemplateSnapshot(['page' => hash('sha256', 'expected')], fn (string $name, int $maxBytes) => 'modified');
    $environment = EnvironmentFactory::forUntrustedTemplates()->setFilesystem($snapshot)->build();
    expect(fn () => $environment->parseTemplate('page'))->toThrow(FileSystemException::class, 'authorized revision');
});

test('restricted cache keys separate source snapshots even with a shared underlying cache', function () {
    $cache = testCompiledTemplatesCache();
    $a = EnvironmentFactory::forUntrustedTemplates()->setFilesystem(new ArrayFileSystem(['page' => 'Tenant A']))->setTemplatesCache($cache)->build();
    $b = EnvironmentFactory::forUntrustedTemplates()->setFilesystem(new ArrayFileSystem(['page' => 'Tenant B']))->setTemplatesCache($cache)->build();
    expect($a->parseTemplate('page')->render($a->newRenderContext()))->toBe('Tenant A');
    expect($b->parseTemplate('page')->render($b->newRenderContext()))->toBe('Tenant B');
    expect($a->parseTemplate('page')->render($a->newRenderContext()))->toBe('Tenant A');
    $cache->set('private', EnvironmentFactory::new()->build()->parseString('secret'));
    expect(fn () => $a->parseTemplate('private'))->toThrow(FileSystemException::class);
});

test('a cached AST cannot restore a PHP tag absent from the current capability set', function () {
    $cache = testCompiledTemplatesCache();
    $sources = ['page' => '{% snapshot_capability %}'];
    $broader = EnvironmentFactory::forUntrustedTemplates()->registerTag(SnapshotCapabilityTag::class)
        ->setFilesystem(new ArrayFileSystem($sources))->setTemplatesCache($cache)->build();
    expect($broader->parseTemplate('page')->render($broader->newRenderContext()))->toBe('approved capability');
    $narrower = EnvironmentFactory::forUntrustedTemplates()->setFilesystem(new ArrayFileSystem($sources))->setTemplatesCache($cache)->build();
    expect(fn () => $narrower->parseTemplate('page'))->toThrow(Keepsuit\Liquid\Exceptions\SyntaxException::class);
    expect(fn () => $broader->tagRegistry->delete('snapshot_capability'))->toThrow(Keepsuit\Liquid\Exceptions\TemplateSecurityException::class, 'sealed');
    expect(fn () => $broader->filterRegistry->delete(Keepsuit\Liquid\Filters\StandardFilters::class))->toThrow(Keepsuit\Liquid\Exceptions\TemplateSecurityException::class, 'sealed');
    expect(fn () => $broader->addExtension(new Keepsuit\Liquid\Extensions\StandardExtension))->toThrow(Keepsuit\Liquid\Exceptions\TemplateSecurityException::class, 'sealed');
});
