<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Conformance;

use Vortos\Migration\Driver\Squawk\ProcessRunnerInterface;
use Vortos\Migration\Driver\Squawk\SquawkSafetyAnalyzer;
use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\MigrationSafetyAnalyzerInterface;
use Vortos\Migration\Safety\MigrationSafetyCapability;
use Vortos\Migration\Schema\MigrationPhase;

final class SquawkSafetyAnalyzerConformanceTest extends MigrationSafetyAnalyzerConformanceTestCase
{
    protected function createAnalyzer(): MigrationSafetyAnalyzerInterface
    {
        $runner = new class implements ProcessRunnerInterface {
            public function run(string $binary, string $stdin, int $timeoutSeconds): array
            {
                return ['exitCode' => 0, 'stdout' => '[]', 'stderr' => ''];
            }
        };

        return new SquawkSafetyAnalyzer($runner, __FILE__);
    }

    protected function expectedKey(): string
    {
        return 'squawk';
    }

    public function test_does_not_read_live_table_stats(): void
    {
        $descriptor = $this->createAnalyzer()->capabilities();
        $this->assertFalse($descriptor->supports(MigrationSafetyCapability::ReadsLiveTableStats));
    }

    public function test_missing_binary_fails_closed(): void
    {
        $runner = new class implements ProcessRunnerInterface {
            public function run(string $binary, string $stdin, int $timeoutSeconds): array
            {
                return ['exitCode' => 0, 'stdout' => '[]', 'stderr' => ''];
            }
        };

        $analyzer = new SquawkSafetyAnalyzer($runner, '/does/not/exist');
        $artifact = new MigrationArtifact('Test', null, MigrationPhase::Expand, ['CREATE TABLE t (id INT)'], [], false);
        $result = $analyzer->analyze($artifact, null);

        $this->assertTrue($result->hasErrors(), 'Missing binary must fail closed with an Error.');
    }

    public function test_malformed_json_fails_closed(): void
    {
        $runner = new class implements ProcessRunnerInterface {
            public function run(string $binary, string $stdin, int $timeoutSeconds): array
            {
                return ['exitCode' => 0, 'stdout' => 'not-json', 'stderr' => ''];
            }
        };

        $analyzer = new SquawkSafetyAnalyzer($runner, __FILE__);
        $artifact = new MigrationArtifact('Test', null, MigrationPhase::Expand, ['SELECT 1'], [], false);
        $result = $analyzer->analyze($artifact, null);

        $this->assertTrue($result->hasErrors());
    }

    public function test_process_exception_fails_closed(): void
    {
        $runner = new class implements ProcessRunnerInterface {
            public function run(string $binary, string $stdin, int $timeoutSeconds): array
            {
                throw new \RuntimeException('Process timed out');
            }
        };

        $analyzer = new SquawkSafetyAnalyzer($runner, __FILE__);
        $artifact = new MigrationArtifact('Test', null, MigrationPhase::Expand, ['SELECT 1'], [], false);
        $result = $analyzer->analyze($artifact, null);

        $this->assertTrue($result->hasErrors());
    }

    public function test_nonzero_exit_with_empty_output_fails_closed(): void
    {
        $runner = new class implements ProcessRunnerInterface {
            public function run(string $binary, string $stdin, int $timeoutSeconds): array
            {
                return ['exitCode' => 1, 'stdout' => '', 'stderr' => 'error'];
            }
        };

        $analyzer = new SquawkSafetyAnalyzer($runner, __FILE__);
        $artifact = new MigrationArtifact('Test', null, MigrationPhase::Expand, ['SELECT 1'], [], false);
        $result = $analyzer->analyze($artifact, null);

        $this->assertTrue($result->hasErrors());
    }
}
