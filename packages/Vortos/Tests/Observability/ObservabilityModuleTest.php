<?php

declare(strict_types=1);

namespace Vortos\Tests\Observability;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Config\ObservabilityModule;

final class ObservabilityModuleTest extends TestCase
{
    public function test_legacy_policy_modules_map_to_auth(): void
    {
        $this->assertSame(ObservabilityModule::Auth, ObservabilityModule::fromLegacy('rate_limit'));
        $this->assertSame(ObservabilityModule::Auth, ObservabilityModule::fromLegacy('quota'));
        $this->assertSame(ObservabilityModule::Auth, ObservabilityModule::fromLegacy('audit'));
    }

    public function test_legacy_query_module_maps_to_persistence(): void
    {
        $this->assertSame(ObservabilityModule::Persistence, ObservabilityModule::fromLegacy('query'));
    }
}
