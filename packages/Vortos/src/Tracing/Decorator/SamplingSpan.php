<?php

declare(strict_types=1);

namespace Vortos\Tracing\Decorator;

use Vortos\Tracing\Contract\SpanInterface;

final class SamplingSpan implements SpanInterface
{
    private bool $ended = false;

    public function __construct(
        private readonly SpanInterface $inner,
        private readonly \Closure $onEnd,
    ) {}

    public function end(): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;

        try {
            $this->inner->end();
        } finally {
            ($this->onEnd)();
        }
    }

    public function addAttribute(string $key, mixed $value): void
    {
        $this->inner->addAttribute($key, $value);
    }

    public function recordException(\Throwable $e): void
    {
        $this->inner->recordException($e);
    }

    public function setStatus(string $status): void
    {
        $this->inner->setStatus($status);
    }
}
