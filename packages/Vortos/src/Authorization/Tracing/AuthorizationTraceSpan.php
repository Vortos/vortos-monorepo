<?php

declare(strict_types=1);

namespace Vortos\Authorization\Tracing;

final class AuthorizationTraceSpan
{
    public function __construct(private readonly ?object $span = null)
    {
    }

    public static function noop(): self
    {
        return new self();
    }

    public function addAttribute(string $key, mixed $value): void
    {
        if ($this->span !== null && method_exists($this->span, 'addAttribute')) {
            $this->span->addAttribute($key, $value);
        }
    }

    public function recordException(\Throwable $e): void
    {
        if ($this->span !== null && method_exists($this->span, 'recordException')) {
            $this->span->recordException($e);
        }
    }

    public function setStatus(string $status): void
    {
        if ($this->span !== null && method_exists($this->span, 'setStatus')) {
            $this->span->setStatus($status);
        }
    }

    public function end(): void
    {
        if ($this->span !== null && method_exists($this->span, 'end')) {
            $this->span->end();
        }
    }
}
