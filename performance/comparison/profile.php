<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/fixtures.php';

use Keepsuit\Liquid\Contracts\LiquidFileSystem;
use Keepsuit\Liquid\EnvironmentFactory;
use Keepsuit\Liquid\TemplatesCache\CompiledTemplatesCache;

$fixtures = comparisonFixtures();
$filesystem = new class($fixtures['templates']) implements LiquidFileSystem
{
    public function __construct(private array $sources) {}

    public function readTemplateFile(string $name): string
    {
        return $this->sources[$name];
    }
};
$directory = sys_get_temp_dir().'/liquid-profile-'.getmypid();
$environment = EnvironmentFactory::new()->setFilesystem($filesystem)
    ->setTemplatesCache(new CompiledTemplatesCache($directory))->setRethrowErrors(true)->build();
foreach (array_keys($fixtures['templates']) as $name) {
    $environment->parseTemplate($name);
}
$template = $environment->parseTemplate('grid');
try {
    for ($i = 0; $i < 200; $i++) {
        $request = $fixtures['workloads']['grid_24'][$i % 16];
        $template->render($environment->newRenderContext(data: $request['data']));
    }
} finally {
    $environment->templatesCache->clear();
    rmdir($directory);
}
