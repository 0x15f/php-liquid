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

/** @param array<string, mixed> $response */
$send = static function (array $response): void {
    $encoded = json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    $offset = 0;
    while ($offset < strlen($encoded)) {
        $written = fwrite(STDOUT, substr($encoded, $offset, 8192));
        if ($written === false || $written === 0) {
            exit(1);
        }
        $offset += $written;
    }
};

$activeIdentity = null;
$environment = null;
$cachedSnapshots = [];

while (($line = fgets(STDIN, 4_194_306)) !== false) {
    try {
        if (! str_ends_with($line, "\n")) {
            exit(1);
        }
        $request = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($request) || ! is_array($request['templates'] ?? null) || ! is_array($request['data'] ?? null)
            || ! is_string($request['namespace'] ?? null) || ! is_string($request['name'] ?? null) || ! is_bool($request['stream'] ?? null) || ! is_string($request['timezone'] ?? null)) {
            exit(1);
        }
        foreach ($request['templates'] as $source) {
            if (! is_string($source)) {
                exit(1);
            }
        }
        /** @var array{templates: array<string, string>, data: array<string, mixed>, namespace: string, name: string, stream: bool, timezone: string} $request */
        if (strlen($request['timezone']) > 128 || ! date_default_timezone_set($request['timezone'])) {
            exit(1);
        }
        $templates = $request['templates'];
        (new \Keepsuit\Liquid\Security\TemplatePolicy)->assertTemplates($templates);
        ksort($templates);
        $identity = hash('sha256', serialize([$request['namespace'], $templates]));
        if ($activeIdentity !== $identity || $environment === null) {
            if (! isset($cachedSnapshots[$identity]) && count($cachedSnapshots) >= 4) {
                $oldest = array_key_first($cachedSnapshots);
                if ($oldest !== null) {
                    $oldDirectory = $argv[2].'/'.$oldest;
                    (new CompiledTemplatesCache($oldDirectory))->clear();
                    rmdir($oldDirectory);
                    unset($cachedSnapshots[$oldest]);
                }
            }
            unset($cachedSnapshots[$identity]);
            $cachedSnapshots[$identity] = true;
            $environment = EnvironmentFactory::forUntrustedTemplates()->setFilesystem(new ArrayFileSystem($templates))
                ->setTemplatesCache(new CompiledTemplatesCache($argv[2].'/'.$identity))->build();
            $activeIdentity = $identity;
        }
        $template = $environment->parseTemplate($request['name']);
        $context = $environment->newRenderContext(data: $request['data']);
        if ($request['stream']) {
            foreach ($template->stream($context) as $output) {
                $send(['chunk_base64' => base64_encode($output)]);
            }
            $response = ['ok' => true];
        } else {
            $output = $template->render($context);
            $response = ['ok' => true, 'output_base64' => base64_encode($output)];
        }
    } catch (Throwable $error) {
        $response = ['ok' => false, 'resource_limit' => $error instanceof ResourceLimitException];
    }
    $send($response);
    unset($request, $templates, $template, $context, $output, $response, $error);
    gc_collect_cycles();
}
