<?php

declare(strict_types=1);

namespace Vortos\Logger\DependencyInjection;

use Monolog\Level;

/**
 * Fluent builder for a channel's routing: which sinks receive its records,
 * at what minimum level, or whether the channel is disabled entirely.
 */
final class ChannelBuilder
{
    /** @var list<string>|null Explicit routing set via routeTo(); null means "use the default sink". */
    private ?array $routedSinkIds = null;

    /** @var list<string> Additional sinks appended via alsoRouteTo(). */
    private array $extraSinkIds = [];

    private ?Level $level = null;

    private bool $disabled = false;

    public function __construct(private readonly string $name)
    {}

    /** Fan this channel's records out to one or more sinks. Replaces any previous/default routing. */
    public function routeTo(string ...$sinkIds): static
    {
        $this->routedSinkIds = $sinkIds;
        return $this;
    }

    /** Add additional sink(s) alongside the default (or routeTo()) routing. */
    public function alsoRouteTo(string ...$sinkIds): static
    {
        $this->extraSinkIds = array_values(array_unique([...$this->extraSinkIds, ...$sinkIds]));
        return $this;
    }

    public function level(Level $level): static
    {
        $this->level = $level;
        return $this;
    }

    /** Silence this channel entirely (records are discarded). */
    public function disable(): static
    {
        $this->disabled = true;
        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Resolve this channel's sink routing against its default sink id
     * (normally the channel's own name).
     *
     * @return list<string>
     */
    public function sinkIds(string $defaultSinkId): array
    {
        $base = $this->routedSinkIds ?? [$defaultSinkId];

        return array_values(array_unique([...$base, ...$this->extraSinkIds]));
    }

    /** Whether routeTo()/alsoRouteTo() configured any routing at all. */
    public function hasRouting(): bool
    {
        return $this->routedSinkIds !== null || $this->extraSinkIds !== [];
    }

    public function getLevel(): ?Level
    {
        return $this->level;
    }

    public function isDisabled(): bool
    {
        return $this->disabled;
    }
}
