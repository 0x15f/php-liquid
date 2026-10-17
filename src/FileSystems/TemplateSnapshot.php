<?php

namespace Keepsuit\Liquid\FileSystems;

use Closure;
use Keepsuit\Liquid\Contracts\LiquidFileSystem;
use Keepsuit\Liquid\Exceptions\FileSystemException;
use Keepsuit\Liquid\Exceptions\ResourceLimitException;

/** An authorized revision manifest. Sources are fetched only on template-cache misses. */
final readonly class TemplateSnapshot implements LiquidFileSystem
{
    /** @var array<string, string> */
    private array $manifest;

    private string $fingerprint;

    /**
     * @param  array<string, string>  $manifest  Authorized names mapped to SHA-256 source hashes.
     * @param  Closure(string, int): string  $load  Application-owned loader receiving name and maximum bytes.
     */
    public function __construct(array $manifest, private Closure $load, private int $sourceByteLimit = 262144)
    {
        if (count($manifest) > 1000 || $sourceByteLimit < 1) {
            throw new \InvalidArgumentException('Invalid template snapshot bounds.');
        }
        foreach ($manifest as $name => $hash) {
            if (strlen((string) $name) > 256 || ! preg_match('/\A[a-f0-9]{64}\z/', $hash)) {
                throw new \InvalidArgumentException('Template manifests require bounded names and SHA-256 hashes.');
            }
        }
        ksort($manifest);
        $this->manifest = $manifest;
        $this->fingerprint = hash('sha256', serialize([$manifest, $sourceByteLimit]));
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function withSourceLimit(int $bytes): self
    {
        if ($bytes >= $this->sourceByteLimit) {
            return $this;
        }

        return new self($this->manifest, $this->load, min($bytes, $this->sourceByteLimit));
    }

    public function assertAuthorized(string $name): void
    {
        if (! array_key_exists($name, $this->manifest)) {
            throw new FileSystemException('Template is not in the authorized manifest.');
        }
    }

    public function readTemplateFile(string $templateName): string
    {
        $expected = $this->manifest[$templateName] ?? throw new FileSystemException('Template is not in the authorized manifest.');
        $source = ($this->load)($templateName, $this->sourceByteLimit);
        if (strlen($source) > $this->sourceByteLimit) {
            throw new ResourceLimitException('Template source budget exceeded.');
        }
        if (! hash_equals($expected, hash('sha256', $source))) {
            throw new FileSystemException('Template source does not match the authorized revision.');
        }

        return $source;
    }
}
