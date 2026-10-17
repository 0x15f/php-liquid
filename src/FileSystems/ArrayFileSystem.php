<?php

namespace Keepsuit\Liquid\FileSystems;

use Keepsuit\Liquid\Contracts\LiquidFileSystem;
use Keepsuit\Liquid\Exceptions\FileSystemException;

/** An application-authorized, immutable tenant/theme snapshot. No filesystem fallback. */
final class ArrayFileSystem implements LiquidFileSystem
{
    /** @param array<string, string> $templates */
    public function __construct(private readonly array $templates)
    {
        $sorted = $templates;
        ksort($sorted);
        $this->fingerprint = hash('sha256', serialize($sorted));
    }

    private readonly string $fingerprint;

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function assertAuthorized(string $name): void
    {
        if (! array_key_exists($name, $this->templates)) {
            throw new FileSystemException('Template is not in the authorized snapshot.');
        }
    }

    public function assertPolicy(\Keepsuit\Liquid\Security\TemplatePolicy $policy): void
    {
        $policy->assertTemplates($this->templates);
    }

    public function readTemplateFile(string $templateName): string
    {
        return $this->templates[$templateName] ?? throw new FileSystemException('Template is not in the authorized snapshot.');
    }
}
