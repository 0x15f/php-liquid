<?php

namespace Keepsuit\Liquid\Security;

use Keepsuit\Liquid\Drops\ForLoopDrop;
use Keepsuit\Liquid\Drops\SelfDrop;
use Keepsuit\Liquid\Drops\TableRowLoopDrop;
use Keepsuit\Liquid\Exceptions\TemplateSecurityException;
use Keepsuit\Liquid\Nodes\Range;
use Keepsuit\Liquid\Support\MissingValue;
use Keepsuit\Liquid\Support\UndefinedVariable;

/** Application-owned capabilities. Never construct this policy from template input. */
final class TemplatePolicy
{
    /** @var array<class-string, true> */
    private readonly array $approved;

    /** @param list<class-string> $allowedClasses Exact classes, not subclasses. */
    public function __construct(
        public readonly array $allowedClasses = [],
        public readonly int $maxSourceBytes = 262144,
        public readonly int $maxTokens = 20000,
        public readonly int $maxParseBytes = 1048576,
        public readonly int $maxDataDepth = 32,
        public readonly int $maxDataValues = 100000,
        public readonly int $maxDataBytes = 10000000,
    ) {
        $this->approved = array_fill_keys([ForLoopDrop::class, TableRowLoopDrop::class, SelfDrop::class,
            Range::class, MissingValue::class, UndefinedVariable::class, ...$allowedClasses], true);
        foreach ([$maxSourceBytes, $maxTokens, $maxParseBytes, $maxDataDepth, $maxDataValues, $maxDataBytes] as $limit) {
            if ($limit < 1) {
                throw new \InvalidArgumentException('Template policy limits must be positive.');
            }
        }
    }

    public function assertObject(object $value): void
    {
        if (! isset($this->approved[$value::class])) {
            throw new TemplateSecurityException('PHP object is not approved for template access: '.$value::class);
        }
    }

    /** @param array<string, string> $templates */
    public function assertTemplates(array $templates): void
    {
        if (count($templates) > 1000) {
            throw new TemplateSecurityException('A template snapshot cannot exceed 1000 files.');
        }
        $bytes = 0;
        foreach ($templates as $name => $source) {
            $bytes += strlen($source);
            if (strlen((string) $name) > 256 || strlen($source) > $this->maxSourceBytes || $bytes > $this->maxParseBytes) {
                throw new \Keepsuit\Liquid\Exceptions\ResourceLimitException('Template snapshot exceeds its source budget.');
            }
        }
    }

    /** Validate arrays without invoking getters, iterators, conversions or callbacks. */
    public function assertData(mixed $value, int $depth = 0, ?int &$remaining = null, ?int &$bytes = null): void
    {
        if ($remaining === null && $bytes === null && ! is_array($value) && ! is_object($value) && ! is_resource($value)) {
            if (is_string($value) && strlen($value) > $this->maxDataBytes) {
                throw new TemplateSecurityException('Template data exceeds the byte budget.');
            }

            return;
        }
        $remaining ??= $this->maxDataValues;
        $bytes ??= $this->maxDataBytes;
        if (--$remaining < 0 || $depth > $this->maxDataDepth) {
            throw new TemplateSecurityException('Template data exceeds the structural budget.');
        }
        if (is_object($value)) {
            $this->assertObject($value);
        } elseif (is_array($value)) {
            foreach ($value as $key => $item) {
                $bytes -= is_string($key) ? strlen($key) : 8;
                $this->assertData($item, $depth + 1, $remaining, $bytes);
            }
        } elseif (is_string($value)) {
            $bytes -= strlen($value);
        } elseif (is_resource($value)) {
            throw new TemplateSecurityException('Resources cannot be exposed to templates.');
        }
        if ($bytes < 0) {
            throw new TemplateSecurityException('Template data exceeds the byte budget.');
        }
    }
}
