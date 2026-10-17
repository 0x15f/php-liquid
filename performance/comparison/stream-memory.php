<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Keepsuit\Liquid\EnvironmentFactory;
use Keepsuit\Liquid\FileSystems\ArrayFileSystem;
use Keepsuit\Liquid\TemplatesCache\CompiledTemplatesCache;

$directory = sys_get_temp_dir().'/liquid-stream-memory-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$environment = EnvironmentFactory::forUntrustedTemplates()
    ->setFilesystem(new ArrayFileSystem(['page' => '{% for x in (1..2000) %}{{ text }}{% endfor %}']))
    ->setTemplatesCache(new CompiledTemplatesCache($directory))->build();
try {
    $template = $environment->parseTemplate('page');
    $data = ['text' => str_repeat('x', 4096)];
    $context = $environment->newRenderContext(data: $data);
    $before = memory_get_usage();
    memory_reset_peak_usage();
    $output = $template->render($context);
    $renderPeak = memory_get_peak_usage() - $before;
    $expected = hash('sha256', $output);
    unset($output, $context);

    $context = $environment->newRenderContext(data: $data);
    $before = memory_get_usage();
    memory_reset_peak_usage();
    $hash = hash_init('sha256');
    $bytes = $chunks = 0;
    foreach ($template->stream($context) as $chunk) {
        hash_update($hash, $chunk);
        $bytes += strlen($chunk);
        $chunks++;
    }
    $streamPeak = memory_get_peak_usage() - $before;
    if ($bytes !== 8_192_000 || hash_final($hash) !== $expected) {
        throw new RuntimeException('Streaming output differs from rendering.');
    }
    echo json_encode(['php' => PHP_VERSION, 'arch' => php_uname('m'), 'output_bytes' => $bytes, 'chunks' => $chunks,
        'render_peak_heap_delta_bytes' => $renderPeak, 'stream_peak_heap_delta_bytes' => $streamPeak,
        'output_sha256' => $expected, 'metric' => 'PHP allocated heap delta, not RSS; compilation excluded'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    $environment->templatesCache->clear();
    rmdir($directory);
}
