<?php

namespace Keepsuit\Liquid\Analysis;

use Closure;
use Keepsuit\Liquid\Contracts\HasParseTreeVisitorChildren;
use Keepsuit\Liquid\Nodes\BodyNode;
use Keepsuit\Liquid\Nodes\BracketKey;
use Keepsuit\Liquid\Nodes\Node;
use Keepsuit\Liquid\Nodes\Variable;
use Keepsuit\Liquid\Nodes\VariableLookup;
use Keepsuit\Liquid\ParsedTemplate;
use Keepsuit\Liquid\Tag;
use Keepsuit\Liquid\Tags\AssignTag;
use Keepsuit\Liquid\Tags\ForTag;
use Keepsuit\Liquid\Tags\RenderTag;

/**
 * Scans the AST without executing templates, filters, Drops or queries.
 * Paths are conservative hints. Runtime resolvers remain authoritative.
 */
final class DependencyPlanner
{
    /** @var array<string, true> */
    private array $paths = [];

    /** @var list<array{path: ?string, limit: ?int, offset: ?int, reversed: bool, static_window: bool}> */
    private array $loops = [];

    /** @var array<string, true> */
    private array $templates = [];

    /** @var array<string, true> */
    private array $reasons = [];

    /** @var array<string, true> */
    private array $stack = [];

    /** @param Closure(string): ParsedTemplate $loadPartial */
    public function __construct(private readonly Closure $loadPartial) {}

    public function analyse(ParsedTemplate $template): DependencyPlan
    {
        // Independent scans, even when the planner is reused in a long-lived worker.
        $scan = new self($this->loadPartial);
        $aliases = [];
        $scan->walk($template->root->body, $aliases);
        $paths = array_keys($scan->paths);
        sort($paths);
        $templates = array_keys($scan->templates);
        sort($templates);

        return new DependencyPlan($paths, $scan->loops, $templates, array_keys($scan->reasons));
    }

    /** @param array<string, ?string> $aliases */
    private function path(mixed $value, array $aliases): ?string
    {
        if (! $value instanceof VariableLookup) {
            return null;
        }
        $path = array_key_exists($value->name, $aliases) ? $aliases[$value->name] : $value->name;
        if ($path === null) {
            return null;
        }
        foreach ($value->lookups as $key) {
            if ($key instanceof VariableLookup) {
                $this->reasons['dynamic bracket lookup'] = true;
                $path .= '.*';
            } else {
                $key = $key instanceof BracketKey ? $key->value : $key;
                // Numeric indexing refers to a collection element; dotted names
                // are ambiguous in this advisory representation and stay dynamic.
                $path .= '.'.(is_int($key) || ctype_digit((string) $key) ? '*' : (str_contains((string) $key, '.') ? '*' : $key));
            }
        }

        return $path;
    }

    /** @param array<string, ?string> $aliases */
    private function walk(mixed $node, array &$aliases, int $depth = 0): void
    {
        if ($depth > 128) {
            $this->reasons['analysis depth limit'] = true;

            return;
        }
        if ($node instanceof VariableLookup) {
            if (($path = $this->path($node, $aliases)) !== null) {
                $this->paths[$path] = true;
            }
            foreach ($node->lookups as $key) {
                if ($key instanceof VariableLookup) {
                    $this->walk($key, $aliases, $depth + 1);
                }
            }

            return;
        }
        if ($node instanceof BodyNode) {
            foreach ($node->children() as $child) {
                $this->walk($child, $aliases, $depth + 1);
            }

            return;
        }
        if ($node instanceof AssignTag && $node::class === AssignTag::class) {
            $meta = $node->dependencyMetadata();
            $this->walk($meta['value'], $aliases, $depth + 1);
            $aliases[$meta['target']] = $meta['value']->filters === [] ? $this->path($meta['value']->name, $aliases) : null;

            return;
        }
        if ($node instanceof ForTag && $node::class === ForTag::class) {
            $meta = $node->dependencyMetadata();
            foreach ([$meta['collection'], $meta['limit'], $meta['offset']] as $expression) {
                $this->walk($expression, $aliases, $depth + 1);
            }
            $path = $this->path($meta['collection'], $aliases);
            $limit = is_int($meta['limit']) ? $meta['limit'] : null;
            $offset = $meta['offset'] === null ? 0 : (is_int($meta['offset']) ? $meta['offset'] : null);
            $this->loops[] = ['path' => $path, 'limit' => $limit, 'offset' => $offset,
                'reversed' => $meta['reversed'], 'static_window' => $path !== null && $limit !== null && $limit >= 0 && $offset !== null && $offset >= 0];
            $inner = $aliases;
            $inner[$meta['variable']] = $path === null ? null : $path.'.*';
            $inner['forloop'] = null;
            $this->walk($meta['body'], $inner, $depth + 1);
            if ($meta['else'] !== null) {
                $other = $aliases;
                $this->walk($meta['else'], $other, $depth + 1);
                $this->invalidateAssignments($meta['else'], $aliases);
            }
            $this->invalidateAssignments($meta['body'], $aliases);

            return;
        }
        if ($node instanceof RenderTag) {
            $meta = $node->dependencyMetadata();
            foreach ([$meta['template'], $meta['value'], ...array_values($meta['attributes'])] as $expression) {
                $this->walk($expression, $aliases, $depth + 1);
            }
            if (! is_string($meta['template'])) {
                $this->reasons['dynamic snippet name'] = true;

                return;
            }
            $name = $meta['template'];
            $this->templates[$name] = true;
            if (isset($this->stack[$name])) {
                $this->reasons['recursive snippet'] = true;

                return;
            }
            // render isolates caller locals, but globals are still available.
            $inner = array_fill_keys(array_keys($aliases), null);
            $alias = $meta['alias'] ?? RenderTag::defaultVariableName($name);
            $inner[$alias] = $this->path($meta['value'], $aliases);
            if ($meta['for'] && $inner[$alias] !== null) {
                $inner[$alias] .= '.*';
                $inner['forloop'] = null;
            }
            foreach ($meta['attributes'] as $key => $value) {
                $inner[$key] = $this->path($value, $aliases);
            }
            $this->stack[$name] = true;
            try {
                $this->walk(($this->loadPartial)($name)->root->body, $inner, $depth + 1);
            } finally {
                unset($this->stack[$name]);
            }

            return;
        }
        if ($node instanceof Variable && $node->filters !== []) {
            $this->reasons['filter semantics require runtime evaluation'] = true;
        }
        $children = $this->children($node);
        foreach ($children as $child) {
            $inner = $aliases;
            $this->walk($child, $inner, $depth + 1);
        }
        if ($node instanceof Tag) {
            $this->reasons['conditional or unsupported tag: '.$node::tagName()] = true;
            $this->invalidateAssignments($node, $aliases);
        }
    }

    /** @return array<mixed> */
    private function children(mixed $node): array
    {
        return match (true) {
            $node instanceof HasParseTreeVisitorChildren => $node->parseTreeVisitorChildren(),
            $node instanceof Node => $node->children(),
            is_array($node) => $node,
            default => [],
        };
    }

    /** @param array<string, ?string> $aliases */
    private function invalidateAssignments(mixed $node, array &$aliases, int $depth = 0): void
    {
        if ($depth > 128 || $node instanceof RenderTag) {
            return;
        }
        if ($node instanceof AssignTag) {
            $aliases[$node->dependencyMetadata()['target']] = null;
        }
        foreach ($this->children($node) as $child) {
            $this->invalidateAssignments($child, $aliases, $depth + 1);
        }
    }
}
