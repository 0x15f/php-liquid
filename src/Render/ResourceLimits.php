<?php

namespace Keepsuit\Liquid\Render;

use Closure;
use Keepsuit\Liquid\Exceptions\ResourceLimitException;

class ResourceLimits
{
    protected int $renderScore = 0;

    protected int $cumulativeRenderScore = 0;

    protected int $assignScore = 0;

    protected int $cumulativeAssignScore = 0;

    protected int $streamedLength = 0;

    protected bool $reachedLimit = false;

    private bool $executing = false;

    public function beginExecution(): void
    {
        if ($this->executing) {
            throw new ResourceLimitException('A render context cannot execute overlapping root templates.');
        }
        $this->executing = true;
    }

    public function endExecution(): void
    {
        $this->executing = false;
    }

    public function __construct(
        public readonly ?int $renderLengthLimit = null,
        public readonly ?int $renderScoreLimit = null,
        public readonly ?int $assignScoreLimit = null,
        public readonly ?int $cumulativeRenderScoreLimit = null,
        public readonly ?int $cumulativeAssignScoreLimit = null,
        public readonly ?int $intermediateBytesLimit = null,
        public readonly ?int $collectionSizeLimit = null,
        public readonly ?int $renderTimeLimitMilliseconds = null,
    ) {
        foreach ([$renderLengthLimit, $renderScoreLimit, $assignScoreLimit, $cumulativeRenderScoreLimit,
            $cumulativeAssignScoreLimit, $intermediateBytesLimit, $collectionSizeLimit, $renderTimeLimitMilliseconds] as $limit) {
            if ($limit !== null && $limit < 0) {
                throw new \InvalidArgumentException('Resource limits cannot be negative.');
            }
        }
        $this->startedAt = $renderTimeLimitMilliseconds === null ? 0 : hrtime(true);
        $this->bounded = $intermediateBytesLimit !== null || $collectionSizeLimit !== null || $renderTimeLimitMilliseconds !== null;
    }

    private int|float $startedAt;

    public readonly bool $bounded;

    public static function forUntrustedTemplates(): self
    {
        return new self(10_000_000, 100_000, 10_000_000, 1_000_000, 20_000_000,
            intermediateBytesLimit: 10_000_000, collectionSizeLimit: 10000, renderTimeLimitMilliseconds: 1000);
    }

    /** Apply application limits without disabling or raising this preset's ceilings. */
    public function withLowerLimits(self $other): self
    {
        $lower = static fn (?int $a, ?int $b): ?int => $a === null ? $b : ($b === null ? $a : min($a, $b));

        return new self(
            $lower($this->renderLengthLimit, $other->renderLengthLimit),
            $lower($this->renderScoreLimit, $other->renderScoreLimit),
            $lower($this->assignScoreLimit, $other->assignScoreLimit),
            $lower($this->cumulativeRenderScoreLimit, $other->cumulativeRenderScoreLimit),
            $lower($this->cumulativeAssignScoreLimit, $other->cumulativeAssignScoreLimit),
            $lower($this->intermediateBytesLimit, $other->intermediateBytesLimit),
            $lower($this->collectionSizeLimit, $other->collectionSizeLimit),
            $lower($this->renderTimeLimitMilliseconds, $other->renderTimeLimitMilliseconds),
        );
    }

    /** @return list<mixed> */
    public function sliceProvider(\Keepsuit\Liquid\Contracts\ProvidesCollectionSlice $provider, int $offset, ?int $length): array
    {
        if ($this->collectionSizeLimit !== null) {
            if ($length !== null && $length < 0) {
                throw new ResourceLimitException('Bounded providers require a nonnegative limit.');
            }
            $probe = $this->collectionSizeLimit < PHP_INT_MAX ? $this->collectionSizeLimit + 1 : PHP_INT_MAX;
            $length = $length === null ? $probe : min($length, $probe);
        }
        $result = $provider->liquidSlice($offset, $length);
        $this->checkCollectionSize(count($result));

        return $result;
    }

    /** @param array<mixed> $values @return array<mixed> */
    public function sliceArray(array $values, int $offset, ?int $length): array
    {
        if ($this->collectionSizeLimit === null) {
            return $offset === 0 && $length === null ? $values : array_slice($values, $offset, $length);
        }
        $count = count($values);
        $start = $offset >= 0 ? min($offset, $count) : max(0, $count + $offset);
        $available = $count - $start;
        $selected = $length === null ? $available : ($length >= 0 ? min($length, $available) : max(0, $available + $length));
        $this->checkCollectionSize($selected);

        return $offset === 0 && $length === null ? $values : array_slice($values, $offset, $length);
    }

    public function checkTime(): void
    {
        if ($this->renderTimeLimitMilliseconds !== null
            && (hrtime(true) - $this->startedAt) / 1_000_000 > $this->renderTimeLimitMilliseconds) {
            $this->throwLimitReachedException();
        }
    }

    public function checkStringSize(int|float $bytes): void
    {
        if ($bytes < 0 || ($this->intermediateBytesLimit !== null && $bytes > $this->intermediateBytesLimit)) {
            $this->throwLimitReachedException();
        }
    }

    public function checkCollectionSize(int|float $items): void
    {
        if ($items < 0 || ($this->collectionSizeLimit !== null && $items > $this->collectionSizeLimit)) {
            $this->throwLimitReachedException();
        }
    }

    /** Check before concatenation. Passing the target by reference avoids a copy on append. */
    public function append(string &$buffer, string $chunk): void
    {
        if ($this->intermediateBytesLimit !== null
            && strlen($chunk) > $this->intermediateBytesLimit - strlen($buffer)) {
            $this->throwLimitReachedException();
        }
        $buffer .= $chunk;
    }

    /** @return array<mixed> */
    public function collect(iterable $values, bool $preserveKeys = true): array
    {
        if (is_array($values)) {
            if ($this->collectionSizeLimit !== null) {
                $this->checkCollectionSize(count($values));
            }

            return $preserveKeys ? $values : array_values($values);
        }
        $result = [];
        $count = 0;
        foreach ($values as $key => $value) {
            $this->checkCollectionSize(++$count);
            $this->incrementRenderScore();
            if ($preserveKeys) {
                $result[$key] = $value;
            } else {
                $result[] = $value;
            }
        }

        return $result;
    }

    public static function clone(ResourceLimits $limits): ResourceLimits
    {
        return new ResourceLimits(
            renderLengthLimit: $limits->renderLengthLimit,
            renderScoreLimit: $limits->renderScoreLimit,
            assignScoreLimit: $limits->assignScoreLimit,
            cumulativeRenderScoreLimit: $limits->cumulativeRenderScoreLimit,
            cumulativeAssignScoreLimit: $limits->cumulativeAssignScoreLimit,
            intermediateBytesLimit: $limits->intermediateBytesLimit,
            collectionSizeLimit: $limits->collectionSizeLimit,
            renderTimeLimitMilliseconds: $limits->renderTimeLimitMilliseconds,
        );
    }

    /**
     * @throws ResourceLimitException
     */
    public function incrementRenderScore(int $amount = 1): ResourceLimits
    {
        // Nonnegative increments and reset() keep the cumulative score at
        // least as large as the per-render score. One overflow check covers both.
        if ($amount < 0 || $amount > PHP_INT_MAX - $this->cumulativeRenderScore) {
            $this->throwLimitReachedException();
        }
        if ($this->renderTimeLimitMilliseconds !== null) {
            $this->checkTime();
        }
        $this->renderScore += $amount;
        $this->cumulativeRenderScore += $amount;

        if ($this->renderScoreLimit !== null && $this->renderScoreLimit < $this->renderScore) {
            $this->throwLimitReachedException();
        }

        if ($this->cumulativeRenderScoreLimit !== null && $this->cumulativeRenderScoreLimit < $this->cumulativeRenderScore) {
            $this->throwLimitReachedException();
        }

        return $this;
    }

    /**
     * @throws ResourceLimitException
     */
    public function incrementAssignScore(int $amount = 1): ResourceLimits
    {
        if ($amount < 0 || $amount > PHP_INT_MAX - $this->cumulativeAssignScore) {
            $this->throwLimitReachedException();
        }

        $this->assignScore += $amount;
        $this->cumulativeAssignScore += $amount;

        if ($this->assignScoreLimit !== null && $this->assignScoreLimit < $this->assignScore) {
            $this->throwLimitReachedException();
        }

        if ($this->cumulativeAssignScoreLimit !== null && $this->cumulativeAssignScoreLimit < $this->cumulativeAssignScore) {
            $this->throwLimitReachedException();
        }

        return $this;
    }

    /**
     * Called from exactly one place, Template::render() on the root template.
     * The root output already contains every node's output, including output
     * from nodes defined outside this library, so nested bodies have nothing
     * to add and custom nodes have nothing to remember.
     *
     * @throws ResourceLimitException
     */
    public function incrementWriteScore(string $output): ResourceLimits
    {
        if ($this->renderLengthLimit !== null && strlen($output) > $this->renderLengthLimit) {
            $this->throwLimitReachedException();
        }

        return $this;
    }

    /**
     * Streaming counterpart of incrementWriteScore: chunks arrive one at a time,
     * so the length limit has to be checked against a running total instead of
     * the length of a single chunk.
     *
     * Called from exactly one place, Template::stream() on the root template,
     * for the same reason incrementWriteScore is.
     *
     * The total is reset per root stream by resetStreamWriteScore(), so the
     * limit caps one stream rather than the lifetime of the context — the same
     * scope the render path applies to a single render() call.
     *
     * @throws ResourceLimitException
     */
    public function incrementStreamWriteScore(string $output): void
    {
        if ($this->renderLengthLimit === null) {
            return;
        }

        $this->streamedLength += strlen($output);

        if ($this->streamedLength > $this->renderLengthLimit) {
            $this->throwLimitReachedException();
        }
    }

    public function resetStreamWriteScore(): void
    {
        $this->streamedLength = 0;
    }

    public function reset(): ResourceLimits
    {
        $this->renderScore = 0;
        $this->streamedLength = 0;
        $this->assignScore = 0;
        $this->reachedLimit = false;

        return $this;
    }

    public function throwLimitReachedException(): void
    {
        $this->reachedLimit = true;

        throw new ResourceLimitException;
    }

    public function reached(): bool
    {
        return $this->reachedLimit;
    }

    public function getAssignScore(): int
    {
        return $this->assignScore;
    }

    public function getCumulativeAssignScore(): int
    {
        return $this->cumulativeAssignScore;
    }

    public function getRenderScore(): int
    {
        return $this->renderScore;
    }

    public function getCumulativeRenderScore(): int
    {
        return $this->cumulativeRenderScore;
    }

    /**
     * @param  Closure(): string  $closure
     * @return string
     *
     * @throws ResourceLimitException
     */
    public function withCapture(Closure $closure): mixed
    {
        $result = $closure();

        if (is_string($result)) {
            $this->incrementAssignScore(strlen($result));
        }

        return $result;
    }
}
