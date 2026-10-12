<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Topology;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Model\ComposeServiceSet;
use Vortos\Pipeline\Model\DerivedServiceEnv;
use Vortos\Pipeline\Model\FileOwner;
use Vortos\Pipeline\Model\HostEnvFileName;
use Vortos\Pipeline\Model\SecretFileMode;
use Vortos\Pipeline\Topology\EnvFileScopeRule;
use Vortos\Pipeline\Topology\TopologyPolicy;
use Vortos\Pipeline\Topology\TopologyViolation;

/**
 * RC-7: a derived env is scoped like a sealed one, and a service that mounts it must not also mount the
 * app-wide runtime env — that would hand it every variable the derivation withholds.
 */
final class EnvFileScopeRuleDerivedEnvTest extends TestCase
{
    private const DERIVED_ALONE = <<<'YAML'
        services:
          app:
            env_file:
              - ./.env.prod
          backup:
            env_file:
              - ./backup.env
        YAML;

    private const DERIVED_BESIDE_RUNTIME = <<<'YAML'
        services:
          app:
            env_file:
              - ./.env.prod
          backup:
            env_file:
              - ./.env.prod
              - ./backup.env
        YAML;

    private const DERIVED_ON_THE_WRONG_SERVICE = <<<'YAML'
        services:
          app:
            env_file:
              - ./backup.env
          backup:
            env_file:
              - ./backup.env
        YAML;

    /** @return list<string> */
    private static function kinds(string $yaml): array
    {
        $rule = EnvFileScopeRule::forDefinition(new PipelineDefinition(
            oidc: false,
            remoteDeployDir: '/opt/vortos',
            runtimeEnvFiles: ['/opt/vortos/.env.prod'],
            sealedEnvFile: 'deploy/secrets/env.prod.sealed',
            derivedServiceEnvs: [new DerivedServiceEnv(
                new HostEnvFileName('backup.env'),
                ['VORTOS_WRITE_DB_DSN', 'REDIS_DSN'],
                SecretFileMode::OwnerReadWrite,
                FileOwner::root(),
                new ComposeServiceSet(['backup']),
            )],
        ));

        return array_map(
            static fn (TopologyViolation $v): string => $v->kind->value . ':' . $v->service,
            (new TopologyPolicy('/opt/vortos', [$rule]))->evaluate($yaml),
        );
    }

    public function test_a_service_on_its_derived_env_alone_is_clear(): void
    {
        self::assertSame([], self::kinds(self::DERIVED_ALONE));
    }

    public function test_the_runtime_env_beside_a_derived_one_is_refused(): void
    {
        self::assertSame(['runtime_env_beside_derived:backup'], self::kinds(self::DERIVED_BESIDE_RUNTIME));
    }

    public function test_a_service_outside_the_audience_is_refused(): void
    {
        self::assertSame(['service_not_allowed:app'], self::kinds(self::DERIVED_ON_THE_WRONG_SERVICE));
    }

    public function test_a_derived_env_needs_the_sealed_source(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('declare sealedEnvFile');

        new PipelineDefinition(
            oidc: false,
            derivedServiceEnvs: [new DerivedServiceEnv(new HostEnvFileName('backup.env'), ['A'], SecretFileMode::OwnerReadWrite, FileOwner::root(), new ComposeServiceSet(['backup']))],
        );
    }

    public function test_names_are_validated_and_sorted(): void
    {
        $env = new DerivedServiceEnv(new HostEnvFileName('b.env'), ['ZED', 'ALPHA'], SecretFileMode::OwnerReadWrite, FileOwner::root(), new ComposeServiceSet(['b']));
        self::assertSame(['ALPHA', 'ZED'], $env->names);

        foreach ([[], ['A', 'A'], ['A=B'], ['A,B'], ['1A']] as $bad) {
            try {
                new DerivedServiceEnv(new HostEnvFileName('b.env'), $bad, SecretFileMode::OwnerReadWrite, FileOwner::root(), new ComposeServiceSet(['b']));
                self::fail('accepted ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
