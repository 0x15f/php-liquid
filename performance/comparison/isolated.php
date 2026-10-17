<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/fixtures.php';

use Keepsuit\Liquid\Security\IsolatedRenderer;

if (extension_loaded('xdebug') || extension_loaded('pcov')) {
    throw new RuntimeException('Timing requires profilers to be unloaded.');
}
$options = getopt('', ['output:', 'samples:']);
$count = (int) ($options['samples'] ?? 320);
if ($count < 16 || $count > 100000) {
    throw new InvalidArgumentException('Use between 16 and 100000 samples.');
}
$fixtures = comparisonFixtures();
$requests = $fixtures['workloads']['grid_24'];
$hashes = json_decode(file_get_contents(__DIR__.'/results/reference-hashes.json'), true, flags: JSON_THROW_ON_ERROR)['grid_24'];
$renderer = new IsolatedRenderer(timeoutMilliseconds: 3000, memoryMegabytes: 128, maxRequests: 100);
$source = hash_init('sha256');
$paths = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $paths[] = $file->getPathname();
    }
}
sort($paths);
foreach ($paths as $path) {
    hash_update($source, substr($path, strlen(dirname(__DIR__, 2).'/src/')).file_get_contents($path));
}
try {
    $start = hrtime(true);
    $output = $renderer->render($fixtures['templates'], 'grid', $requests[0]['data'], 'benchmark');
    $cold = (hrtime(true) - $start) / 1000000;
    if (hash('sha256', $output) !== $hashes[0]) {
        throw new RuntimeException('Cold output differs from the Shopify reference.');
    }
    for ($i = 0; $i < 32; $i++) {
        $renderer->render($fixtures['templates'], 'grid', $requests[$i % 16]['data'], 'benchmark');
    }
    $samples = [];
    for ($i = 0; $i < $count; $i++) {
        $index = $i % 16;
        $start = hrtime(true);
        $output = $renderer->render($fixtures['templates'], 'grid', $requests[$index]['data'], 'benchmark');
        $samples[] = (hrtime(true) - $start) / 1000;
        if (hash('sha256', $output) !== $hashes[$index]) {
            throw new RuntimeException('Personalized output differs from the Shopify reference.');
        }
    }
    $ordered = $samples;
    sort($ordered);
    $percentile = fn (float $p) => $ordered[(int) floor((count($ordered) - 1) * $p)];
    $result = ['time_utc' => gmdate('c'), 'php' => PHP_VERSION, 'arch' => php_uname('m'), 'kernel' => php_uname('r'),
        'engine_source_sha256' => hash_final($source), 'worker_sha256' => hash_file('sha256', dirname(__DIR__, 2).'/resources/isolated-worker.php'),
        'fixture_sha256' => hash('sha256', json_encode($fixtures, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'cpu_max' => trim(file_get_contents('/sys/fs/cgroup/cpu.max')), 'mode' => 'sequential-isolated-render-wall-time',
        'warmup_requests' => 32, 'worker_max_requests' => 100, 'worker_php_heap_mib' => 128,
        'cold_ms' => $cold, 'samples' => $count, 'p50_wall_us' => $percentile(0.5), 'p95_wall_us' => $percentile(0.95),
        'p99_wall_us' => $percentile(0.99), 'max_wall_us' => max($samples), 'raw_wall_us' => $samples,
        'checks' => ['shopify_reference' => true, 'personalized_inputs' => 16]];
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    if (isset($options['output'])) {
        file_put_contents($options['output'], $json);
    } else {
        echo $json;
    }
} finally {
    $renderer->close();
}
