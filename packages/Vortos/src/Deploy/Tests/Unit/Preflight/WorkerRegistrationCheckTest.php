<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Definition\WorkerTopology;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Preflight\Check\WorkerRegistrationCheck;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\Strategy\DeployStrategy;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Docker\Worker\WorkerProcessDefinition;
use Vortos\Docker\Worker\WorkerProcessRegistry;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

/**
 * Registering a worker does not make it run — the supervisor config is a committed file, and a
 * definition only reaches it when someone runs `vortos:worker:install`. When nobody does, the deploy
 * is green and the worker drains nothing. The doctor fails closed on that gap.
 */
final class WorkerRegistrationCheckTest extends TestCase
{
    public function test_registered_worker_missing_a_program_fails_closed(): void
    {
        $finding = $this->check(
            registry: $this->registry('alerts-drain', 'outbox-relay'),
            config: "[supervisord]\nnodaemon=true\n\n[program:outbox-relay]\ncommand=php bin/console vortos:outbox:relay\n",
        )->check($this->context());

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('alerts-drain', $finding->detail);
        self::assertStringNotContainsString('outbox-relay,', $finding->detail);
        self::assertStringContainsString('vortos:worker:install', $finding->remediation);
    }

    public function test_all_registered_workers_present_passes(): void
    {
        $finding = $this->check(
            registry: $this->registry('alerts-drain', 'outbox-relay'),
            config: "[program:alerts-drain]\ncommand=x\n\n[program:outbox-relay]\ncommand=y\n",
        )->check($this->context());

        self::assertSame(PreflightStatus::Pass, $finding->status);
    }

    /**
     * Presence, not equality: a drifted comment or a changed log path is a build-time concern
     * (`--check` reports it stale). Blocking a rollout on it would make the gate one people disable.
     */
    public function test_drifted_program_body_still_passes(): void
    {
        $finding = $this->check(
            registry: $this->registry('alerts-drain'),
            config: "; some hand-written operational note\n[program:alerts-drain]\ncommand=totally --different\nstartsecs=99\n",
        )->check($this->context());

        self::assertSame(PreflightStatus::Pass, $finding->status);
    }

    public function test_no_registry_is_skipped_not_failed(): void
    {
        $check = new WorkerRegistrationCheck(null, static fn (string $p): ?string => '');

        self::assertSame(PreflightStatus::Skip, $check->check($this->context())->status);
    }

    public function test_no_registered_workers_passes(): void
    {
        $finding = $this->check(registry: new WorkerProcessRegistry(), config: '')->check($this->context());

        self::assertSame(PreflightStatus::Pass, $finding->status);
    }

    public function test_absent_config_is_skipped_not_failed(): void
    {
        $check = new WorkerRegistrationCheck(
            $this->registry('alerts-drain'),
            static fn (string $p): ?string => null,
        );

        self::assertSame(PreflightStatus::Skip, $check->check($this->context())->status);
    }

    /**
     * Workers are placed across containers on purpose — the scheduler daemon must run on exactly one
     * node. Demanding every registered worker in the worker color's config would require a second
     * scheduler and fail a correct deployment, so a worker placed in a sibling container's config
     * counts as placed.
     */
    public function test_worker_placed_in_a_sibling_container_config_passes(): void
    {
        $previous = $_ENV['VORTOS_WORKER_SUPERVISOR_CONFIGS'] ?? null;
        $_ENV['VORTOS_WORKER_SUPERVISOR_CONFIGS'] = '/etc/supervisord.scheduler.conf';

        try {
            $check = new WorkerRegistrationCheck(
                $this->registry('alerts-drain', 'scheduler-daemon'),
                static fn (string $p): ?string => $p === '/etc/supervisord.scheduler.conf'
                    ? "[program:scheduler-daemon]\ncommand=x\n"
                    : "[program:alerts-drain]\ncommand=y\n",
            );

            self::assertSame(PreflightStatus::Pass, $check->check($this->context())->status);
        } finally {
            if ($previous === null) {
                unset($_ENV['VORTOS_WORKER_SUPERVISOR_CONFIGS']);
            } else {
                $_ENV['VORTOS_WORKER_SUPERVISOR_CONFIGS'] = $previous;
            }
        }
    }

    public function test_worker_in_no_config_at_all_still_fails(): void
    {
        $previous = $_ENV['VORTOS_WORKER_SUPERVISOR_CONFIGS'] ?? null;
        $_ENV['VORTOS_WORKER_SUPERVISOR_CONFIGS'] = '/etc/supervisord.scheduler.conf';

        try {
            $check = new WorkerRegistrationCheck(
                $this->registry('alerts-drain', 'scheduler-daemon'),
                static fn (string $p): ?string => "[program:scheduler-daemon]\ncommand=x\n",
            );

            $finding = $check->check($this->context());

            self::assertSame(PreflightStatus::Fail, $finding->status);
            self::assertStringContainsString('alerts-drain', $finding->detail);
        } finally {
            if ($previous === null) {
                unset($_ENV['VORTOS_WORKER_SUPERVISOR_CONFIGS']);
            } else {
                $_ENV['VORTOS_WORKER_SUPERVISOR_CONFIGS'] = $previous;
            }
        }
    }

    public function test_non_supervisord_worker_command_is_skipped(): void
    {
        $finding = $this->check(
            registry: $this->registry('alerts-drain'),
            config: '',
        )->check($this->context(['php', 'bin/console', 'messenger:consume', 'async']));

        self::assertSame(PreflightStatus::Skip, $finding->status);
    }

    public function test_config_path_comes_from_the_worker_command(): void
    {
        $seen = null;
        $check = new WorkerRegistrationCheck(
            $this->registry('alerts-drain'),
            static function (string $p) use (&$seen): ?string {
                $seen = $p;

                return "[program:alerts-drain]\n";
            },
        );

        $check->check($this->context(['/usr/bin/supervisord', '-c', '/custom/supervisord.conf']));

        self::assertSame('/custom/supervisord.conf', $seen);
    }

    private function check(WorkerProcessRegistry $registry, string $config): WorkerRegistrationCheck
    {
        return new WorkerRegistrationCheck($registry, static fn (string $p): ?string => $config);
    }

    private function registry(string ...$names): WorkerProcessRegistry
    {
        $registry = new WorkerProcessRegistry();
        foreach ($names as $name) {
            $registry->add(new WorkerProcessDefinition(
                name: $name,
                command: 'php /var/www/html/bin/console ' . $name,
                description: 'Test worker ' . $name,
            ));
        }

        return $registry;
    }

    /** @param list<string>|null $workerCommand */
    private function context(?array $workerCommand = null): PreflightContext
    {
        $definition = new DeploymentDefinition(
            host: 'ssh-compose',
            registry: 'dockerhub',
            ci: 'github',
            secrets: 'age',
            monitoring: 'grafana',
            notifiers: [],
            credential: 'ssh-key',
            strategy: DeployStrategy::BlueGreen,
            arch: Arch::Arm64,
            autoRollback: true,
            definitionHash: 'test-hash',
            runtimeService: new RuntimeServiceSpec(
                workerCommand: $workerCommand ?? RuntimeServiceSpec::DEFAULT_WORKER_COMMAND,
            ),
            workerTopology: WorkerTopology::RideColor,
        );

        $manifest = new BuildManifest(
            buildId: 'build-1',
            gitSha: str_repeat('a', 40),
            imageRepository: 'ghcr.io/acme/app',
            imageDigest: 'sha256:' . str_repeat('ab', 32),
            targetArch: Arch::Arm64,
            environment: 'production',
            schemaFingerprint: SchemaFingerprint::empty(),
            createdAt: new \DateTimeImmutable(),
        );

        $state = new CurrentDeployState(
            activeColor: ActiveColor::Blue,
            currentDigest: 'sha256:' . str_repeat('ab', 32),
            appliedFingerprint: SchemaFingerprint::empty(),
        );

        return new PreflightContext($definition, $manifest, $state, new EnvironmentName('production'));
    }
}
