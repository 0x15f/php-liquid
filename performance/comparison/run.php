<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/fixtures.php';
require __DIR__.'/bridges.php';

use Keepsuit\Liquid\Contracts\LiquidFileSystem;
use Keepsuit\Liquid\EnvironmentFactory;
use Keepsuit\Liquid\TemplatesCache\CompiledTemplatesCache;

function comparisonCpuNs(): int
{
    $r = getrusage();

    return (($r['ru_utime.tv_sec'] + $r['ru_stime.tv_sec']) * 1_000_000
        + $r['ru_utime.tv_usec'] + $r['ru_stime.tv_usec']) * 1000;
}

$options = getopt('', ['output:', 'rounds:', 'label:', 'php-only', 'check-only', 'workload:', 'paired-baseline', 'warmup:', 'prepared']);
$rounds = (int) ($options['rounds'] ?? 31);
$warmup = (int) ($options['warmup'] ?? 10);
if (! isset($options['check-only']) && (extension_loaded('xdebug') || extension_loaded('pcov'))) {
    throw new RuntimeException('Timing requires Xdebug and PCOV to be unloaded, not merely disabled.');
}
$fixtures = comparisonFixtures();
$filesystem = new class($fixtures['templates']) implements LiquidFileSystem
{
    public function __construct(private array $sources) {}

    public function readTemplateFile(string $templateName): string
    {
        return $this->sources[$templateName] ?? throw new RuntimeException($templateName);
    }
};
$directory = sys_get_temp_dir().'/liquid-compare-'.bin2hex(random_bytes(8));
$factory = fn () => EnvironmentFactory::new()->setFilesystem($filesystem)->setStrictVariables(true)->setStrictFilters(true)->setRethrowErrors(true);
$start = hrtime(true);
$parsed = $factory()->build();
foreach (array_keys($fixtures['templates']) as $name) {
    $parsed->parseTemplate($name);
}
$parsedInitMs = (hrtime(true) - $start) / 1e6;
$start = hrtime(true);
$compiled = $factory()->setTemplatesCache(new CompiledTemplatesCache($directory))->build();
foreach (array_keys($fixtures['templates']) as $name) {
    $compiled->parseTemplate($name);
}
$compiledInitMs = (hrtime(true) - $start) / 1e6;
$backends = [
    'php_parsed' => fn (array $r) => $parsed->parseTemplate($r['template'])->render($parsed->newRenderContext(data: $r['data'])),
    'php_compiled' => fn (array $r) => $compiled->parseTemplate($r['template'])->render($compiled->newRenderContext(data: $r['data'])),
];
$initialization = ['php_parsed' => $parsedInitMs, 'php_compiled' => $compiledInitMs];
$baselineDirectory = null;
if (isset($options['paired-baseline'])) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'LiquidBaseline\\Liquid\\';
        if (str_starts_with($class, $prefix)) {
            require dirname(__DIR__, 2).'/build/comparison-baseline/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        }
    });
    $baselineFilesystem = new class($fixtures['templates']) implements LiquidBaseline\Liquid\Contracts\LiquidFileSystem
    {
        public function __construct(private array $sources) {}

        public function readTemplateFile(string $name): string
        {
            return $this->sources[$name];
        }
    };
    $baselineDirectory = $directory.'-baseline';
    $baseline = LiquidBaseline\Liquid\EnvironmentFactory::new()->setFilesystem($baselineFilesystem)
        ->setStrictVariables(true)->setStrictFilters(true)->setRethrowErrors(true)
        ->setTemplatesCache(new LiquidBaseline\Liquid\TemplatesCache\CompiledTemplatesCache($baselineDirectory))->build();
    foreach (array_keys($fixtures['templates']) as $name) {
        $baseline->parseTemplate($name);
    }
    $backends['php_compiled_baseline'] = fn (array $r) => $baseline->parseTemplate($r['template'])->render($baseline->newRenderContext(data: $r['data']));
}
$encode = fn ($r) => json_encode($r, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$nativeInputs = [];
$indices = [];
if (isset($options['prepared'])) {
    foreach ($fixtures['workloads'] as $workload => $requests) {
        foreach ($requests as $i => $request) {
            $indices[$workload][$i] = count($nativeInputs);
            $nativeInputs[] = $request['data'];
        }
    }
}
$rustInit = $encode(['op' => 'init', 'templates' => $fixtures['templates'], 'inputs' => $nativeInputs]);
$extismVersion = null;
$referencePath = dirname(__DIR__, 2).'/build/reference-hashes.json';
$referenceHashes = is_file($referencePath) ? json_decode(file_get_contents($referencePath), true, flags: JSON_THROW_ON_ERROR) : null;
if (! isset($options['php-only'])) {
    foreach (['rust_native' => NativeLiquidBridge::class, 'rust_wasm' => WasmLiquidBridge::class] as $name => $class) {
        $start = hrtime(true);
        $bridge = new $class(__DIR__.'/artifacts/'.($name === 'rust_native' ? 'libliquid_comparison.so' : 'liquid_comparison.wasm'));
        if ($bridge->call($rustInit) !== 'OK') {
            throw new RuntimeException('Initialization failed');
        }
        $initialization[$name] = (hrtime(true) - $start) / 1e6;
        $backends[$name] = isset($options['prepared'])
            ? static fn (array $r) => $bridge->call($encode(['op' => 'prepared', 'template' => $r['template'], 'index' => $r['_prepared_index']]))
            : static fn (array $r) => $bridge->call($encode($r));
        if ($bridge instanceof WasmLiquidBridge) {
            $extismVersion = $bridge->version();
        }
    }
}
$sourceHash = hash_init('sha256');
$sourceRoot = dirname(__DIR__, 2).'/src/';
$sourceFiles = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $sourceFiles[] = $file->getPathname();
    }
}
sort($sourceFiles);
foreach ($sourceFiles as $path) {
    hash_update($sourceHash, substr($path, strlen($sourceRoot)).file_get_contents($path));
}
$result = ['label' => $options['label'] ?? 'unspecified', 'time_utc' => gmdate('c'),
    'php' => PHP_VERSION, 'arch' => php_uname('m'), 'kernel' => php_uname('r'),
    'extism' => $extismVersion, 'engine_source_sha256' => hash_final($sourceHash),
    'rust_source_sha256' => hash_file('sha256', __DIR__.'/rust/src/lib.rs'),
    'cargo_lock_sha256' => hash_file('sha256', __DIR__.'/rust/Cargo.lock'),
    'native_sha256' => is_file(__DIR__.'/artifacts/libliquid_comparison.so') ? hash_file('sha256', __DIR__.'/artifacts/libliquid_comparison.so') : null,
    'wasm_sha256' => is_file(__DIR__.'/artifacts/liquid_comparison.wasm') ? hash_file('sha256', __DIR__.'/artifacts/liquid_comparison.wasm') : null,
    'opcache' => opcache_get_status(false)['opcache_enabled'] ?? false, 'jit' => opcache_get_status(false)['jit'] ?? null,
    'profilers_loaded' => ['xdebug' => extension_loaded('xdebug'), 'pcov' => extension_loaded('pcov')], 'warmup_rounds' => $warmup,
    'xdebug_mode' => ini_get('xdebug.mode'), 'cpu_affinity' => trim(shell_exec('taskset -pc '.getmypid()) ?? ''),
    'cpu_max' => trim(file_get_contents('/sys/fs/cgroup/cpu.max')),
    'initialization_ms' => $initialization, 'fixture_sha256' => hash('sha256', $encode($fixtures)),
    'rounds' => $rounds, 'input_mode' => isset($options['prepared']) ? 'preloaded-native-contexts' : 'php-arrays-with-bridge-conversion', 'samples' => [], 'checks' => [], 'memory' => []];
try {
    foreach ($fixtures['workloads'] as $workload => $requests) {
        if (isset($options['workload']) && $options['workload'] !== $workload) {
            continue;
        }
        if (isset($options['prepared'])) {
            foreach ($requests as $i => &$request) {
                $request['_prepared_index'] = $indices[$workload][$i];
            }
            unset($request);
        }
        // Verify every personalized input, including A -> B -> A request reuse.
        foreach ($requests as $i => $request) {
            $expected = $backends['php_parsed']($request);
            if ($referenceHashes !== null && hash('sha256', $expected) !== ($referenceHashes[$workload][$i] ?? null)) {
                throw new RuntimeException("Shopify reference mismatch: {$workload} request {$i}");
            }
            foreach ($backends as $name => $render) {
                if ($render($request) !== $expected) {
                    throw new RuntimeException("Output mismatch: {$workload} {$name} request {$i}");
                }
            }
        }
        foreach ($backends as $name => $render) {
            if ($render($requests[0]) !== $backends['php_parsed']($requests[0])) {
                throw new RuntimeException('Request reuse mismatch');
            }
        }
        $result['checks'][$workload] = ['personalized_inputs' => count($requests), 'shopify_reference' => $referenceHashes !== null, 'output_bytes' => strlen($expected), 'input_json_bytes' => strlen($encode($requests[0]))];
        if (isset($options['check-only'])) {
            continue;
        }
        // Each batch covers the same complete customer cycles. Independent batch
        // lengths avoid timing fast engines below the CPU clock's resolution or
        // making slow engines run for seconds per sample.
        $revolutions = [];
        foreach ($backends as $name => $render) {
            $begin = comparisonCpuNs();
            foreach ($requests as $request) {
                $render($request);
            }
            $cycleCpu = max(1, comparisonCpuNs() - $begin);
            $revolutions[$name] = 16 * max(1, min(128, (int) ceil(30_000_000 / $cycleCpu)));
        }
        $names = array_keys($backends);
        for ($round = -$warmup; $round < $rounds; $round++) {
            // Balanced rotation; reverse every complete cycle to reduce order bias.
            $offset = ($round + $warmup) % count($names);
            $order = array_merge(array_slice($names, $offset), array_slice($names, 0, $offset));
            if (intdiv($round + $warmup, count($names)) % 2) {
                $order = array_reverse($order);
            }
            foreach ($order as $name) {
                $render = $backends[$name];
                $revs = $revolutions[$name];
                $cpu = comparisonCpuNs();
                $wall = hrtime(true);
                $bytes = 0;
                for ($i = 0; $i < $revs; $i++) {
                    $bytes += strlen($render($requests[$i % count($requests)]));
                }
                $wall = hrtime(true) - $wall;
                $cpu = comparisonCpuNs() - $cpu;
                if ($round >= 0) {
                    $result['samples'][$workload][$name][] = ['round' => $round, 'revs' => $revs,
                        'cpu_us' => $cpu / $revs / 1000, 'wall_us' => $wall / $revs / 1000, 'output_bytes' => $bytes];
                }
            }
        }
        fwrite(STDERR, "Finished {$workload}\n");
    }
    $result['memory'] = ['php_peak_bytes' => memory_get_peak_usage(true), 'process_max_rss_kib' => getrusage()['ru_maxrss']];
    $result['jit_after'] = opcache_get_status(false)['jit'] ?? null;
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    if (isset($options['output'])) {
        file_put_contents($options['output'], $json);
    } else {
        echo $json;
    }
} finally {
    $compiled->templatesCache->clear();
    rmdir($directory);
    if ($baselineDirectory !== null) {
        $baseline->templatesCache->clear();
        rmdir($baselineDirectory);
    }
}
