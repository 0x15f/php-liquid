<?php

dataset('shopify review cases', array_map('array_values', array_column(
    json_decode(file_get_contents(__DIR__.'/../Fixtures/shopify-review.json'), true, flags: JSON_THROW_ON_ERROR),
    null,
    'name',
)));

test('matches Shopify Liquid 5.13 reference output', function (string $name, string $source, array $data, string $expected, bool $compiled) {
    expect(renderTemplate(template: $source, data: $data, compiled: $compiled))->toBe($expected);
    expect(implode('', iterator_to_array(streamTemplate($source, data: $data, compiled: $compiled))))->toBe($expected);
})->with('shopify review cases')->with('template backends');
