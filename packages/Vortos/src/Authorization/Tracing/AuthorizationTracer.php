<?php

declare(strict_types=1);

namespace Vortos\Authorization\Tracing;

final class AuthorizationTracer
{
    public function __construct(
        private readonly ?object $tracer = null,
        private readonly bool $traceDecisions = false,
        private readonly bool $traceResolver = false,
        private readonly bool $traceAdminMutations = false,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function decision(string $name, array $attributes = []): AuthorizationTraceSpan
    {
        return $this->start($this->traceDecisions, $name, $attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function resolver(string $name, array $attributes = []): AuthorizationTraceSpan
    {
        return $this->start($this->traceResolver, $name, $attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function adminMutation(string $name, array $attributes = []): AuthorizationTraceSpan
    {
        return $this->start($this->traceAdminMutations, $name, $attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function start(bool $enabled, string $name, array $attributes): AuthorizationTraceSpan
    {
        if (!$enabled || $this->tracer === null || !method_exists($this->tracer, 'startSpan')) {
            return AuthorizationTraceSpan::noop();
        }

        $attributes['vortos.module'] = $this->authorizationModuleAttribute();

        return new AuthorizationTraceSpan($this->tracer->startSpan($name, $attributes));
    }

    private function authorizationModuleAttribute(): mixed
    {
        if (enum_exists('Vortos\\Tracing\\Config\\TracingModule')) {
            return constant('Vortos\\Tracing\\Config\\TracingModule::Authorization');
        }

        return 'authorization';
    }
}
