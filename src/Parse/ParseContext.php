<?php

namespace Keepsuit\Liquid\Parse;

use Closure;
use Keepsuit\Liquid\Environment;
use Keepsuit\Liquid\Exceptions\InternalException;
use Keepsuit\Liquid\Exceptions\LiquidException;
use Keepsuit\Liquid\Exceptions\StackLevelException;
use Keepsuit\Liquid\Exceptions\SyntaxException;
use Keepsuit\Liquid\ParsedTemplate;
use Keepsuit\Liquid\Support\OutputsBag;
use Keepsuit\Liquid\Template;
use Keepsuit\Liquid\TemplateSharedState;

class ParseContext
{
    const MAX_DEPTH = 100;

    public int $lineNumber;

    public int $depth = 0;

    protected bool $partial = false;

    /**
     * @var string[]
     */
    protected array $partials = [];

    protected OutputsBag $outputs;

    protected Lexer $lexer;

    protected Parser $parser;

    public readonly Environment $environment;

    private ParseBudget $budget;

    public function __construct(
        ?Environment $environment = null,
        ?ParseBudget $budget = null,
    ) {
        $this->environment = $environment ?? Environment::default();
        $this->environment->sealTemplateCapabilities();

        $this->budget = $budget ?? new ParseBudget;
        $this->lineNumber = 1;
        $this->outputs = new OutputsBag;
        $this->lexer = new Lexer($this);
        $this->parser = new Parser($this);
    }

    public function chargeToken(): void
    {
        $limit = $this->environment->templatePolicy?->maxTokens;
        if ($limit !== null && ++$this->budget->tokens > $limit) {
            throw new \Keepsuit\Liquid\Exceptions\ResourceLimitException('Template token budget exceeded.');
        }
    }

    public function isPartial(): bool
    {
        return $this->partial;
    }

    /**
     * @throws SyntaxException
     */
    public function tokenize(string $markup): TokenStream
    {
        $policy = $this->environment->templatePolicy;
        if ($policy !== null) {
            $this->budget->bytes += strlen($markup);
            if (strlen($markup) > $policy->maxSourceBytes || $this->budget->bytes > $policy->maxParseBytes) {
                throw new \Keepsuit\Liquid\Exceptions\ResourceLimitException('Template source budget exceeded.');
            }
        }

        return $this->lexer->tokenize($markup);
    }

    /**
     * @throws LiquidException
     */
    public function parseTemplate(string $templateName, bool $force = false): Template
    {
        $cacheKey = $this->environment->boundedCompilation ? $this->environment->templateCacheKey($templateName) : $templateName;
        if (! $force) {
            $cachedTemplate = $this->environment->templatesCache->get($cacheKey);

            if ($cachedTemplate !== null) {
                return $cachedTemplate;
            }
        }

        $source = $this->environment->fileSystem->readTemplateFile($templateName);

        $template = $this->parse($source, name: $templateName);

        $this->environment->templatesCache->set($cacheKey, $template);

        return $this->environment->templatesCache->get($cacheKey) ?? $template;
    }

    public function parse(TokenStream|string $source, ?string $name = null): ParsedTemplate
    {
        if ($this->environment->templatePolicy !== null && $name !== null && strlen($name) > 256) {
            throw new \Keepsuit\Liquid\Exceptions\ResourceLimitException('Template name budget exceeded.');
        }
        $this->partials = [];
        $this->outputs = new OutputsBag;

        try {
            $tokenStream = $source instanceof TokenStream ? $source : $this->tokenize($source);

            $root = $this->parser->parse($tokenStream, $name);

            return new ParsedTemplate(
                root: $root,
                state: new TemplateSharedState(
                    partials: $this->partials,
                    outputs: (clone $this->outputs),
                    boundedCompilation: $this->environment->boundedCompilation,
                ),
            );
        } catch (LiquidException $e) {
            $e->templateName = $e->templateName ?? $name;
            $e->lineNumber = $e->lineNumber ?? $this->lineNumber;
            throw $e;
        } catch (\Throwable $e) {
            $exception = new InternalException($e);
            $exception->templateName = $exception->templateName ?? $name;
            $exception->lineNumber = $exception->lineNumber ?? $this->lineNumber;
            throw $exception;
        }
    }

    public function loadPartial(string $templateName): Template
    {
        try {
            // Check if template is already available in the cache
            $cacheKey = $this->environment->boundedCompilation ? $this->environment->templateCacheKey($templateName) : $templateName;
            $template = $this->environment->templatesCache->get($cacheKey);

            if ($template === null) {
                $partialParseContext = new ParseContext(environment: $this->environment, budget: $this->budget);
                $partialParseContext->partial = true;
                $partialParseContext->depth = $this->depth;

                $template = $partialParseContext->parseTemplate($templateName, force: true);

                $this->outputs->merge($partialParseContext->outputs);
            }

            if (! in_array($templateName, $this->partials, true)) {
                $this->partials[] = $templateName;
            }

            return $template;
        } catch (LiquidException $exception) {
            $exception->templateName = $templateName;

            throw $exception;
        }
    }

    /**
     * @template TReturnValue
     *
     * @param  Closure(ParseContext): TReturnValue  $callback
     * @return TReturnValue
     *
     * @throws StackLevelException
     */
    public function nested(Closure $callback)
    {
        if ($this->depth >= self::MAX_DEPTH) {
            throw StackLevelException::nestingTooDeep();
        }

        $this->depth += 1;

        try {
            return $callback($this);
        } finally {
            $this->depth -= 1;
        }
    }

    public function getOutputs(): OutputsBag
    {
        return $this->outputs;
    }
}
