<?php

namespace Keepsuit\Liquid\Laravel;

use Illuminate\View\ViewFinderInterface;
use Keepsuit\Liquid\Contracts\LiquidFileSystem;
use Keepsuit\Liquid\Exceptions\FileSystemException;

final class LaravelFileSystem implements LiquidFileSystem
{
    /** @var array<string, string> */
    private array $paths = [];

    public function __construct(private readonly ViewFinderInterface $finder) {}

    public function nameForPath(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved === false || ! str_ends_with($resolved, '.liquid')) {
            throw new FileSystemException('Liquid views must resolve to a .liquid file.');
        }
        $name = '__liquid_path/'.hash('sha256', $resolved);
        $this->paths[$name] = $resolved;

        return $name;
    }

    public function readTemplateFile(string $templateName): string
    {
        $path = $this->paths[$templateName] ?? $this->finder->find($templateName);
        if (! str_ends_with($path, '.liquid')) {
            throw new FileSystemException('Liquid cannot render a view of another template type.');
        }
        $source = file_get_contents($path);
        if ($source === false) {
            throw new FileSystemException('Unable to read the Liquid view.');
        }

        return $source;
    }
}
