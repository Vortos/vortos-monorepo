<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Middleware;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Middleware\TracingMiddleware;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;
use Vortos\Tracing\Contract\SpanInterface;
use Vortos\Tracing\Contract\TracingInterface;
use Vortos\Tracing\NoOpTracer;

final class TracingMiddlewareTest extends TestCase
{
    private function makeEmail(): Email
    {
        return Email::new()->to('u@example.com')->subject('S')->htmlBody('H');
    }

    private function makeSentEmail(): SentEmail
    {
        return new SentEmail('msg-1', new \DateTimeImmutable(), 1, 'ses', 'us-east-1');
    }

    public function test_passes_through_to_next(): void
    {
        $mw   = new TracingMiddleware(new NoOpTracer());
        $sent = $this->makeSentEmail();

        $result = $mw->process($this->makeEmail(), fn($e) => $sent);

        $this->assertSame($sent, $result);
    }

    public function test_starts_and_ends_span(): void
    {
        $spanEnded = false;
        $span = new class($spanEnded) implements SpanInterface {
            public function __construct(public bool &$ended) {}
            public function end(): void                   { $this->ended = true; }
            public function addAttribute(string $key, mixed $value): void {}
            public function recordException(\Throwable $e): void {}
            public function setStatus(string $status): void {}
        };

        $tracer = new class($span) implements TracingInterface {
            public function __construct(private SpanInterface $span) {}
            public function startSpan(string $name, array $attributes = []): SpanInterface { return $this->span; }
            public function injectHeaders(array &$headers): void {}
            public function extractContext(array $headers): void {}
            public function setBaggageItem(string $key, string $value): void {}
            public function baggageItem(string $key): ?string { return null; }
            public function baggage(): array { return []; }
            public function currentCorrelationId(): ?string { return null; }
        };

        $mw = new TracingMiddleware($tracer);
        $mw->process($this->makeEmail(), fn($e) => $this->makeSentEmail());

        $this->assertTrue($spanEnded);
    }

    public function test_span_ended_even_on_exception(): void
    {
        $spanEnded = false;
        $span = new class($spanEnded) implements SpanInterface {
            public function __construct(public bool &$ended) {}
            public function end(): void                   { $this->ended = true; }
            public function addAttribute(string $key, mixed $value): void {}
            public function recordException(\Throwable $e): void {}
            public function setStatus(string $status): void {}
        };

        $tracer = new class($span) implements TracingInterface {
            public function __construct(private SpanInterface $span) {}
            public function startSpan(string $name, array $attributes = []): SpanInterface { return $this->span; }
            public function injectHeaders(array &$headers): void {}
            public function extractContext(array $headers): void {}
            public function setBaggageItem(string $key, string $value): void {}
            public function baggageItem(string $key): ?string { return null; }
            public function baggage(): array { return []; }
            public function currentCorrelationId(): ?string { return null; }
        };

        $mw = new TracingMiddleware($tracer);

        try {
            $mw->process($this->makeEmail(), fn($e) => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {}

        $this->assertTrue($spanEnded);
    }

    public function test_span_name_is_ses_send(): void
    {
        $capturedName = null;
        $tracer = new class($capturedName) implements TracingInterface {
            public function __construct(public ?string &$capturedName) {}
            public function startSpan(string $name, array $attributes = []): SpanInterface
            {
                $this->capturedName = $name;
                return new \Vortos\Tracing\NoOpSpan();
            }
            public function injectHeaders(array &$headers): void {}
            public function extractContext(array $headers): void {}
            public function setBaggageItem(string $key, string $value): void {}
            public function baggageItem(string $key): ?string { return null; }
            public function baggage(): array { return []; }
            public function currentCorrelationId(): ?string { return null; }
        };

        $mw = new TracingMiddleware($tracer);
        $mw->process($this->makeEmail(), fn($e) => $this->makeSentEmail());

        $this->assertSame('ses.send', $capturedName);
    }

    public function test_exception_propagates(): void
    {
        $mw = new TracingMiddleware(new NoOpTracer());

        $this->expectException(\RuntimeException::class);
        $mw->process($this->makeEmail(), fn($e) => throw new \RuntimeException('fail'));
    }

    public function test_works_with_noop_tracer(): void
    {
        $mw   = new TracingMiddleware(new NoOpTracer());
        $sent = $this->makeSentEmail();

        $result = $mw->process($this->makeEmail(), fn($e) => $sent);

        $this->assertSame($sent, $result);
    }
}
