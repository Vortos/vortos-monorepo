<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Definition;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Definition\PipelineDefinitionBuilder;
use Vortos\Pipeline\Model\ComposeServiceSet;
use Vortos\Pipeline\Model\FileOwner;
use Vortos\Pipeline\Model\HostEnvFileName;
use Vortos\Pipeline\Model\SealedServiceEnv;
use Vortos\Pipeline\Model\SealedToolingEnv;
use Vortos\Pipeline\Model\SecretFileMode;

/** A deploy-tooling credential (the database owner) is declared once, delivered sealed, and never under OIDC. */
final class PipelineDefinitionSealedToolingEnvTest extends TestCase
{
    private static function tooling(string $target = 'db-owner.env'): SealedToolingEnv
    {
        return new SealedToolingEnv('deploy/secrets/' . $target . '.sealed', new HostEnvFileName($target), SecretFileMode::OwnerReadWrite, new FileOwner(1001, 1001));
    }

    public function test_the_builder_propagates_the_declaration_and_it_serialises_without_secret_material(): void
    {
        $definition = PipelineDefinitionBuilder::create()
            ->imageRepository('ghcr.io/acme/app')
            ->nativeRunnerLabel('ubuntu-24.04-arm')
            ->oidc(false)
            ->sealedToolingEnvs(self::tooling())
            ->build();

        self::assertSame(['db-owner.env'], array_map(static fn (SealedToolingEnv $t): string => $t->target->value, $definition->sealedToolingEnvs));
        self::assertSame(
            [['sealed_path' => 'deploy/secrets/db-owner.env.sealed', 'target' => 'db-owner.env', 'mode' => '0600', 'owner' => '1001:1001']],
            $definition->toArray()['sealed_tooling_envs'],
        );
    }

    public function test_a_tooling_file_cannot_share_a_name_with_a_service_file(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('declared once');

        new PipelineDefinition(
            sealedServiceEnvs: [new SealedServiceEnv('deploy/secrets/x.sealed', new HostEnvFileName('db-owner.env'), SecretFileMode::OwnerReadWrite, FileOwner::root(), new ComposeServiceSet(['backup-scheduler']))],
            sealedToolingEnvs: [self::tooling()],
        );
    }

    public function test_a_tooling_file_cannot_be_the_runtime_env_that_reaches_every_colour(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PipelineDefinition(runtimeEnvFiles: ['/opt/vortos/db-owner.env'], sealedToolingEnvs: [self::tooling()]);
    }

    public function test_it_is_refused_under_oidc_rather_than_migrations_silently_running_as_the_runtime_role(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('age-KEK');

        new PipelineDefinition(oidc: true, sealedToolingEnvs: [self::tooling()]);
    }

    public function test_untyped_entries_and_unsafe_paths_are_refused(): void
    {
        try {
            new PipelineDefinition(sealedToolingEnvs: [['target' => 'db-owner.env']]);
            self::fail('accepted an array entry');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('SealedToolingEnv instances', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        new SealedToolingEnv('../db-owner.env.sealed', new HostEnvFileName('db-owner.env'), SecretFileMode::OwnerReadWrite, new FileOwner(1001, 1001));
    }
}
