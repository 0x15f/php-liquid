<?php

namespace Keepsuit\Liquid\Nodes;

use Keepsuit\Liquid\Compiler\CompilerContext;
use Keepsuit\Liquid\Contracts\CanBeCompiled;
use Keepsuit\Liquid\Contracts\CanBeStreamed;
use Keepsuit\Liquid\Contracts\Disableable;
use Keepsuit\Liquid\Exceptions\LiquidException;
use Keepsuit\Liquid\Exceptions\UndefinedDropMethodException;
use Keepsuit\Liquid\Exceptions\UndefinedFilterException;
use Keepsuit\Liquid\Exceptions\UndefinedVariableException;
use Keepsuit\Liquid\Render\RenderContext;
use Keepsuit\Liquid\Tag;
use Keepsuit\Liquid\Tags\BreakTag;
use Keepsuit\Liquid\Tags\ContinueTag;

class BodyNode extends Node implements CanBeCompiled, CanBeStreamed
{
    private const MAX_BUFFERED_BYTES = 4096;

    public function __construct(
        /** @var array<Node> */
        protected array $children = [],
    ) {}

    /**
     * @return array<Node>
     */
    public function children(): array
    {
        return $this->children;
    }

    public function pushChild(Node $node): BodyNode
    {
        $this->children[] = $node;

        return $this;
    }

    /**
     * @param  array<Node>  $children
     */
    public function setChildren(array $children): BodyNode
    {
        $this->children = $children;

        return $this;
    }

    /**
     * Interrupts break out of a single do/while instead of a hasInterrupt() check around every child.
     */
    public function compile(CompilerContext $context): void
    {
        if ($this->compileConstantBody($context)) {
            return;
        }

        $lastIndex = count($this->children) - 1;
        $interruptible = false;
        foreach ($this->children as $index => $child) {
            if ($index !== $lastIndex && $context->canInterrupt($child)) {
                $interruptible = true;

                break;
            }
        }

        if ($interruptible) {
            $context->write('do {')->indent();
        }

        $literal = '';

        foreach ($this->children as $index => $child) {
            if ($child instanceof Text) {
                $literal .= $child->value;

                continue;
            }

            if ($literal !== '') {
                $context->writeText($literal);
                $literal = '';
            }

            $context->subcompile($child);

            if ($index !== $lastIndex && $context->canInterrupt($child)) {
                if ($child::class === BreakTag::class || $child::class === ContinueTag::class) {
                    $context->write('break;');

                    continue;
                }

                $context->write('if ($context->hasInterrupt()) {')
                    ->indent()
                    ->write('break;')
                    ->outdent()
                    ->write('}');
            }
        }

        if ($literal !== '') {
            $context->writeText($literal);
        }

        if ($interruptible) {
            $context->outdent()->write('} while (false);');
        }
    }

    private function compileConstantBody(CompilerContext $context): bool
    {
        $output = '';
        $interruptedOutput = null;
        foreach ($this->children as $child) {
            if ($child::class === Text::class || $child::class === Raw::class) {
                $output .= $child->value;
                if ($child::class === Raw::class) {
                    $interruptedOutput ??= $output;
                }
            } elseif ($child::class === Variable::class && ($literal = $child->constantOutput()) !== null) {
                $output .= $literal;
                $interruptedOutput ??= $output;
            } else {
                return false;
            }
        }

        // Folding streams is safe only when none of these nodes can yield:
        // a caller could otherwise queue an interrupt between their chunks.
        if (! $context->isRendering() && ! $context->canBufferConstantOutput(strlen($output))) {
            return false;
        }

        // Fixed rendering cannot create an interrupt, but one may already be
        // queued when the body starts. Text before the first variable still renders.
        if ($interruptedOutput !== null && $interruptedOutput !== $output) {
            $context->write('if ($context->hasInterrupt()) {')->indent();
            $context->writeText($interruptedOutput);
            $context->outdent()->write('} else {')->indent();
            $context->writeText($output);
            $context->outdent()->write('}');
        } else {
            $context->writeText($output);
        }

        return true;
    }

    /**
     * @throws LiquidException
     */
    public function render(RenderContext $context): string
    {
        $context->resourceLimits->incrementRenderScore(max(1, count($this->children)));

        $output = '';
        $byteLimit = $context->resourceLimits->intermediateBytesLimit;

        if ($byteLimit === null) {
            return $this->renderUnbounded($context);
        }

        foreach ($this->children as $node) {
            // Text is the majority of children and cannot fail or interrupt.
            if ($node instanceof Text) {
                if (strlen($node->value) > $byteLimit - strlen($output)) {
                    $context->resourceLimits->throwLimitReachedException();
                }
                $output .= $node->value;

                continue;
            }

            try {
                if ($node instanceof Disableable && $node instanceof Tag) {
                    $node->ensureTagIsEnabled($context);
                }

                $chunk = $node->render($context);
                if (strlen($chunk) > $byteLimit - strlen($output)) {
                    $context->resourceLimits->throwLimitReachedException();
                }
                $output .= $chunk;
            } catch (UndefinedVariableException|UndefinedDropMethodException|UndefinedFilterException $exception) {
                $context->handleError($exception, $node->lineNumber);
            } catch (\Throwable $exception) {
                $context->resourceLimits->append($output, $context->handleError($exception, $node->lineNumber));
            }

            if ($context->hasInterrupt()) {
                break;
            }
        }

        return $output;
    }

    private function renderUnbounded(RenderContext $context): string
    {
        $output = '';
        foreach ($this->children as $node) {
            if ($node instanceof Text) {
                $output .= $node->value;

                continue;
            }

            try {
                if ($node instanceof Disableable && $node instanceof Tag) {
                    $node->ensureTagIsEnabled($context);
                }

                $output .= $node->render($context);
            } catch (UndefinedVariableException|UndefinedDropMethodException|UndefinedFilterException $exception) {
                $context->handleError($exception, $node->lineNumber);
            } catch (\Throwable $exception) {
                $output .= $context->handleError($exception, $node->lineNumber);
            }

            if ($context->hasInterrupt()) {
                break;
            }
        }

        return $output;
    }

    /**
     * @return \Generator<string>
     *
     * @throws LiquidException
     */
    public function stream(RenderContext $context): \Generator
    {
        $context->resourceLimits->incrementRenderScore(max(1, count($this->children)));

        $buffer = '';
        $byteLimit = $context->resourceLimits->intermediateBytesLimit;

        foreach ($this->children as $node) {
            // Text is the majority of children and cannot fail or interrupt.
            if ($node instanceof Text) {
                if ($byteLimit !== null && strlen($node->value) > $byteLimit - strlen($buffer)) {
                    $context->resourceLimits->throwLimitReachedException();
                }
                $buffer .= $node->value;

                continue;
            }

            if (strlen($buffer) >= self::MAX_BUFFERED_BYTES) {
                yield $buffer;
                $buffer = '';
            }

            try {
                if ($node instanceof Disableable && $node instanceof Tag) {
                    $node->ensureTagIsEnabled($context);
                }

                if ($node instanceof CanBeStreamed && ! $node instanceof CanBeCompiled) {
                    if ($buffer !== '') {
                        yield $buffer;
                        $buffer = '';
                    }

                    yield from $node->stream($context);
                } elseif ($node instanceof CanBeStreamed) {
                    // Native scalar variables need no generator. Subclasses
                    // retain their stream overrides and filtered values render
                    // completely before their output reaches the buffer.
                    $value = $node::class === Variable::class
                        ? ($node->filters !== [] ? $node->render($context) : Variable::streamValue($context, $node->evaluate($context)))
                        : $node->stream($context);

                    if (is_string($value)) {
                        if ($byteLimit !== null && strlen($value) > $byteLimit - strlen($buffer)) {
                            $context->resourceLimits->throwLimitReachedException();
                        }
                        $buffer .= $value;

                        if (strlen($buffer) >= self::MAX_BUFFERED_BYTES) {
                            yield $buffer;
                            $buffer = '';
                        }
                    } else {
                        foreach ($value as $output) {
                            $context->resourceLimits->append($buffer, $output);

                            if (strlen($buffer) >= self::MAX_BUFFERED_BYTES) {
                                yield $buffer;
                                $buffer = '';
                            }
                        }
                    }
                } else {
                    $chunk = $node->render($context);
                    if ($byteLimit !== null && strlen($chunk) > $byteLimit - strlen($buffer)) {
                        $context->resourceLimits->throwLimitReachedException();
                    }
                    $buffer .= $chunk;
                }
            } catch (UndefinedVariableException|UndefinedDropMethodException|UndefinedFilterException $exception) {
                if ($buffer !== '') {
                    yield $buffer;
                    $buffer = '';
                }

                $context->handleError($exception, $node->lineNumber);
            } catch (\Keepsuit\Liquid\Exceptions\ResourceLimitException|\Keepsuit\Liquid\Exceptions\TemplateSecurityException $exception) {
                // A root budget failure can arrive while this body buffers a
                // suspended child. Close it before unwinding, even on PHP 8.2.
                if (isset($value) && $value instanceof \Generator && $value->valid()) {
                    try {
                        $value->throw($exception);
                    } catch (\Throwable) {
                        // Preserve the original failure, including on cleanup.
                    }
                }
                throw $exception;
            } catch (\Throwable $exception) {
                if ($buffer !== '') {
                    yield $buffer;
                    $buffer = '';
                }

                $context->resourceLimits->append($buffer, $context->handleError($exception, $node->lineNumber));
            }

            if ($context->hasInterrupt()) {
                break;
            }
        }

        if ($buffer !== '') {
            yield $buffer;
        }
    }

    public function blank(): bool
    {
        foreach ($this->children as $node) {
            if ($node->blank()) {
                continue;
            }

            return false;
        }

        return true;
    }

    public function removeBlankStrings(): void
    {
        if (! $this->blank()) {
            throw new \RuntimeException('Cannot remove blank strings from non-blank section');
        }

        $this->children = array_filter($this->children, fn (Node $node) => ! ($node instanceof Text));
    }
}
