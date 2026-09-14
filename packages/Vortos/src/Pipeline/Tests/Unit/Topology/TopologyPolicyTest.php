<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Topology;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Topology\ComposeTopology;
use Vortos\Pipeline\Topology\ComposeTopologyViolationKind;
use Vortos\Pipeline\Topology\EnvFileScopeRule;
use Vortos\Pipeline\Topology\HostPath;
use Vortos\Pipeline\Topology\TopologyPolicy;
use Vortos\Pipeline\Topology\TopologyRuleInterface;
use Vortos\Pipeline\Topology\TopologyViolation;
use Vortos\Pipeline\Topology\UnreadableTopologyException;

final class TopologyPolicyTest extends TestCase
{
    public function test_an_unparseable_or_serviceless_topology_proves_nothing(): void
    {
        $policy = TopologyPolicy::forDefinition(new PipelineDefinition());

        foreach (["services:\n  a: [unclosed\n", "volumes: {}\n", "- a list\n"] as $yaml) {
            $violations = $policy->evaluate($yaml);
            self::assertCount(1, $violations, $yaml);
            self::assertSame(ComposeTopologyViolationKind::Unreadable, $violations[0]->kind);
            self::assertSame('topology', $violations[0]->rule);
        }
    }

    public function test_every_rule_sees_the_same_parse_and_violations_are_concatenated_in_rule_order(): void
    {
        $seen = [];
        $rule = static function (string $name) use (&$seen): TopologyRuleInterface {
            return new class ($name, $seen) implements TopologyRuleInterface {
                /** @param list<ComposeTopology> $seen */
                public function __construct(private string $n, private array &$seen) {}

                public function name(): string
                {
                    return $this->n;
                }

                public function evaluate(ComposeTopology $topology): array
                {
                    $this->seen[] = $topology;

                    return [new TopologyViolation($this->n, ComposeTopologyViolationKind::Unreadable, 'svc', 'x', 'd')];
                }
            };
        };

        $policy = new TopologyPolicy('/opt/vortos', [$rule('first'), $rule('second')]);
        $violations = $policy->evaluate("services:\n  svc: {}\n");

        self::assertSame(['first', 'second'], array_map(static fn (TopologyViolation $v): string => $v->rule, $violations));
        self::assertSame(['first', 'second'], $policy->ruleNames());
        self::assertCount(2, $seen);
        self::assertSame($seen[0], $seen[1]);
    }

    public function test_a_policy_without_rules_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TopologyPolicy('/opt/vortos', []);
    }

    public function test_the_definition_policy_carries_the_env_file_scope_rule(): void
    {
        self::assertContains(EnvFileScopeRule::NAME, TopologyPolicy::forDefinition(new PipelineDefinition())->ruleNames());
    }

    public function test_topology_parses_services_and_resolves_paths_like_compose(): void
    {
        $topology = ComposeTopology::parse("services:\n  db:\n    image: postgres\n  bare:\n", '/opt/vortos');

        self::assertSame(['db', 'bare'], array_keys($topology->services));
        self::assertSame([], $topology->services['bare']);
        self::assertSame('/opt/vortos/docker/postgres/init', $topology->resolveHostPath('./docker/postgres/init'));
        self::assertSame('/etc/x', $topology->resolveHostPath('/etc//x/'));
        self::assertNull($topology->resolveHostPath('../escape'));
        self::assertNull($topology->resolveHostPath('${HOME}/x'));
        self::assertNull(HostPath::resolve('', '/opt'));
    }

    public function test_topology_refuses_a_relative_host_dir(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ComposeTopology::parse("services: {}\n", 'opt/vortos');
    }

    public function test_unreadable_topology_is_an_exception_at_the_parse_boundary(): void
    {
        $this->expectException(UnreadableTopologyException::class);

        ComposeTopology::parse("services: 3\n", '/opt/vortos');
    }
}
