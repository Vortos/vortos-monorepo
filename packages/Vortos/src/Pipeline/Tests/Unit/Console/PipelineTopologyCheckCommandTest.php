<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Pipeline\Console\PipelineTopologyCheckCommand;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Model\ComposeServiceSet;
use Vortos\Pipeline\Model\HostEnvFileName;
use Vortos\Pipeline\Model\RootOfTrustEnvFile;

final class PipelineTopologyCheckCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vortos-topology-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/docker-compose.prod.yaml');
        @rmdir($this->dir);
    }

    private function checkTopology(string $yaml): CommandTester
    {
        file_put_contents($this->dir . '/docker-compose.prod.yaml', $yaml);

        return $this->tester();
    }

    private function tester(): CommandTester
    {
        $definition = new PipelineDefinition(
            rootOfTrustEnvFiles: [new RootOfTrustEnvFile(new HostEnvFileName('age.env'), new ComposeServiceSet(['backup-scheduler']), 'the identity itself')],
        );
        $tester = new CommandTester(new PipelineTopologyCheckCommand($definition, $this->dir));
        $tester->execute(['--json' => true]);

        return $tester;
    }

    public function test_clear_topology_succeeds(): void
    {
        $tester = $this->checkTopology("services:\n  backup-scheduler:\n    env_file: [./.env.prod, ./age.env]\n");

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('clear', json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR)['status']);
    }

    public function test_a_violation_fails_and_is_reported(): void
    {
        $tester = $this->checkTopology("services:\n  app-blue:\n    env_file: [./.env.prod, ./age.env]\n");

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('refused', $report['status']);
        self::assertSame(['service_not_allowed', 'unconsumed'], array_column($report['violations'], 'kind'));
    }

    public function test_a_missing_topology_fails_closed(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('refused', json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR)['status']);
    }
}
