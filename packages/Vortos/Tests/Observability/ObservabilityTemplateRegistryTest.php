<?php

declare(strict_types=1);

namespace Vortos\Tests\Observability;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Service\ObservabilityTemplateRegistry;

final class ObservabilityTemplateRegistryTest extends TestCase
{
    private ObservabilityTemplateRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ObservabilityTemplateRegistry(__DIR__ . '/../../src/Observability/Resources/observability');
    }

    public function test_lists_supported_stacks(): void
    {
        $this->assertSame(
            ['prometheus', 'grafana', 'alertmanager', 'datadog', 'newrelic', 'grafana-oss'],
            $this->registry->names(),
        );
    }

    public function test_grafana_oss_combines_open_source_stack_files(): void
    {
        $stack = $this->registry->get('grafana-oss');

        $this->assertNotNull($stack);
        $this->assertContains('prometheus/vortos-alert-rules.yml', $stack->files);
        $this->assertContains('grafana/vortos-overview-dashboard.json', $stack->files);
        $this->assertContains('alertmanager/vortos-alertmanager.yml', $stack->files);
    }

    public function test_unknown_stack_returns_null(): void
    {
        $this->assertNull($this->registry->get('unknown'));
    }
}

