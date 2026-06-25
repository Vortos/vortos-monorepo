<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Vortos\Migration\Console\MigrateAnalyzeCommand;
use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\MigrationArtifactFactoryInterface;
use Vortos\Migration\Safety\MigrationSafetyAnalyzerInterface;
use Vortos\Migration\Safety\PendingMigrationVersionProviderInterface;
use Vortos\Migration\Safety\SafetyDiagnostic;
use Vortos\Migration\Safety\SafetyResult;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Schema\MigrationPhase;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class MigrateAnalyzeCommandTest extends TestCase
{
    public function test_exits_zero_when_no_migrations(): void
    {
        $command = $this->buildCommand([], SafetyResult::clean('pg-native'));
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $input->bind($command->getDefinition());

        $code = $command->run($input, $output);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No migrations', $output->fetch());
    }

    public function test_exits_zero_when_all_migrations_clean(): void
    {
        $command = $this->buildCommand(
            ['App\\Migrations\\Version20260101' => SafetyResult::clean('pg-native')],
            SafetyResult::clean('pg-native'),
        );
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $input->bind($command->getDefinition());

        $code = $command->run($input, $output);

        $this->assertSame(0, $code);
    }

    public function test_exits_nonzero_when_error_diagnostic_present(): void
    {
        $errorResult = new SafetyResult('pg-native', [
            new SafetyDiagnostic('pg.index.non-concurrent', Severity::Error, 'users', 'CREATE INDEX', 'No CONCURRENTLY', 'Fix it'),
        ]);

        $command = $this->buildCommand(
            ['App\\Migrations\\Version20260101' => $errorResult],
            $errorResult,
        );
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $input->bind($command->getDefinition());

        $code = $command->run($input, $output);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('ERR', $output->fetch());
    }

    public function test_json_output_has_correct_shape(): void
    {
        $errorResult = new SafetyResult('pg-native', [
            new SafetyDiagnostic('pg.index.non-concurrent', Severity::Error, 'users', 'CREATE INDEX', 'msg', 'fix'),
        ]);

        $command = $this->buildCommand(
            ['App\\Migrations\\Version20260101' => $errorResult],
            $errorResult,
        );
        $output = new BufferedOutput();
        $input = new ArrayInput(['--json' => true]);
        $input->bind($command->getDefinition());

        $command->run($input, $output);
        $data = json_decode($output->fetch(), true);

        $this->assertArrayHasKey('ok', $data);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('engine', $data);
        $this->assertArrayHasKey('migrations', $data);
        $this->assertFalse($data['ok']);
    }

    public function test_all_flag_uses_getAll(): void
    {
        $version = 'App\\Migrations\\Version20260101';
        $command = $this->buildCommand(
            [$version => SafetyResult::clean('pg-native')],
            SafetyResult::clean('pg-native'),
            useAll: true,
        );
        $output = new BufferedOutput();
        $input = new ArrayInput(['--all' => true]);
        $input->bind($command->getDefinition());

        $code = $command->run($input, $output);
        $this->assertSame(0, $code);
    }

    public function test_warning_only_exits_zero(): void
    {
        $warnResult = new SafetyResult('pg-native', [
            new SafetyDiagnostic('pg.phase.undeclared', Severity::Warning, null, 'ALTER TABLE', 'Undeclared', 'Add @MigrationPhase'),
        ]);

        $command = $this->buildCommand(
            ['App\\Migrations\\Version20260101' => $warnResult],
            $warnResult,
        );
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $input->bind($command->getDefinition());

        $code = $command->run($input, $output);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('WRN', $output->fetch());
    }

    /** @param array<string, SafetyResult> $perVersion */
    private function buildCommand(
        array $perVersion,
        SafetyResult $defaultResult,
        bool $useAll = false,
    ): MigrateAnalyzeCommand {
        $analyzer = $this->createMock(MigrationSafetyAnalyzerInterface::class);
        $analyzer->method('engine')->willReturn('pg-native');
        $analyzer->method('capabilities')->willReturn(CapabilityDescriptor::create([]));
        $analyzer->method('analyze')->willReturnCallback(
            static function (MigrationArtifact $a) use ($perVersion, $defaultResult): SafetyResult {
                return $perVersion[$a->version] ?? $defaultResult;
            },
        );

        $artifactFactory = $this->createMock(MigrationArtifactFactoryInterface::class);
        $artifactFactory->method('fromClass')->willReturnCallback(
            static fn (string $cls) => new MigrationArtifact($cls, $cls, MigrationPhase::Expand, [], [], false),
        );

        $versions = array_keys($perVersion);
        $versionProvider = $this->createMock(PendingMigrationVersionProviderInterface::class);
        $versionProvider->method('getPending')->willReturn($versions);
        $versionProvider->method('getAll')->willReturn($versions);

        return new MigrateAnalyzeCommand($analyzer, $artifactFactory, $versionProvider);
    }
}
