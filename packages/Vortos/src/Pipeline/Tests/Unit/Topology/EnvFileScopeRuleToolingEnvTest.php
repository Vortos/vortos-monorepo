<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Topology;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Model\FileOwner;
use Vortos\Pipeline\Model\HostEnvFileName;
use Vortos\Pipeline\Model\SealedToolingEnv;
use Vortos\Pipeline\Model\SecretFileMode;
use Vortos\Pipeline\Topology\EnvFileScopeRule;
use Vortos\Pipeline\Topology\TopologyPolicy;
use Vortos\Pipeline\Topology\TopologyViolation;

/** The database owner credential is deploy tooling: no running service may mount it, in any position. */
final class EnvFileScopeRuleToolingEnvTest extends TestCase
{
    private const CLEAR = <<<'YAML'
        services:
          app:
            env_file:
              - ./.env.prod
          write_db:
            env_file:
              - ./.env.prod
        YAML;

    private const MOUNTED = <<<'YAML'
        services:
          app:
            env_file:
              - ./.env.prod
              - ./db-owner.env
          write_db:
            env_file:
              - path: ./db-owner.env
                required: true
              - ./.env.prod
        YAML;

    /** @return list<string> */
    private static function kinds(string $yaml): array
    {
        $rule = EnvFileScopeRule::forDefinition(new PipelineDefinition(
            oidc: false,
            remoteDeployDir: '/opt/vortos',
            runtimeEnvFiles: ['/opt/vortos/.env.prod'],
            sealedToolingEnvs: [new SealedToolingEnv('deploy/secrets/db-owner.env.sealed', new HostEnvFileName('db-owner.env'), SecretFileMode::OwnerReadWrite, new FileOwner(1001, 1001))],
        ));

        return array_map(
            static fn (TopologyViolation $v): string => $v->kind->value . ':' . $v->service,
            (new TopologyPolicy('/opt/vortos', [$rule]))->evaluate($yaml),
        );
    }

    public function test_a_topology_no_service_of_which_mounts_it_is_clear_and_not_unconsumed(): void
    {
        self::assertSame([], self::kinds(self::CLEAR));
    }

    public function test_any_service_mounting_it_is_refused(): void
    {
        self::assertSame(['tooling_only:app', 'tooling_only:write_db'], self::kinds(self::MOUNTED));
    }
}
