<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Topology;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\ComposeServiceSet;
use Vortos\Pipeline\Topology\EnvFileScopeRule;
use Vortos\Pipeline\Topology\TopologyPolicy;
use Vortos\Pipeline\Topology\TopologyViolation;

/**
 * RC-4 guard: every env file a service reads is accounted for, and only its declared audience reads it.
 */
final class EnvFileScopeRuleTest extends TestCase
{
    private const CLEAR = <<<'YAML'
        x-logging: &logging
          driver: json-file
        services:
          write_db:
            logging: *logging
            env_file:
              - ./.env.prod
          backup-scheduler:
            <<: { restart: always }
            env_file:
              - ./.env.prod
              - ./age.env
              - ./backup-identity.env
              - ./backup-r2.env
        volumes:
          write_db_data: {}
        YAML;

    /** @param array<string, list<string>>|null $scoped */
    private function policy(?array $scoped = null): TopologyPolicy
    {
        $scoped ??= [
            'age.env' => ['backup-scheduler'],
            'backup-identity.env' => ['backup-scheduler'],
            'backup-r2.env' => ['backup-scheduler'],
        ];

        return new TopologyPolicy('/opt/vortos', [new EnvFileScopeRule(
            '/opt/vortos',
            ['/opt/vortos/.env.prod'],
            array_map(static fn (array $s): ComposeServiceSet => new ComposeServiceSet($s), $scoped),
        )]);
    }

    /** @return list<string> */
    private function kinds(string $yaml, ?TopologyPolicy $policy = null): array
    {
        return array_map(
            static fn (TopologyViolation $v): string => $v->kind->value . ':' . $v->service,
            ($policy ?? $this->policy())->evaluate($yaml),
        );
    }

    public function test_the_production_shape_is_clear(): void
    {
        self::assertSame([], $this->kinds(self::CLEAR));
    }

    public function test_an_env_file_nothing_delivers_is_refused(): void
    {
        $yaml = str_replace('      - ./backup-r2.env', "      - ./backup-r2.env\n      - ./hand-made.env", self::CLEAR);

        self::assertSame(['undeclared:backup-scheduler'], $this->kinds($yaml));
    }

    public function test_a_scoped_secret_on_a_service_outside_its_audience_is_refused(): void
    {
        $yaml = str_replace("    env_file:\n      - ./.env.prod\n  backup-scheduler", "    env_file:\n      - ./.env.prod\n      - ./backup-r2.env\n  backup-scheduler", self::CLEAR);

        self::assertSame(['service_not_allowed:write_db'], $this->kinds($yaml));
    }

    public function test_an_allowed_service_that_never_mounts_the_file_is_refused(): void
    {
        $policy = $this->policy([
            'age.env' => ['backup-scheduler'],
            'backup-identity.env' => ['backup-scheduler'],
            'backup-r2.env' => ['backup-scheduler', 'write_db'],
        ]);

        self::assertSame(['unconsumed:write_db'], $this->kinds(self::CLEAR, $policy));
    }

    public function test_a_declared_secret_no_service_mounts_is_refused(): void
    {
        $policy = $this->policy([
            'age.env' => ['backup-scheduler'],
            'backup-identity.env' => ['backup-scheduler'],
            'backup-r2.env' => ['backup-scheduler'],
            'pgbackrest.env' => ['write_db'],
        ]);

        self::assertSame(['unconsumed:write_db'], $this->kinds(self::CLEAR, $policy));
    }

    public function test_a_runtime_env_listed_after_a_scoped_secret_is_refused_because_it_would_win(): void
    {
        $yaml = <<<'YAML'
            services:
              backup-scheduler:
                env_file:
                  - ./age.env
                  - ./backup-identity.env
                  - ./backup-r2.env
                  - ./.env.prod
            YAML;

        self::assertSame(
            ['shadowed_by_runtime_env:backup-scheduler', 'shadowed_by_runtime_env:backup-scheduler', 'shadowed_by_runtime_env:backup-scheduler'],
            $this->kinds($yaml),
        );
    }

    public function test_an_optional_scoped_secret_is_refused(): void
    {
        $yaml = <<<'YAML'
            services:
              backup-scheduler:
                env_file:
                  - path: ./.env.prod
                  - path: ./age.env
                  - path: ./backup-identity.env
                    required: true
                  - path: ./backup-r2.env
                    required: false
            YAML;

        self::assertSame(['optional:backup-scheduler'], $this->kinds($yaml));
    }

    public function test_interpolated_or_traversing_paths_are_unverifiable(): void
    {
        $yaml = <<<'YAML'
            services:
              backup-scheduler:
                env_file:
                  - ./.env.prod
                  - ./age.env
                  - ${SECRETS_DIR}/backup-identity.env
                  - ../vortos/backup-r2.env
            YAML;

        self::assertSame(
            [
                'unverifiable:backup-scheduler',
                'unverifiable:backup-scheduler',
                'unconsumed:backup-scheduler',
                'unconsumed:backup-scheduler',
            ],
            $this->kinds($yaml),
        );
    }

    public function test_string_form_and_absolute_paths_resolve_like_compose(): void
    {
        $yaml = <<<'YAML'
            services:
              backup-scheduler:
                env_file: /opt/vortos/age.env
            YAML;

        self::assertSame([], $this->kinds($yaml, $this->policy(['age.env' => ['backup-scheduler']])));
    }

    public function test_a_malformed_env_file_entry_is_unverifiable(): void
    {
        $yaml = <<<'YAML'
            services:
              backup-scheduler:
                env_file:
                  - { required: true }
            YAML;

        self::assertContains('unverifiable:backup-scheduler', $this->kinds($yaml));
    }

    public function test_a_scoped_file_cannot_also_be_a_runtime_env_file(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EnvFileScopeRule('/opt/vortos', ['/opt/vortos/shared.env'], ['shared.env' => new ComposeServiceSet(['app'])]);
    }

    public function test_violations_name_the_rule_service_and_file_but_never_contents(): void
    {
        $yaml = str_replace('      - ./backup-r2.env', "      - ./backup-r2.env\n      - ./hand-made.env", self::CLEAR);
        $violation = $this->policy()->evaluate($yaml)[0];

        self::assertStringStartsWith('[env_file_scope/undeclared] service "backup-scheduler", "./hand-made.env": ', $violation->message());
        self::assertSame(['rule', 'kind', 'service', 'subject', 'detail'], array_keys($violation->toArray()));
    }
}
