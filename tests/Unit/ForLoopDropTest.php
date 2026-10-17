<?php

use Keepsuit\Liquid\Drops\ForLoopDrop;

test('native loop lookups match metadata lookups throughout the loop', function () {
    $parent = new ForLoopDrop('outer', 1);
    $native = new ForLoopDrop('items', 3, $parent);
    $metadata = new class('items', 3, $parent) extends ForLoopDrop {};
    foreach (range(0, 2) as $iteration) {
        foreach (['first', 'last', 'index', 'index0', 'rindex', 'rindex0', 'length', 'name', 'parentloop', 'parent_loop', 'parentLoop', 'unknown'] as $key) {
            expect($native->{$key})->toBe($metadata->{$key});
        }
        $native->increment();
        $metadata->increment();
    }
});

test('loop subclasses retain method and dynamic property overrides', function () {
    $loop = new class('items', 3) extends ForLoopDrop
    {
        public function first(): string
        {
            return 'custom';
        }

        protected function liquidMethodMissing(string $name): mixed
        {
            return $name === 'index' ? 99 : parent::liquidMethodMissing($name);
        }
    };

    expect($loop->first)->toBe('custom')->and($loop->index)->toBe(99);
});
