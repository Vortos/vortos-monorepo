<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Provision;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Provision\FirstDeployProvisioner;

/**
 * G4: the first-deploy provisioning plan is idempotent and correctly ordered — generate JWT keys
 * only when absent, then migrate, then the fail-closed secrets preflight.
 */
final class FirstDeployProvisionerTest extends TestCase
{
    /** @return list<string> */
    private function commands(bool $keysPresent): array
    {
        $steps = (new FirstDeployProvisioner())->plan($keysPresent, '/etc/vortos/keys', 'production');

        return array_map(static fn ($s): string => $s->command, $steps);
    }

    public function test_generates_keys_when_absent(): void
    {
        $commands = $this->commands(false);

        self::assertSame(
            ['vortos:auth:keys:generate', 'vortos:migrate', 'secrets:preflight'],
            $commands,
        );
    }

    public function test_skips_key_generation_when_present_idempotent(): void
    {
        $commands = $this->commands(true);

        self::assertSame(['vortos:migrate', 'secrets:preflight'], $commands);
        self::assertNotContains('vortos:auth:keys:generate', $commands);
    }

    public function test_key_generation_targets_the_configured_output_dir(): void
    {
        $steps = (new FirstDeployProvisioner())->plan(false, '/etc/vortos/keys', 'staging');

        self::assertSame(['--out=/etc/vortos/keys'], $steps[0]->args);
    }

    public function test_migration_runs_non_interactively(): void
    {
        $steps = (new FirstDeployProvisioner())->plan(true, '/k', 'production');

        self::assertSame('vortos:migrate', $steps[0]->command);
        self::assertContains('--force', $steps[0]->args, 'Migration must not prompt during an automated deploy.');
    }

    public function test_preflight_targets_the_environment_and_runs_last(): void
    {
        $steps = (new FirstDeployProvisioner())->plan(true, '/k', 'production');
        $last = $steps[array_key_last($steps)];

        self::assertSame('secrets:preflight', $last->command);
        self::assertSame(['--env=production'], $last->args);
    }
}
