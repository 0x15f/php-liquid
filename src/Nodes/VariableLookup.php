<?php

namespace Keepsuit\Liquid\Nodes;

use Keepsuit\Liquid\Compiler\CompilerContext;
use Keepsuit\Liquid\Contracts\CanBeEvaluated;
use Keepsuit\Liquid\Contracts\CanBeExported;
use Keepsuit\Liquid\Contracts\HasParseTreeVisitorChildren;
use Keepsuit\Liquid\Contracts\IsContextAware;
use Keepsuit\Liquid\Exceptions\SyntaxException;
use Keepsuit\Liquid\Render\RenderContext;
use Keepsuit\Liquid\Support\MissingValue;
use Keepsuit\Liquid\Support\UndefinedVariable;

/** @phpstan-type CompiledLookup scalar|array{string, list<string|int>}|CanBeEvaluated|null */
class VariableLookup implements CanBeEvaluated, CanBeExported, HasParseTreeVisitorChildren
{
    const FILTER_METHODS = ['size', 'first', 'last'];

    private const LOOKUP_REGEX = '{\.([\w\-]+)|\["([\w\-]+)"\]|\[\'([\w\-]+)\'\]|\[(-?\d+)\]}';

    public function __construct(
        public readonly string $name,
        /** @var array<string|int|VariableLookup|BracketKey> */
        public readonly array $lookups = [],
    ) {}

    /**
     * Parses `a.b[0]["c"]` into a name plus its lookups.
     */
    public static function fromMarkup(string $markup): VariableLookup
    {
        $nameLength = strcspn($markup, '.[');
        $lookupsString = substr($markup, $nameLength);

        if ($lookupsString === '') {
            return new VariableLookup($markup);
        }

        preg_match_all(self::LOOKUP_REGEX, $lookupsString, $matches, PREG_UNMATCHED_AS_NULL);

        // preg_match_all() skips whatever it cannot match, so the matches have to
        // account for the whole string or there was junk between or after them.
        if (implode('', $matches[0]) !== $lookupsString) {
            throw new SyntaxException('Invalid variable lookup: '.$lookupsString);
        }

        $lookups = [];
        foreach (array_keys($matches[0]) as $i) {
            // Every alternative in the pattern captures, so one of them is set.
            $lookup = $matches[1][$i] ?? $matches[2][$i] ?? $matches[3][$i] ?? $matches[4][$i];
            assert($lookup !== null);

            if ($matches[4][$i] !== null && str_starts_with($lookup, '-')) {
                $lookup = (int) $lookup;
            }

            if (($matches[2][$i] !== null || $matches[3][$i] !== null) && in_array($lookup, self::FILTER_METHODS, true)) {
                $lookup = new BracketKey($lookup);
            }

            $lookups[] = $lookup;
        }

        return new VariableLookup(substr($markup, 0, $nameLength), $lookups);
    }

    public function export(CompilerContext $context): ?string
    {
        if (static::class !== self::class) {
            return null;
        }

        return 'new '.$context->writeClassName(self::class).'('
            .$context->writeValue($this->name).', '
            .$context->writeValue($this->lookups).')';
    }

    public function toString(): string
    {
        if ($this->lookups === []) {
            return $this->name;
        }

        return implode('.', [$this->name, ...$this->lookups]);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function parseTreeVisitorChildren(): array
    {
        return [$this->name, ...$this->lookups];
    }

    public function evaluate(RenderContext $context): mixed
    {
        if ($this->lookups === []) {
            $variable = $context->findVariable($this->name);

            return $variable instanceof MissingValue
                ? self::undefinedValue($context, $this->name, [])
                : $variable;
        }

        return self::evaluateParts($context, $this->name, $this->lookups);
    }

    /**
     * Compiler entry point for a name with no lookup path.
     *
     * @internal
     */
    public static function evaluateName(RenderContext $context, string $name): mixed
    {
        $variable = $context->findVariable($name);

        return $variable instanceof MissingValue
            ? self::undefinedValue($context, $name, [])
            : $variable;
    }

    /**
     * Common compiled storefront lookup, without allocating/walking a path.
     * Objects, nulls, pseudo-properties and negative indices retain the generic
     * evaluator so extension behavior and strict-variable errors are unchanged.
     *
     * @internal
     */
    public static function evaluateKey(RenderContext $context, string $name, string|int $key): mixed
    {
        $variable = $context->findVariable($name);

        if (is_array($variable) && (is_string($key) || $key >= 0)
            && isset($variable[$key]) && ! is_object($value = $variable[$key])) {
            return $value;
        }

        if ($variable instanceof MissingValue) {
            return self::undefinedValue($context, $name, [$key]);
        }

        $result = self::walkLookupParts($context, $variable, [$key]);

        return $result instanceof MissingValue ? self::undefinedValue($context, $name, [$key]) : $result;
    }

    /**
     * Resolve an immutable compiler lookup descriptor or an already known literal.
     *
     * @param  CompiledLookup  $value
     *
     * @internal
     */
    public static function evaluateDescriptor(RenderContext $context, mixed $value): mixed
    {
        if (is_array($value)) {
            if ($value[1] === []) {
                $variable = $context->findVariable($value[0]);
                $value = $variable instanceof MissingValue
                    ? self::undefinedValue($context, $value[0], [])
                    : $variable;
            } else {
                $value = self::evaluateParts($context, $value[0], $value[1]);
            }
        }

        return $value instanceof CanBeEvaluated ? $context->evaluate($value) : $value;
    }

    /**
     * Evaluate a parsed lookup without requiring a VariableLookup instance.
     *
     * @param  array<string|int|VariableLookup|BracketKey>  $lookups
     */
    public static function evaluateParts(RenderContext $context, string $name, array $lookups): mixed
    {
        $variable = $context->findVariable($name);

        if ($variable instanceof MissingValue) {
            return self::undefinedValue($context, $name, $lookups);
        }

        if ($lookups === []) {
            return $variable;
        }

        $result = self::walkLookupParts($context, $variable, $lookups);

        if (! $result instanceof MissingValue) {
            return $result;
        }

        // A resolved root shadows outer roots, even when a nested key is absent.
        return self::undefinedValue($context, $name, $lookups);
    }

    /**
     * @param  array<string|int|VariableLookup|BracketKey>  $lookups
     */
    private static function undefinedValue(RenderContext $context, string $name, array $lookups): ?UndefinedVariable
    {
        return $context->options->strictVariables
            ? new UndefinedVariable(implode('.', [$name, ...$lookups]))
            : null;
    }

    /**
     * @param  array<string|int|VariableLookup|BracketKey>  $lookups
     */
    private static function walkLookupParts(RenderContext $context, mixed $object, array $lookups): mixed
    {
        if ($object instanceof CanBeEvaluated) {
            $object = $context->evaluate($object);
        }

        if ($object instanceof \Generator) {
            $object = $context->resourceLimits->collect($object, preserveKeys: false);
        }

        foreach ($lookups as $lookup) {
            // Prepared storefront contexts predominantly contain hashes and
            // scalars. Resolve these without object normalization, virtual
            // lookups or pseudo-property dispatch. Nulls and objects retain the
            // full path; negative list indices must also retain Liquid indexing.
            if (is_array($object)
                && (is_string($lookup) || (is_int($lookup) && $lookup >= 0))
                && isset($object[$lookup])
                && ! is_object($value = $object[$lookup])) {
                $object = $value;

                continue;
            }

            if (is_string($lookup)) {
                $key = $lookup;
            } else {
                $key = match (true) {
                    $lookup instanceof VariableLookup => $context->evaluate($lookup),
                    $lookup instanceof BracketKey => $lookup->value,
                    default => $lookup,
                };

                if (! (is_string($key) || is_int($key))) {
                    return new MissingValue;
                }
            }

            if (is_int($key) && $key < 0 && is_array($object) && array_is_list($object)) {
                $key += count($object);
            }

            $nextObject = $context->internalContextLookup($object, $key);

            if ($nextObject instanceof CanBeEvaluated) {
                $nextObject = $context->evaluate($nextObject);
            }

            if ($nextObject instanceof MissingValue) {
                if ((is_iterable($object) || is_string($object)) && is_string($lookup) && in_array($lookup, self::FILTER_METHODS, true)) {
                    // Hash lookups keep their key-based semantics; the explicit
                    // first filter may return a key/value pair instead.
                    $nextObject = $lookup === 'first' && is_array($object) && ! array_is_list($object)
                        ? null
                        : $context->applyFilter($lookup, $object);
                } else {
                    return $nextObject;
                }
            }

            $object = $nextObject;
            if ($object instanceof IsContextAware) {
                $object->setContext($context);
            }
        }

        return $object;
    }
}
