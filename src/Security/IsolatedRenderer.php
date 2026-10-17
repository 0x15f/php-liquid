<?php

namespace Keepsuit\Liquid\Security;

use Composer\Autoload\ClassLoader;
use Keepsuit\Liquid\Exceptions\IsolatedRenderException;
use Keepsuit\Liquid\Exceptions\ResourceLimitException;

/**
 * Reuses a minimal PHP worker with compiled templates and fresh request contexts.
 * Only JSON data crosses the boundary; no application bootstrap, callbacks or custom extensions.
 */
final class IsolatedRenderer
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private ?string $directory = null;

    private int $requests = 0;

    public function __construct(
        private readonly int $timeoutMilliseconds = 1000,
        private readonly int $memoryMegabytes = 128,
        private readonly int $maxRequests = 100,
        private readonly ?string $autoloadPath = null,
        private readonly ?string $phpBinary = null,
    ) {
        if ($timeoutMilliseconds < 1 || $memoryMegabytes < 32 || $maxRequests < 1) {
            throw new \InvalidArgumentException('Worker limits must be positive; memory must be at least 32 MiB.');
        }
    }

    /**
     * The application must authorize the entire template snapshot and data before calling.
     *
     * @param  array<string, string>  $templates
     * @param  array<string, mixed>  $data
     */
    public function render(array $templates, string $name, array $data = [], string $namespace = 'default'): string
    {
        $output = '';
        foreach ($this->request($templates, $name, $data, $namespace, false) as $chunk) {
            $output = $chunk;
        }

        return $output;
    }

    /**
     * @param  array<string, string>  $templates
     * @param  array<string, mixed>  $data
     * @return \Generator<int, string>
     */
    public function stream(array $templates, string $name, array $data = [], string $namespace = 'default'): \Generator
    {
        return $this->request($templates, $name, $data, $namespace, true);
    }

    private bool $busy = false;

    /**
     * @param  array<string, string>  $templates
     * @param  array<string, mixed>  $data
     * @return \Generator<int, string>
     */
    private function request(array $templates, string $name, array $data, string $namespace, bool $stream): \Generator
    {
        if ($this->busy) {
            throw new IsolatedRenderException('A renderer cannot execute overlapping requests.');
        }
        $policy = new TemplatePolicy;
        $policy->assertData($data);
        $policy->assertTemplates($templates);
        $timezone = date_default_timezone_get();
        $request = compact('templates', 'name', 'data', 'namespace', 'stream', 'timezone');
        $remaining = 4_194_303;
        $this->checkTransportBudget($request, $remaining);
        $payload = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        if (strlen($payload) > 4_194_304) {
            throw new ResourceLimitException('Worker request exceeds 4 MiB.');
        }
        if ($this->requests >= $this->maxRequests) {
            $this->close();
        }
        $this->start();
        $this->requests++;
        $this->busy = true;
        $completed = false;
        try {
            foreach ($this->exchange($payload) as $message) {
                if (isset($message['chunk_base64']) && is_string($message['chunk_base64'])) {
                    yield $this->decodeOutput($message['chunk_base64']);

                    continue;
                }
                if (! isset($message['ok']) || ! is_bool($message['ok'])) {
                    throw new IsolatedRenderException('Invalid worker response.');
                }
                if (! $message['ok']) {
                    if (($message['resource_limit'] ?? false) === true) {
                        throw new ResourceLimitException('Isolated template exceeded a resource limit.');
                    }
                    throw new IsolatedRenderException('Isolated template was rejected.');
                }
                if (! $stream) {
                    if (! isset($message['output_base64']) || ! is_string($message['output_base64'])) {
                        throw new IsolatedRenderException('Invalid worker output.');
                    }
                    yield $this->decodeOutput($message['output_base64']);
                }
                $completed = true;

                return;
            }
            throw new IsolatedRenderException('Worker response was incomplete.');
        } finally {
            // Cancellation and every failure discard unread frames and release the worker.
            if (! $completed) {
                $this->close();
            }
            $this->busy = false;
        }
    }

    private function decodeOutput(string $encoded): string
    {
        $output = base64_decode($encoded, true);
        if ($output === false) {
            throw new IsolatedRenderException('Invalid worker output encoding.');
        }

        return $output;
    }

    /** Bound worst-case JSON expansion before allocating the encoded request in the parent. */
    private function checkTransportBudget(mixed $value, int &$remaining, int $depth = 0): void
    {
        if ($depth > 60) {
            throw new ResourceLimitException('Worker request nesting exceeds the transport budget.');
        }
        if (is_array($value)) {
            $remaining -= count($value) + 2;
            $list = array_is_list($value);
            foreach ($value as $key => $item) {
                if (! $list) {
                    $remaining -= 6 * strlen((string) $key) + 3;
                }
                $this->checkTransportBudget($item, $remaining, $depth + 1);
            }
        } elseif (is_string($value)) {
            $remaining -= 6 * strlen($value) + 2;
        } elseif (is_float($value)) {
            $remaining -= 2048;
        } elseif ($value === null || is_int($value) || is_bool($value)) {
            $remaining -= 32;
        } else {
            throw new \Keepsuit\Liquid\Exceptions\TemplateSecurityException('Isolated inputs must contain only JSON values.');
        }
        if ($remaining < 0) {
            throw new ResourceLimitException('Worker request exceeds its conservative JSON expansion budget.');
        }
    }

    private function start(): void
    {
        if ($this->process !== null) {
            return;
        }
        $autoload = $this->autoloadPath;
        if ($autoload === null) {
            foreach (ClassLoader::getRegisteredLoaders() as $directory => $loader) {
                if (realpath($loader->findFile(self::class) ?: '') === __FILE__) {
                    $autoload = $directory.'/autoload.php';
                    break;
                }
            }
        }
        if ($autoload === null || ! is_file($autoload)) {
            throw new \RuntimeException('Supply the application Composer autoload path for the isolated worker.');
        }
        $this->directory = sys_get_temp_dir().'/liquid-worker-'.bin2hex(random_bytes(16));
        if (! mkdir($this->directory, 0700)) {
            throw new \RuntimeException('Unable to create private worker cache.');
        }
        $binary = $this->phpBinary ?? (PHP_SAPI === 'cli' ? PHP_BINARY : PHP_BINDIR.'/php');
        if (! is_executable($binary) || PHP_OS_FAMILY === 'Windows') {
            $this->close();
            throw new \RuntimeException('Isolated rendering requires a POSIX PHP CLI executable.');
        }
        $process = proc_open([$binary, '-d', 'memory_limit='.$this->memoryMegabytes.'M', '-d', 'max_execution_time=0',
            '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'opcache.enable_cli=0',
            dirname(__DIR__, 2).'/resources/isolated-worker.php', $autoload, $this->directory], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            $this->close();
            throw new \RuntimeException('Unable to start isolated PHP worker.');
        }
        $this->process = $process;
        $this->pipes = $pipes;
        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function exchange(string $payload): \Generator
    {
        $deadline = hrtime(true) + $this->timeoutMilliseconds * 1_000_000;
        $sent = 0;
        $response = '';
        $diagnostic = '';
        $received = 0;
        $scanFrom = 0;
        while (true) {
            if ($this->process === null) {
                throw new IsolatedRenderException('Renderer was closed during the request.');
            }
            $remaining = $deadline - hrtime(true);
            if ($remaining <= 0) {
                throw new ResourceLimitException('Isolated render exceeded its wall-clock deadline.');
            }
            $read = feof($this->pipes[2]) ? [$this->pipes[1]] : [$this->pipes[2], $this->pipes[1]];
            $write = $sent < strlen($payload) ? [$this->pipes[0]] : [];
            $except = null;
            $ready = stream_select($read, $write, $except, 0, (int) min(10000, ceil($remaining / 1000)));
            if ($ready === false) {
                throw new IsolatedRenderException('Worker communication failed.');
            }
            foreach ($write as $pipe) {
                $written = @fwrite($pipe, substr($payload, $sent, 8192));
                if ($written === false) {
                    throw new IsolatedRenderException('Worker stopped accepting input.');
                }
                $sent += $written;
            }
            foreach ($read as $pipe) {
                $chunk = fread($pipe, 8192);
                if ($chunk === false) {
                    throw new IsolatedRenderException('Worker communication failed.');
                }
                if ($pipe === $this->pipes[2]) {
                    $diagnostic = substr($diagnostic.$chunk, -4096);

                    continue;
                }
                if ($chunk === '' && feof($pipe)) {
                    if (str_contains($diagnostic, 'Allowed memory size')) {
                        throw new ResourceLimitException('Isolated worker exceeded its memory allowance.');
                    }
                    throw new IsolatedRenderException('Worker exited before completing the render.');
                }
                if ($pipe === $this->pipes[1]) {
                    $received += strlen($chunk);
                    if ($received > 61_000_000) {
                        throw new ResourceLimitException('Worker response exceeds its transport budget.');
                    }
                    $response .= $chunk;
                    while (($end = strpos($response, "\n", $scanFrom)) !== false) {
                        $message = json_decode(substr($response, 0, $end), true, 64, JSON_THROW_ON_ERROR);
                        if (! is_array($message)) {
                            throw new IsolatedRenderException('Invalid worker frame.');
                        }
                        /** @var array<string, mixed> $message */
                        $response = substr($response, $end + 1);
                        $scanFrom = 0;
                        if (hrtime(true) >= $deadline) {
                            throw new ResourceLimitException('Isolated render exceeded its wall-clock deadline.');
                        }
                        yield $message;
                        if (array_key_exists('ok', $message)) {
                            return;
                        }
                    }
                    $scanFrom = strlen($response);
                }
                // Drain stderr so warnings cannot block the child or leak application details.
            }
        }
    }

    public function close(): void
    {
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        $this->pipes = [];
        if ($this->process !== null) {
            proc_terminate($this->process, 9);
            proc_close($this->process);
            $this->process = null;
        }
        if ($this->directory !== null) {
            $this->removeDirectory($this->directory);
            $this->directory = null;
        }
        $this->requests = 0;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (new \FilesystemIterator($directory) as $entry) {
            assert($entry instanceof \SplFileInfo);
            if ($entry->isDir() && ! $entry->isLink()) {
                $this->removeDirectory($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }

    public function __destruct()
    {
        $this->close();
    }
}
