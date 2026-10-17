<?php

namespace Keepsuit\Liquid\Support;

use Keepsuit\Liquid\Attributes\Hidden;
use Keepsuit\Liquid\Contracts\IsContextAware;
use Keepsuit\Liquid\Exceptions\InvalidArgumentException;
use Keepsuit\Liquid\Exceptions\UndefinedFilterException;
use Keepsuit\Liquid\Exceptions\UndefinedVariableException;
use Keepsuit\Liquid\Filters\FiltersProvider;
use Keepsuit\Liquid\Render\RenderContext;

class FilterRegistry
{
    private bool $sealed = false;

    public function seal(): string
    {
        $this->sealed = true;
        $declarations = [];
        foreach ($this->filters as $name => [$instance, $method]) {
            $declarations[$name] = [$instance::class, $method];
        }
        ksort($declarations);

        return hash('sha256', serialize($declarations));
    }

    private function assertMutable(): void
    {
        if ($this->sealed) {
            throw new \Keepsuit\Liquid\Exceptions\TemplateSecurityException('Restricted template capabilities are sealed.');
        }
    }

    /**
     * @var array<string,array{0:FiltersProvider,1:string}>
     */
    protected array $filters = [];

    /**
     * @param  class-string<FiltersProvider>  $filtersClass
     */
    public function register(string $filtersClass): static
    {
        $this->assertMutable();
        if (! class_exists($filtersClass)) {
            throw new InvalidArgumentException("Filter class $filtersClass does not exist.");
        }

        $filters = $this->extractFiltersFromClass($filtersClass);

        $instance = new $filtersClass;
        foreach ($filters as $filter => $method) {
            $this->filters[$filter] = [$instance, $method];
        }

        return $this;
    }

    /**
     * @param  class-string<FiltersProvider>  $filtersClass
     */
    public function delete(string $filtersClass): static
    {
        $this->assertMutable();
        if (! class_exists($filtersClass)) {
            return $this;
        }

        $filters = $this->extractFiltersFromClass($filtersClass);

        foreach ($filters as $key => $value) {
            unset($this->filters[$key]);
        }

        return $this;
    }

    /**
     * @return string[]
     */
    public function all(): array
    {
        return array_keys($this->filters);
    }

    public function has(string $filter): bool
    {
        return isset($this->filters[$filter]);
    }

    /**
     * @throws UndefinedFilterException|UndefinedVariableException
     */
    public function invoke(RenderContext $context, string $filterName, mixed $value, array $args = []): mixed
    {
        $filter = $this->filters[$filterName] ?? null;

        if ($filter !== null) {
            try {
                $instance = $filter[0];
                $method = $filter[1];

                if ($instance instanceof IsContextAware) {
                    $instance->setContext($context);
                }

                if (! $context->requiresBoundedExecution) {
                    return $instance->{$method}($value, ...$args);
                }

                if ($context->environment->templatePolicy !== null) {
                    $context->environment->templatePolicy->assertData($value);
                    if ($args !== []) {
                        $context->environment->templatePolicy->assertData($args);
                    }
                    // Decimal arithmetic is arbitrary precision. Cap operand digits before Brick allocates.
                    if (in_array($filterName, ['plus', 'minus', 'times', 'divided_by', 'modulo', 'sum'], true)) {
                        foreach ([$value, ...$args] as $operand) {
                            if (is_string($operand) && strlen($operand) > 256) {
                                throw new \Keepsuit\Liquid\Exceptions\ResourceLimitException('Numeric operand budget exceeded.');
                            }
                        }
                    }
                }
                $context->resourceLimits->incrementRenderScore();
                if (is_string($value)) {
                    $context->resourceLimits->checkStringSize(strlen($value));
                }
                foreach ($args as $operand) {
                    if (is_string($operand)) {
                        $context->resourceLimits->checkStringSize(strlen($operand));
                    }
                }
                $result = $instance->{$method}($value, ...$args);
                $context->environment->templatePolicy?->assertData($result);
                if (is_string($result)) {
                    $context->resourceLimits->checkStringSize(strlen($result));
                } elseif (is_array($result)) {
                    $context->resourceLimits->checkCollectionSize(count($result));
                }

                return $result;
            } catch (\TypeError $e) {
                if ($value instanceof UndefinedVariable) {
                    throw new UndefinedVariableException($value->variableName);
                }

                throw $e;
            }
        }

        if ($context->options->strictFilters) {
            throw new UndefinedFilterException($filterName);
        }

        return $value;
    }

    /**
     * @param  class-string<FiltersProvider>  $filtersClass
     * @return array<string,string>
     */
    protected function extractFiltersFromClass(string $filtersClass): array
    {
        $filters = [];

        $reflection = new \ReflectionClass($filtersClass);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), '__')) {
                continue;
            }

            if ($method->isStatic()) {
                continue;
            }

            if ($method->getAttributes(Hidden::class) !== []) {
                continue;
            }

            $filters[Str::snake($method->getName())] = $method->getName();
        }

        return $filters;
    }
}
