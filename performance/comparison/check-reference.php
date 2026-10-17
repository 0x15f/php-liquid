<?php

require __DIR__.'/bridges.php';
$fixtures = json_decode(file_get_contents(dirname(__DIR__, 2).'/tests/Fixtures/shopify-review.json'), true, flags: JSON_THROW_ON_ERROR);
$templates = [];
foreach ($fixtures as $i => $case) {
    $templates['case_'.$i] = $case['template'];
}
$report = [];
foreach (['native' => new NativeLiquidBridge(__DIR__.'/artifacts/libliquid_comparison.so'), 'wasm' => new WasmLiquidBridge(__DIR__.'/artifacts/liquid_comparison.wasm')] as $name => $bridge) {
    foreach ($fixtures as $i => $case) {
        try {
            $bridge->call(json_encode(['op' => 'init', 'templates' => ['case_'.$i => $case['template']]], JSON_THROW_ON_ERROR));
            $actual = $bridge->call(json_encode(['op' => 'render', 'template' => 'case_'.$i, 'data' => (object) $case['data']], JSON_THROW_ON_ERROR));
            $report[$name][$case['name']] = ['pass' => $actual === $case['expected'], 'expected' => $case['expected'], 'actual' => $actual];
        } catch (Throwable $error) {
            $report[$name][$case['name']] = ['pass' => false, 'expected' => $case['expected'], 'error' => $error->getMessage()];
        }
    }
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
