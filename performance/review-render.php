<?php

require dirname(__DIR__).'/vendor/autoload.php';

use Keepsuit\Liquid\Performance\Support\StorefrontTheme;
use Keepsuit\Liquid\TemplatesCache\CompiledTemplatesCache;

$directory = sys_get_temp_dir().'/liquid-review-'.bin2hex(random_bytes(8));
$parsed = StorefrontTheme::environment();
$compiled = StorefrontTheme::environmentFactory()
    ->setTemplatesCache(new CompiledTemplatesCache($directory))->build();
foreach (StorefrontTheme::templateNames() as $name) {
    $parsed->parseTemplate($name);
    $compiled->parseTemplate($name);
}
$pages = StorefrontTheme::pageTemplateNames();
foreach ($pages as $page) {
    if (StorefrontTheme::renderPage($parsed, $page) !== StorefrontTheme::renderPage($compiled, $page)) {
        throw new RuntimeException('Backend output mismatch: '.$page);
    }
}
$results = ['php' => PHP_VERSION, 'architecture' => php_uname('m'), 'opcache' => ini_get('opcache.enable_cli'),
    'jit' => ini_get('opcache.jit'), 'xdebug_mode' => ini_get('xdebug.mode'), 'pages_per_batch' => count($pages),
    'measurement' => 'warm render wall time, fresh fixture data/context per page; excludes HTTP and database'];
$samples = ['parsed' => [], 'compiled' => []];
try {
    for ($round = -3; $round < 15; $round++) {
        $backends = $round % 2 === 0 ? ['parsed' => $parsed, 'compiled' => $compiled] : ['compiled' => $compiled, 'parsed' => $parsed];
        foreach ($backends as $backend => $environment) {
            $start = hrtime(true);
            for ($iteration = 0; $iteration < 20; $iteration++) {
                foreach ($pages as $page) {
                    StorefrontTheme::renderPage($environment, $page);
                }
            }
            if ($round >= 0) {
                $samples[$backend][] = (hrtime(true) - $start) / (20 * count($pages)) / 1000;
            }
        }
    }
    foreach ($samples as $backend => $values) {
        assert($values !== []);
        sort($values);
        $results[$backend] = ['median_us_per_page' => $values[7], 'min_us_per_page' => min($values),
            'max_us_per_page' => max($values), 'batch_samples_us_per_page' => $values];
    }
    $results['compiled_speedup'] = $results['parsed']['median_us_per_page'] / $results['compiled']['median_us_per_page'];
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $compiled->templatesCache->clear();
    rmdir($directory);
}
