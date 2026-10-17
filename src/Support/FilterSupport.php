<?php

namespace Keepsuit\Liquid\Support;

use Keepsuit\Liquid\Contracts\IsContextAware;
use Keepsuit\Liquid\Drop;
use Keepsuit\Liquid\Exceptions\InvalidArgumentException;
use Keepsuit\Liquid\Render\RenderContext;

/**
 * @internal
 */
class FilterSupport
{
    public readonly bool $bounded;

    public function __construct(protected RenderContext $context)
    {
        $this->bounded = $context->requiresBoundedExecution;
    }

    public function visit(int $count, int $depth = 0): void
    {
        $this->context->resourceLimits->checkCollectionSize($count);
        $this->context->resourceLimits->incrementRenderScore();
        if ($depth > ($this->context->environment->templatePolicy === null ? 100 : $this->context->environment->templatePolicy->maxDataDepth)) {
            $this->context->resourceLimits->throwLimitReachedException();
        }
    }

    public function normalize(mixed $value): mixed
    {
        $value = $this->context->normalizeValue($value);

        if ($value instanceof IsContextAware) {
            $value->setContext($this->context);
        }

        return $value;
    }

    public function normalizeCollection(iterable $input): array
    {
        return Arr::map($this->context->resourceLimits->collect($input), $this->normalize(...));
    }

    public function iterate(mixed $input): FilterInputIterator
    {
        return new FilterInputIterator($input, $this);
    }

    public function stringify(mixed $value): string
    {
        if (is_array($value)) {
            return $this->inspectArrayValue($value);
        }

        return FilterCoercion::toString($value);
    }

    protected function inspectArrayValue(mixed $value, int $depth = 0): string
    {
        if ($depth > ($this->context->environment->templatePolicy === null ? 100 : $this->context->environment->templatePolicy->maxDataDepth)) {
            $this->context->resourceLimits->throwLimitReachedException();
        }
        if (is_string($value)) {
            $this->context->resourceLimits->checkStringSize(6.0 * strlen($value) + 2);

            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        if ($value === null) {
            return 'nil';
        }

        if (is_array($value)) {
            $list = array_is_list($value);
            $this->context->resourceLimits->checkCollectionSize(count($value));
            $output = $list ? '[' : '{';
            $first = true;
            foreach ($value as $key => $item) {
                $this->context->resourceLimits->incrementRenderScore();
                if (! $first) {
                    $this->context->resourceLimits->append($output, ', ');
                }
                if (! $list) {
                    $this->context->resourceLimits->append($output, $this->inspectArrayValue($key, $depth + 1).'=>');
                }
                $this->context->resourceLimits->append($output, $this->inspectArrayValue($item, $depth + 1));
                $first = false;
            }
            $this->context->resourceLimits->append($output, $list ? ']' : '}');

            return $output;
        }

        return $this->stringify($value);
    }

    public function canSelectProperty(mixed $item): bool
    {
        return is_array($item) || is_object($item) || is_string($item) || is_int($item);
    }

    /**
     * @phpstan-assert int|string|null $property
     */
    public function validateProperty(mixed $property): void
    {
        if ($property instanceof UndefinedVariable) {
            throw $property->toException();
        }

        if ($property !== null && ! is_string($property) && ! is_int($property)) {
            throw new InvalidArgumentException('invalid property');
        }
    }

    public function property(mixed $item, mixed $property): mixed
    {
        if ($this->bounded && is_object($item)) {
            $this->context->environment->templatePolicy?->assertObject($item);
            $this->context->resourceLimits->incrementRenderScore();
        }
        if ($property !== null && ! is_string($property) && ! is_int($property)) {
            throw $property instanceof UndefinedVariable
                ? $property->toException()
                : new InvalidArgumentException('invalid property');
        }

        $value = match (true) {
            is_array($item) => $item[$property ?? ''] ?? null,
            is_string($item) && is_string($property) => str_contains($item, $property) ? $property : null,
            is_string($item) && is_int($property) => $property >= -Str::length($item) && $property < Str::length($item) ? Str::substr($item, $property, 1) : null,
            is_string($item), is_int($item) => throw new InvalidArgumentException('cannot select the property '.FilterCoercion::toString($property)),
            $item instanceof Drop => $item->{(string) $property},
            is_object($item) => $item->{(string) $property} ?? null,
            default => null,
        };

        return $this->normalize($value);
    }
}
