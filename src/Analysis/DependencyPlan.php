<?php

namespace Keepsuit\Liquid\Analysis;

/** Advisory field dependencies, never an authorization rule or a complete query plan. */
final class DependencyPlan implements \JsonSerializable
{
    public const VERSION = 1;

    public function __construct(
        /** @var list<string> */
        public readonly array $paths,
        /** @var list<array{path: ?string, limit: ?int, offset: ?int, reversed: bool, static_window: bool}> */
        public readonly array $loops,
        /** @var list<string> */
        public readonly array $templates,
        /** @var list<string> */
        public readonly array $runtimeReasons,
    ) {}

    /** Direct fields only. Nested relationships and dynamic segments are not columns.
     * @return list<string>
     */
    public function fields(string $prefix): array
    {
        $fields = [];
        foreach ($this->paths as $path) {
            if (! str_starts_with($path, $prefix.'.')) {
                continue;
            }
            $field = substr($path, strlen($prefix) + 1);
            if ($field !== '*' && ! str_contains($field, '.')) {
                $fields[] = $field;
            }
        }

        return array_values(array_unique($fields));
    }

    /** @return array{version: int, paths: list<string>, loops: array, templates: list<string>, runtime_reasons: list<string>} */
    public function jsonSerialize(): array
    {
        return ['version' => self::VERSION, 'paths' => $this->paths, 'loops' => $this->loops,
            'templates' => $this->templates, 'runtime_reasons' => $this->runtimeReasons];
    }
}
