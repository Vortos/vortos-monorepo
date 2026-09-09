<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Per-request record of which flags were actually evaluated, and to what.
 *
 * This is what lets an analytics backend stamp flag attribution onto *every* event a
 * request produces, not just the exposure event — the difference between "I can see
 * how often the flag was called" and "I can break any metric in the product down by
 * flag value", which is the whole point of wiring exposures to an analytics backend.
 *
 * ## Worker-mode discipline
 *
 * This service is deliberately mutable, which under a long-lived FrankenPHP worker is
 * exactly the trap that leaks one request's state into the next. It implements
 * {@see ResetInterface}, so `ResettableServicesPass` collects it automatically and
 * `Runner::cleanUp()` clears it after `kernel.terminate` — i.e. after the analytics
 * flush has already read it. Nothing here may be memoised anywhere else.
 *
 * Bounded on purpose: a request that somehow evaluates thousands of distinct flags
 * must not grow this map without limit.
 */
final class ActiveFlagCollector implements ActiveFlagSourceInterface, ResetInterface
{
    public const DEFAULT_MAX_FLAGS = 200;

    /** @var array<string,string> flag name => evaluated variant */
    private array $active = [];

    private bool $truncated = false;

    public function __construct(private readonly int $maxFlags = self::DEFAULT_MAX_FLAGS) {}

    /** Record an evaluation. Last write wins — a re-evaluation is the more current answer. */
    public function record(string $flag, ?string $variant): void
    {
        if ($flag === '') {
            return;
        }

        if (!isset($this->active[$flag]) && count($this->active) >= $this->maxFlags) {
            $this->truncated = true;

            return;
        }

        $this->active[$flag] = $variant ?? '';
    }

    /** @return array<string,string> flag name => evaluated variant, in evaluation order */
    public function all(): array
    {
        return $this->active;
    }

    public function isEmpty(): bool
    {
        return $this->active === [];
    }

    /** True when the per-request cap dropped at least one distinct flag. */
    public function wasTruncated(): bool
    {
        return $this->truncated;
    }

    public function reset(): void
    {
        $this->active = [];
        $this->truncated = false;
    }
}
