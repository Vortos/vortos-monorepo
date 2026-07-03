<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Release\Console\RecordManifestCommand;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Manifest\ManifestAlreadyExistsException;
use Vortos\Release\Migration\AvailableMigrationSetReaderInterface;
use Vortos\Release\ReadModel\ManifestRepositoryInterface;
use Vortos\Release\Schema\SchemaFingerprint;

final class RecordManifestCommandTest extends TestCase
{
    private InMemoryManifestRepository $repo;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->repo = new InMemoryManifestRepository();
        $reader = new class implements AvailableMigrationSetReaderInterface {
            public function availableSet(): SchemaFingerprint
            {
                return new SchemaFingerprint(['m001', 'm002']);
            }
        };

        $this->tester = new CommandTester(new RecordManifestCommand($this->repo, $reader));
    }

    public function test_records_manifest_with_repository_and_digest(): void
    {
        $exit = $this->tester->execute([
            '--env' => 'production',
            '--repository' => 'ghcr.io/acme/app',
            '--digest' => 'sha256:' . str_repeat('a', 64),
            '--git-sha' => 'abc1234def5678',
            '--arch' => 'arm64',
        ]);

        $this->assertSame(0, $exit);
        $this->assertCount(1, $this->repo->recorded);

        $manifest = $this->repo->recorded[0];
        $this->assertSame('ghcr.io/acme/app', $manifest->imageRepository);
        $this->assertSame('sha256:' . str_repeat('a', 64), $manifest->imageDigest);
        $this->assertSame('production', $manifest->environment);
        $this->assertSame('ghcr.io/acme/app@sha256:' . str_repeat('a', 64), $manifest->pullReference());
        $this->assertSame(Arch::Arm64, $manifest->targetArch);
        $this->assertSame(['m001', 'm002'], $manifest->schemaFingerprint->migrationIds);
    }

    public function test_build_id_defaults_to_git_sha_prefix(): void
    {
        $this->tester->execute([
            '--env' => 'staging',
            '--repository' => 'ghcr.io/acme/app',
            '--digest' => 'sha256:' . str_repeat('b', 64),
            '--git-sha' => 'abcdef0123456789',
        ]);

        $this->assertSame('abcdef012345', $this->repo->recorded[0]->buildId);
    }

    public function test_is_idempotent_on_duplicate_build_id(): void
    {
        $args = [
            '--env' => 'production',
            '--repository' => 'ghcr.io/acme/app',
            '--digest' => 'sha256:' . str_repeat('a', 64),
            '--git-sha' => 'abc1234',
            '--build-id' => 'build-1',
        ];

        $this->assertSame(0, $this->tester->execute($args));
        $this->assertSame(0, $this->tester->execute($args), 'Re-recording the same build id must succeed.');
        $this->assertCount(1, $this->repo->recorded, 'Duplicate must not double-record.');
        $this->assertStringContainsString('already-recorded', $this->tester->getDisplay());
    }

    public function test_rejects_invalid_repository(): void
    {
        $exit = $this->tester->execute([
            '--env' => 'production',
            '--repository' => 'ghcr.io/acme/app:v1',
            '--digest' => 'sha256:' . str_repeat('a', 64),
            '--git-sha' => 'abc1234',
        ]);

        $this->assertSame(1, $exit);
        $this->assertCount(0, $this->repo->recorded);
        $this->assertStringContainsString('Image repository', $this->tester->getDisplay());
    }

    public function test_rejects_missing_required_option(): void
    {
        $exit = $this->tester->execute([
            '--env' => 'production',
            '--repository' => 'ghcr.io/acme/app',
            '--digest' => 'sha256:' . str_repeat('a', 64),
            // no --git-sha
        ]);

        $this->assertSame(1, $exit);
        $this->assertCount(0, $this->repo->recorded);
    }

    public function test_json_output_reports_pull_reference(): void
    {
        $this->tester->execute([
            '--env' => 'production',
            '--repository' => 'ghcr.io/acme/app',
            '--digest' => 'sha256:' . str_repeat('c', 64),
            '--git-sha' => 'abc1234',
            '--json' => true,
        ]);

        $payload = json_decode(trim($this->tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('recorded', $payload['status']);
        $this->assertSame('ghcr.io/acme/app@sha256:' . str_repeat('c', 64), $payload['image']);
    }
}

final class InMemoryManifestRepository implements ManifestRepositoryInterface
{
    /** @var list<BuildManifest> */
    public array $recorded = [];

    public function record(BuildManifest $manifest): void
    {
        foreach ($this->recorded as $existing) {
            if ($existing->buildId === $manifest->buildId) {
                throw ManifestAlreadyExistsException::forBuildId($manifest->buildId);
            }
        }

        $this->recorded[] = $manifest;
    }
}
