<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Builder\RemoteDeployScript;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Model\FileOwner;
use Vortos\Pipeline\Model\HostEnvFileName;
use Vortos\Pipeline\Model\SealedToolingEnv;
use Vortos\Pipeline\Model\SecretFileMode;

/**
 * The database owner credential reaches the deploy one-shots — and only them — overriding the runtime credential
 * because it is listed after the runtime env (docker applies --env-file in order; measured on Docker 29).
 */
final class RemoteDeployScriptToolingEnvTest extends TestCase
{
    private static function script(): string
    {
        return (new RemoteDeployScript())->build(
            new PipelineDefinition(
                imageRepository: 'ghcr.io/acme/app',
                nativeRunnerLabel: 'ubuntu-24.04-arm',
                oidc: false,
                syncComposeTopology: true,
                syncComposeTopologyApply: true,
                sealedEnvFile: 'deploy/secrets/env.prod.sealed',
                sealedToolingEnvs: [new SealedToolingEnv('deploy/secrets/db-owner.env.sealed', new HostEnvFileName('db-owner.env'), SecretFileMode::OwnerReadWrite, new FileOwner(1001, 1001))],
                preCutoverCommands: ['vortos:database:roles:grant'],
            ),
            'ghcr.io/acme/app@${{ needs.build.outputs.image }}',
            'ghcr.io/acme/app',
            '${{ needs.build.outputs.image }}',
            '${{ matrix.environment }}',
        );
    }

    public function test_it_is_opened_with_its_declared_mode_and_owner_before_any_one_shot_reads_it(): void
    {
        $script = self::script();

        $reveal = strpos($script, 'deploy/secrets/open-env.php deploy/secrets/db-owner.env.sealed /opt/vortos/db-owner.env 0600 1001:1001');
        $firstConsumer = strpos($script, 'php bin/console vortos:migrate:analyze');
        self::assertIsInt($reveal);
        self::assertIsInt($firstConsumer);
        self::assertLessThan($firstConsumer, $reveal);
    }

    public function test_every_deploy_one_shot_reads_it_after_the_runtime_env(): void
    {
        $oneShots = array_values(array_filter(
            explode("\n", self::script()),
            static fn (string $line): bool => str_contains($line, 'php bin/console ') && str_contains($line, 'docker-socket-proxy:2375 --env-file'),
        ));

        self::assertNotEmpty($oneShots);
        $commands = [];
        foreach ($oneShots as $line) {
            self::assertStringContainsString('--env-file /opt/vortos/.env.prod --env-file /opt/vortos/db-owner.env ', $line);
            $commands[] = substr($line, (int) strpos($line, 'php bin/console ') + 16);
        }
        self::assertContains('vortos:database:roles:grant', $commands);
        self::assertNotEmpty(array_filter($commands, static fn (string $c): bool => str_starts_with($c, 'vortos:deploy:provision')));
    }

    public function test_the_topology_sync_and_the_secret_reveals_do_not_read_it(): void
    {
        foreach (explode("\n", self::script()) as $line) {
            if (str_contains($line, 'vortos:deploy:compose:sync') || str_contains($line, 'open-env.php')) {
                self::assertStringNotContainsString('--env-file /opt/vortos/db-owner.env', $line);
            }
        }
    }
}
