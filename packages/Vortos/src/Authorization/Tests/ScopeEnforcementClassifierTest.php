<?php

declare(strict_types=1);

namespace Vortos\Authorization\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Authorization\Scope\ScopeEnforcement;
use Vortos\Authorization\Scope\ScopeEnforcementClassifier;

final class ScopeEnforcementClassifierTest extends TestCase
{
    public function test_framework_defaults_apply_without_config(): void
    {
        $classifier = new ScopeEnforcementClassifier();

        $this->assertSame(ScopeEnforcement::SelfSufficient, $classifier->classify('any'));
        $this->assertSame(ScopeEnforcement::SelfSufficient, $classifier->classify('global'));
        $this->assertSame(ScopeEnforcement::Ownership, $classifier->classify('own'));
    }

    public function test_unknown_scope_falls_to_ownership_fail_closed(): void
    {
        $classifier = new ScopeEnforcementClassifier();

        $this->assertSame(ScopeEnforcement::Ownership, $classifier->classify('federation'));
        $this->assertSame(ScopeEnforcement::Ownership, $classifier->classify('whatever-app-invented'));
    }

    public function test_app_map_classifies_container_scopes(): void
    {
        $classifier = new ScopeEnforcementClassifier([
            'org' => ScopeEnforcement::Containment,
            'team' => ScopeEnforcement::Containment,
        ]);

        $this->assertSame(ScopeEnforcement::Containment, $classifier->classify('org'));
        $this->assertSame(ScopeEnforcement::Containment, $classifier->classify('team'));
    }

    public function test_app_map_accepts_string_values_from_config(): void
    {
        $classifier = new ScopeEnforcementClassifier(['org' => 'containment']);

        $this->assertSame(ScopeEnforcement::Containment, $classifier->classify('org'));
    }

    public function test_app_map_overrides_framework_default(): void
    {
        $classifier = new ScopeEnforcementClassifier(['own' => ScopeEnforcement::Containment]);

        $this->assertSame(ScopeEnforcement::Containment, $classifier->classify('own'));
    }

    public function test_invalid_string_value_throws(): void
    {
        $this->expectException(\ValueError::class);

        new ScopeEnforcementClassifier(['org' => 'nonsense']);
    }
}
