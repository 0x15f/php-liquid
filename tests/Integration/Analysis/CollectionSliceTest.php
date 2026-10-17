<?php

use Keepsuit\Liquid\Contracts\ProvidesCollectionSlice;

/** Returns array_slice-equivalent windows, and detects accidental full traversal. */
final class SliceOnlyCollection implements IteratorAggregate, ProvidesCollectionSlice
{
    public array $calls = [];

    public function liquidSlice(int $offset, ?int $length): array
    {
        $this->calls[] = [$offset, $length];

        return array_slice(['a', 'b', 'c', 'd', 'e'], $offset, $length);
    }

    public function getIterator(): Traversable
    {
        throw new RuntimeException('Must not materialize the collection');
    }
}

test('bounded collection matches array loop semantics in every output backend', function (bool $compiled) {
    foreach ([
        '{% for x in items reversed limit: 2 offset: 1 %}{{ x }}:{{ forloop.length }};{% endfor %}',
        '{% for x in items limit: 2 %}{{ x }}{% endfor %}{% for x in items limit: 2 offset: continue %}{{ x }}{% endfor %}',
        '{% for x in items offset: -3 limit: -1 %}{{ x }}{% endfor %}',
        '{% for x in items limit: 0 %}bad{% else %}empty{% endfor %}',
        '{% for x in items %}{{ x }}{% break %}{% endfor %}',
    ] as $source) {
        foreach (['render', 'stream'] as $method) {
            $env = testEnvironmentFactory($compiled)->setRethrowErrors(true)->build();
            $template = testParseString($env, $source);
            $render = function ($items) use ($method, $env, $template): string {
                $context = $env->newRenderContext(data: ['items' => $items]);

                return $method === 'render' ? $template->render($context) : implode('', iterator_to_array($template->stream($context)));
            };
            $collection = new SliceOnlyCollection;
            expect($render($collection))->toBe($render(['a', 'b', 'c', 'd', 'e']));
            expect($collection->calls)->not->toBeEmpty();
        }
    }
})->with('template backends');
