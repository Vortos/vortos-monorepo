<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\PHPStan;

use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPUnit\Framework\TestCase;
use Vortos\OpsKit\PHPStan\AgnosticismRule;

/**
 * The PHPStan rule shares ProviderNameMatcher with the (fully-tested) scanner, so
 * this covers the rule's own glue: node type and the pure leak() decision (namespace
 * exemption + provider matching). Full file-level analysis is the scanner's job (and
 * the future phpstan.neon wiring).
 */
final class AgnosticismRuleTest extends TestCase
{
    public function test_is_a_rule_dispatched_on_the_base_node(): void
    {
        $rule = new AgnosticismRule();
        $this->assertInstanceOf(Rule::class, $rule);
        $this->assertSame(Node::class, $rule->getNodeType());
    }

    public function test_flags_provider_name_outside_driver_namespace(): void
    {
        $this->assertSame('caddy', (new AgnosticismRule())->leak('App\\Edge\\CaddyClient', 'App\\Edge'));
    }

    public function test_exempts_driver_namespace(): void
    {
        $this->assertNull((new AgnosticismRule())->leak('CaddyClient', 'App\\Edge\\Driver\\Caddy'));
    }

    public function test_ignores_clean_names(): void
    {
        $this->assertNull((new AgnosticismRule())->leak('EdgeClient', 'App\\Edge'));
    }

    public function test_handles_global_namespace(): void
    {
        $this->assertSame('github', (new AgnosticismRule())->leak('GithubClient', null));
    }

    public function test_custom_provider_list_is_honored(): void
    {
        $rule = new AgnosticismRule(['acmecloud']);
        $this->assertSame('acmecloud', $rule->leak('AcmeCloudThing', 'App\\Infra'));
        $this->assertNull($rule->leak('CaddyThing', 'App\\Infra'), 'only the custom list applies');
    }

    public function test_unscoped_rule_applies_to_every_file(): void
    {
        $rule = new AgnosticismRule();
        $this->assertTrue($rule->appliesTo('/any/path/Whatever.php'));
    }

    public function test_scoped_rule_only_applies_within_included_paths(): void
    {
        $rule = new AgnosticismRule(null, ['Driver'], ['/OpsKit/', '/Deploy/']);
        $this->assertTrue($rule->appliesTo('/repo/packages/Vortos/src/OpsKit/Foo.php'));
        $this->assertTrue($rule->appliesTo('/repo/packages/Vortos/src/Deploy/Bar.php'));
        $this->assertFalse($rule->appliesTo('/repo/packages/Vortos/src/Paddle/Baz.php'));
    }
}
