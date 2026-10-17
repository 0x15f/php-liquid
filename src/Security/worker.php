<?php

// This entry point is launched only by IsolatedRenderer with application-controlled arguments.
if (PHP_SAPI !== 'cli' || $argc !== 3) {
    exit(1);
}
require $argv[1];

use Keepsuit\Liquid\EnvironmentFactory;
use Keepsuit\Liquid\Exceptions\ResourceLimitException;
use Keepsuit\Liquid\FileSystems\ArrayFileSystem;
use Keepsuit\Liquid\TemplatesCache\CompiledTemplatesCache;

$activeIdentity = null;
$environment = null;

while (($line = fgets(STDIN, 4_194_306)) !== false) {
    try {
        if (! str_ends_with($line, "\n")) {
            exit(1);
        }
        $request = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($request) || ! is_array($request['templates'] ?? null) || ! is_array($request['data'] ?? null)
            || ! is_string($request['namespace'] ?? null) || ! is_string($request['name'] ?? null)) {
            exit(1);
        }
        foreach ($request['templates'] as $source) {
            if (! is_string($source)) {
                exit(1);
            }
        }
        /** @var array{templates: array<string, string>, data: array<string, mixed>, namespace: string, name: string} $request */
        $templates = $request['templates'];
        (new \Keepsuit\Liquid\Security\TemplatePolicy)->assertTemplates($templates);
        ksort($templates);
        $identity = hash('sha256', serialize([$request['namespace'], $templates]));
        if ($activeIdentity !== $identity || $environment === null) {
            $environment = EnvironmentFactory::forUntrustedTemplates()->setFilesystem(new ArrayFileSystem($templates))
                ->setTemplatesCache(new CompiledTemplatesCache($argv[2].'/'.$identity))->build();
            $activeIdentity = $identity;
        }
        $output = $environment->parseTemplate($request['name'])->render($environment->newRenderContext(data: $request['data']));
        $response = ['ok' => true, 'output_base64' => base64_encode($output)];
    } catch (Throwable $error) {
        $response = ['ok' => false, 'resource_limit' => $error instanceof ResourceLimitException];
    }
    echo json_encode($response, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    unset($request, $templates, $output, $response, $error);
    gc_collect_cycles();
}
