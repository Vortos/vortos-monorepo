<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Definition;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Definition\PipelineDefinitionBuilder;
use Vortos\Pipeline\Model\ComposeServiceSet;
use Vortos\Pipeline\Model\FileOwner;
use Vortos\Pipeline\Model\HostEnvFileName;
use Vortos\Pipeline\Model\RootOfTrustEnvFile;
use Vortos\Pipeline\Model\SealedServiceEnv;
use Vortos\Pipeline\Model\SecretFileMode;

/**
 * RC-4: a pipeline definition refuses a per-service secret declaration it could not deliver safely.
 */
final class PipelineDefinitionSealedServiceEnvTest extends TestCase
{
    private static function sealed(string $target = 'backup-r2.env'): SealedServiceEnv
    {
        return new SealedServiceEnv(
            'deploy/secrets/' . $target . '.sealed',
            new HostEnvFileName($target),
            SecretFileMode::OwnerReadWrite,
            FileOwner::root(),
            new ComposeServiceSet(['backup-scheduler']),
        );
    }

    private static function root(string $target = 'age.env'): RootOfTrustEnvFile
    {
        return new RootOfTrustEnvFile(new HostEnvFileName($target), new ComposeServiceSet(['backup-scheduler']), 'holds the identity sealed files open with');
    }

    public function test_the_builder_propagates_every_declaration(): void
    {
        $definition = PipelineDefinitionBuilder::create()
            ->sealedServiceEnvs(self::sealed('backup-r2.env'), self::sealed('backup-identity.env'))
            ->rootOfTrustEnvFiles(self::root())
            ->composeTopologyPath('deploy/compose.yaml')
            ->syncComposeTopology(true)
            ->build();

        self::assertSame(['backup-r2.env', 'backup-identity.env'], array_map(static fn (SealedServiceEnv $s): string => $s->target->value, $definition->sealedServiceEnvs));
        self::assertSame('age.env', $definition->rootOfTrustEnvFiles[0]->target->value);
        self::assertSame('deploy/compose.yaml', $definition->composeTopologyPath);

        $array = $definition->toArray();
        self::assertSame(
            ['sealed_path' => 'deploy/secrets/backup-r2.env.sealed', 'target' => 'backup-r2.env', 'mode' => '0600', 'owner' => '0:0', 'services' => ['backup-scheduler']],
            $array['sealed_service_envs'][0],
        );
        self::assertSame([['target' => 'age.env', 'services' => ['backup-scheduler']]], $array['root_of_trust_env_files']);
        self::assertSame('deploy/compose.yaml', $array['compose_topology_path']);
    }

    public function test_a_host_env_file_is_declared_once(): void
    {
        $this->expectExceptionMessage('declared once');

        new PipelineDefinition(sealedServiceEnvs: [self::sealed('age.env')], rootOfTrustEnvFiles: [self::root('age.env')]);
    }

    public function test_a_scoped_file_cannot_be_a_runtime_env_file(): void
    {
        $this->expectExceptionMessage('also a runtime env file');

        new PipelineDefinition(runtimeEnvFiles: ['/opt/vortos/shared.env'], sealedServiceEnvs: [self::sealed('shared.env')]);
    }

    public function test_sealed_files_are_refused_under_oidc_rather_than_silently_skipped(): void
    {
        $this->expectExceptionMessage('age-KEK deploy posture');

        new PipelineDefinition(oidc: true, sealedServiceEnvs: [self::sealed()]);
    }

    public function test_a_root_of_trust_file_alone_is_allowed_under_oidc(): void
    {
        $definition = new PipelineDefinition(oidc: true, rootOfTrustEnvFiles: [self::root()]);

        self::assertCount(1, $definition->rootOfTrustEnvFiles);
    }

    public function test_untyped_config_entries_are_refused(): void
    {
        $this->expectExceptionMessage('SealedServiceEnv instances');

        // @phpstan-ignore argument.type
        new PipelineDefinition(sealedServiceEnvs: [['target' => 'backup-r2.env']]);
    }

    /** @return iterable<string, array{string}> */
    public static function badTopologyPaths(): iterable
    {
        yield 'empty' => [''];
        yield 'absolute' => ['/var/www/html/docker-compose.prod.yaml'];
        yield 'traversal' => ['../docker-compose.prod.yaml'];
        yield 'metachar' => ['compose;id.yaml'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badTopologyPaths')]
    public function test_refuses_an_unsafe_topology_path(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PipelineDefinition(composeTopologyPath: $path);
    }
}
