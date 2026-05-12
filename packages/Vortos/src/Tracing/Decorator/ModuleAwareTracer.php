<?php
declare(strict_types=1);

namespace Vortos\Tracing\Decorator;

use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Tracing\Config\TracingModule;
use Vortos\Tracing\Contract\SpanInterface;
use Vortos\Tracing\Contract\TracingInterface;
use Vortos\Tracing\NoOpSpan;

final class ModuleAwareTracer implements TracingInterface
{
    /** @param list<TracingModule|ObservabilityModule|string> $disabledModules */
    public function __construct(
        private readonly TracingInterface $inner,
        array $disabledModules = []
    ) {
        $this->disabledModules = [];
        foreach ($disabledModules as $module) {
            $value = $module instanceof TracingModule
                ? $module->observabilityModule()->value
                : ($module instanceof ObservabilityModule ? $module->value : ObservabilityModule::fromLegacy((string) $module)->value);
            $this->disabledModules[$value] = true;
        }
    }

    /** @var array<string, true> */
    private array $disabledModules;

    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        $module = $attributes['vortos.module'] ?? null;

        if ($module instanceof TracingModule) {
            $module = $module->observabilityModule();
        } elseif (is_string($module)) {
            $module = ObservabilityModule::fromLegacy($module);
        }

        if ($module instanceof ObservabilityModule && $this->isDisabled($module)) {
            return new NoOpSpan();
        }

        return $this->inner->startSpan($name, $attributes);
    }

    public function injectHeaders(array &$headers): void
    {
        $this->inner->injectHeaders($headers);
    }

    public function extractContext(array $headers): void
    {
        $this->inner->extractContext($headers);
    }

    public function setBaggageItem(string $key, string $value): void
    {
        $this->inner->setBaggageItem($key, $value);
    }

    public function baggageItem(string $key): ?string
    {
        return $this->inner->baggageItem($key);
    }

    public function baggage(): array
    {
        return $this->inner->baggage();
    }

    public function currentCorrelationId(): ?string
    {
        return $this->inner->currentCorrelationId();
    }

    private function isDisabled(ObservabilityModule $module): bool
    {
        return isset($this->disabledModules[$module->value]);
    }
}
