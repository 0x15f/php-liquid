<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/fixtures.php';

use Keepsuit\Liquid\Analysis\DependencyPlanner;
use Keepsuit\Liquid\Contracts\LiquidFileSystem;
use Keepsuit\Liquid\EnvironmentFactory;
use Keepsuit\Liquid\Performance\Loading\CatalogSession;
use Keepsuit\Liquid\Performance\Loading\CatalogStore;
use Keepsuit\Liquid\Performance\Loading\ProductsCollection;
use Keepsuit\Liquid\TemplatesCache\CompiledTemplatesCache;

function loadingCpuNs(): int
{
    $r = getrusage();
    if ($r === false) {
        throw new RuntimeException('CPU clock unavailable');
    }

    return (($r['ru_utime.tv_sec'] + $r['ru_stime.tv_sec']) * 1_000_000
        + $r['ru_utime.tv_usec'] + $r['ru_stime.tv_usec']) * 1000;
}

$options = getopt('', ['database:', 'output:', 'rounds:', 'warmup:', 'batch:', 'setup', 'export-reference:']);
$database = $options['database'] ?? dirname(__DIR__, 2).'/build/loading-catalog.sqlite';
if (! is_string($database)) {
    throw new RuntimeException('Invalid database path');
}
if (isset($options['setup'])) {
    if (is_file($database)) {
        throw new RuntimeException('Setup refuses to replace an existing database');
    }
    createLoadingDatabase($database);
    echo "Created file-backed catalog: 2 tenants, 2000 products each, four variants per product\n";
    exit;
}
if (extension_loaded('pcov') || extension_loaded('xdebug')) {
    throw new RuntimeException('Timing requires PCOV and Xdebug unloaded');
}
$pdo = new PDO('sqlite:'.$database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sources = loadingTemplates();
$filesystem = new class($sources) implements LiquidFileSystem
{
    public function __construct(private readonly array $sources) {}

    public function readTemplateFile(string $templateName): string
    {
        return $this->sources[$templateName];
    }
};
$parsed = EnvironmentFactory::new()->setFilesystem($filesystem)->setRethrowErrors(true)->build();
$directory = sys_get_temp_dir().'/liquid-loading-'.bin2hex(random_bytes(8));
$env = EnvironmentFactory::new()->setFilesystem($filesystem)->setStrictVariables(true)->setStrictFilters(true)
    ->setRethrowErrors(true)->setTemplatesCache(new CompiledTemplatesCache($directory))->build();
$planner = new DependencyPlanner(fn (string $name) => $parsed->parseString($sources[$name], $name));
$planStart = hrtime(true);
$plans = [];
foreach (['grid', 'dynamic'] as $name) {
    $plans[$name] = $planner->analyse($parsed->parseString($sources[$name], $name));
}
$planMs = (hrtime(true) - $planStart) / 1e6;
$templates = ['grid' => $env->parseTemplate('grid'), 'dynamic' => $env->parseTemplate('dynamic')];
$modes = ['eager_full', 'eager_projected', 'naive', 'planned'];
$requests = [];
foreach (['grid_without_variants' => ['grid', false], 'grid_with_variants' => ['grid', true], 'dynamic_fields' => ['dynamic', true]] as $workload => [$templateName, $show]) {
    for ($i = 0; $i < 16; $i++) {
        $requests[$workload][] = ['template' => $templateName, 'tenant' => 1 + $i % 2,
            'customer' => ['id' => 1000 + $i, 'show_variants' => $show],
            'field' => 'title', 'variant_field' => 'title'];
    }
}
/** @param array{template: string, tenant: int, customer: array, field: string, variant_field: string} $request
 * @return array{html: string, queries: int, rows: int, bytes: int}
 */
$render = static function (array $request, string $mode) use ($pdo, $plans, $templates, $env): array {
    $store = new CatalogStore($pdo, $request['tenant']);
    $session = new CatalogSession($store, $plans[$request['template']], $mode);
    $context = $env->newRenderContext(data: [
        'collection' => ['products' => new ProductsCollection($session)],
        'customer' => $request['customer'], 'field' => $request['field'], 'variant_field' => $request['variant_field'],
    ]);
    $html = $templates[$request['template']]->render($context);

    return ['html' => $html, 'queries' => $store->queries, 'rows' => $store->rows, 'bytes' => $store->bytes];
};
$version = $pdo->query('SELECT sqlite_version()');
if ($version === false) {
    throw new RuntimeException('SQLite version unavailable');
}
$result = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'arch' => php_uname('m'),
    'kernel' => php_uname('r'), 'cpu_affinity' => trim(shell_exec('taskset -pc '.getmypid()) ?: ''),
    'cpu_max' => file_get_contents('/sys/fs/cgroup/cpu.max'),
    'profilers_loaded' => ['pcov' => extension_loaded('pcov'), 'xdebug' => extension_loaded('xdebug')],
    'sqlite' => $version->fetchColumn(),
    'opcache' => opcache_get_status(false)['opcache_enabled'] ?? false,
    'jit' => opcache_get_status(false)['jit'] ?? null,
    'plan_build_ms' => $planMs, 'plans' => $plans, 'checks' => [], 'samples' => []];
$reference = [];
$referencePath = __DIR__.'/results/reference-hashes.json';
/** @var array<string, list<string>>|null $referenceHashes */
$referenceHashes = is_file($referencePath) ? json_decode(file_get_contents($referencePath) ?: '', true, flags: JSON_THROW_ON_ERROR) : null;
try {
    foreach ($requests as $workload => $inputs) {
        foreach ($inputs as $i => $request) {
            $expected = $render($request, 'eager_full')['html'];
            $hash = hash('sha256', $expected);
            if (! isset($options['export-reference']) && ($referenceHashes[$workload][$i] ?? null) !== $hash) {
                throw new RuntimeException('Missing or mismatched Shopify reference hash');
            }
            foreach ($modes as $mode) {
                if ($render($request, $mode)['html'] !== $expected) {
                    throw new RuntimeException('Output mismatch: '.$workload.' '.$mode);
                }
            }
            $reference['workloads'][$workload][] = ['template' => $request['template'], 'data' => [
                'collection' => ['products' => json_decode(json_encode(referenceProducts($pdo, $request['tenant']), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)],
                'customer' => $request['customer'], 'field' => $request['field'], 'variant_field' => $request['variant_field'],
            ]];
        }
        $result['checks'][$workload] = ['inputs' => count($inputs), 'shopify_reference' => ! isset($options['export-reference']), 'output_bytes' => strlen($expected)];
    }
    if (isset($options['export-reference'])) {
        $path = $options['export-reference'];
        if (! is_string($path)) {
            throw new RuntimeException('Invalid reference path');
        }
        $reference['templates'] = $sources;
        file_put_contents($path, json_encode($reference, JSON_THROW_ON_ERROR));
        exit;
    }
    // Release correctness/reference data before measuring per-request allocation.
    unset($reference, $expected, $context);
    gc_collect_cycles();
    $rounds = (int) ($options['rounds'] ?? 24);
    $warmup = (int) ($options['warmup'] ?? 8);
    $batch = (int) ($options['batch'] ?? 16);
    if ($rounds < 1 || $warmup < 0 || $batch < 16 || $batch % 16 !== 0) {
        throw new RuntimeException('Use positive rounds, nonnegative warmup and complete 16-customer batches');
    }
    $result['rounds'] = $rounds;
    $result['warmup'] = $warmup;
    $result['batch'] = $batch;
    foreach ($requests as $workload => $inputs) {
        for ($round = -$warmup; $round < $rounds; $round++) {
            $offset = ($round + $warmup) % count($modes);
            $order = array_merge(array_slice($modes, $offset), array_slice($modes, 0, $offset));
            if (intdiv($round + $warmup, count($modes)) % 2) {
                $order = array_reverse($order);
            }
            foreach ($order as $mode) {
                $cpu = loadingCpuNs();
                $wall = hrtime(true);
                $metrics = ['queries' => 0, 'rows' => 0, 'bytes' => 0];
                $outputBytes = 0;
                for ($i = 0; $i < $batch; $i++) {
                    $value = $render($inputs[$i % count($inputs)], $mode);
                    foreach ($metrics as $key => $unused) {
                        $metrics[$key] += $value[$key];
                    }
                    $outputBytes += strlen($value['html']);
                }
                $wall = hrtime(true) - $wall;
                $cpu = loadingCpuNs() - $cpu;
                unset($value);
                if ($round >= 0) {
                    $result['samples'][$workload][$mode][] = ['round' => $round, 'renders' => $batch,
                        'cpu_us' => $cpu / $batch / 1000, 'wall_us' => $wall / $batch / 1000,
                        'queries' => $metrics['queries'] / $batch, 'rows' => $metrics['rows'] / $batch,
                        'payload_bytes' => $metrics['bytes'] / $batch, 'output_bytes' => $outputBytes / $batch];
                }
            }
        }
        // Separate diagnostic: actual allocated bytes above the pre-render base.
        // The compiled code and SQLite page cache are already warm in all modes.
        foreach ($modes as $mode) {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $base = memory_get_usage(false);
            $value = $render($inputs[0], $mode);
            $result['memory'][$workload][$mode] = ['php_peak_delta_bytes' => memory_get_peak_usage(false) - $base];
            unset($value);
        }
        fwrite(STDERR, "Finished {$workload}\n");
    }
    $hashes = [];
    foreach ([dirname(__DIR__, 2).'/src', __DIR__] as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $hashes[substr($file->getPathname(), strlen(dirname(__DIR__, 2)) + 1)] = hash_file('sha256', $file->getPathname());
            }
        }
    }
    ksort($hashes);
    $result['source_sha256'] = hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR));
    $result['database_sha256'] = hash_file('sha256', $database);
    $result['database_bytes'] = filesize($database);
    $result['fixture_sha256'] = hash('sha256', json_encode($sources, JSON_THROW_ON_ERROR));
    $result['reference_sha256'] = hash_file('sha256', $referencePath);
    $result['jit_after'] = opcache_get_status(false)['jit'] ?? null;
    $result['process_rss_peak_kib'] = (getrusage() ?: [])['ru_maxrss'] ?? null;
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    if (isset($options['output']) && is_string($options['output'])) {
        file_put_contents($options['output'], $json);
    } else {
        echo $json;
    }
} finally {
    $env->templatesCache->clear();
    rmdir($directory);
}

/** Independent eager array projection for Ruby reference (not timed).
 * @return list<array<string, mixed>>
 */
function referenceProducts(PDO $pdo, int $tenant): array
{
    $products = $pdo->prepare('SELECT id,title,url,image,price_formatted,available FROM products WHERE tenant_id = ? ORDER BY id LIMIT 24');
    $products->execute([$tenant]);
    /** @var list<array<string, mixed>> $result */
    $result = $products->fetchAll(PDO::FETCH_ASSOC);
    foreach ($result as &$product) {
        $variants = $pdo->prepare('SELECT id,title,available FROM variants WHERE tenant_id = ? AND product_id = ? ORDER BY id');
        $variants->execute([$tenant, $product['id']]);
        $product['variants'] = $variants->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($product);

    return $result;
}
