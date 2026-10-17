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

$sources = ['page' => 'Hello {{ name | escape }}{% render "card" %}', 'card' => '!'];
$snapshot = new Keepsuit\Liquid\FileSystems\TemplateSnapshot(
    array_map(fn (string $source) => hash('sha256', $source), $sources),
    fn (string $name, int $maxBytes): string => $sources[$name],
);
$directory = sys_get_temp_dir().'/liquid-consumer-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$cache = new Keepsuit\Liquid\TemplatesCache\CompiledTemplatesCache($directory);
$restricted = Keepsuit\Liquid\EnvironmentFactory::forUntrustedTemplates()->setFilesystem($snapshot)->setTemplatesCache($cache)->build();
$isolated = new Keepsuit\Liquid\Security\IsolatedRenderer(timeoutMilliseconds: 3000);
try {
    $template = $restricted->parseTemplate('page');
    $expected = 'Hello A&amp;B!';
    if ($template->render($restricted->newRenderContext(data: ['name' => 'A&B'])) !== $expected
        || implode('', iterator_to_array($template->stream($restricted->newRenderContext(data: ['name' => 'A&B'])))) !== $expected
        || $isolated->render($sources, 'page', ['name' => 'A&B']) !== $expected
        || implode('', iterator_to_array($isolated->stream($sources, 'page', ['name' => 'A&B']))) !== $expected) {
        throw new RuntimeException('Restricted rendering or streaming failed.');
    }
} finally {
    $isolated->close();
    $cache->clear();
    rmdir($directory);
}
echo "Restricted compiled rendering and isolated streaming passed.\n";
