<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Builder\PipelineBuilder;
use Vortos\Pipeline\Builder\StageGate;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Model\CommandStep;

/**
 * RC-4: a topology synced to the host is checked for env-file audiences in the gating tests job, so a
 * violation stops the release before any image is built.
 */
final class TopologyCheckStepTest extends TestCase
{
    /** @return list<string> */
    private function testsJobCommands(PipelineDefinition $definition): array
    {
        $tests = (new PipelineBuilder(new StageGate()))->build($definition)->stageById('tests');
        self::assertNotNull($tests);

        return array_values(array_map(
            static fn (CommandStep $s): string => $s->run,
            array_filter($tests->steps, static fn ($s): bool => $s instanceof CommandStep),
        ));
    }

    public function test_a_synced_topology_is_checked_before_the_tests_run(): void
    {
        $commands = $this->testsJobCommands(new PipelineDefinition(syncComposeTopology: true));

        $check = array_search('php bin/console pipeline:topology:check', $commands, true);
        $tests = array_search('./vendor/bin/phpunit --testdox', $commands, true);
        self::assertIsInt($check);
        self::assertIsInt($tests);
        self::assertLessThan($tests, $check);
    }

    public function test_no_check_when_no_topology_reaches_a_host(): void
    {
        self::assertNotContains('php bin/console pipeline:topology:check', $this->testsJobCommands(new PipelineDefinition()));
    }
}
