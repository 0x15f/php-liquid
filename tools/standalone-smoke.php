<?php

require $argv[1];

if (class_exists(Illuminate\Support\ServiceProvider::class)) {
    throw new RuntimeException('Standalone installation unexpectedly requires Laravel.');
}
$environment = Keepsuit\Liquid\EnvironmentFactory::new()->build();
$output = $environment->parseString('Hello {{ name | escape }}')->render($environment->newRenderContext(data: ['name' => 'A&B']));
if ($output !== 'Hello A&amp;B') {
    throw new RuntimeException('Standalone rendering failed.');
}
echo "Framework-free rendering passed.\n";
