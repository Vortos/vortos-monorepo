<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Builder\RemoteDeployScript;
use Vortos\Pipeline\Definition\PipelineDefinition;

/**
 * G1 + B9: the deploy-in-image commands run ON the VPS (in the app network, on the arm host), never
 * on the infra-less amd64 runner.
 */
final class RemoteDeployScriptTest extends TestCase
{
    private function script(PipelineDefinition $definition): string
    {
        return (new RemoteDeployScript())->build(
            $definition,
            'ghcr.io/acme/app@${{ needs.build.outputs.image }}',
            'ghcr.io/acme/app',
            '${{ needs.build.outputs.image }}',
            '${{ matrix.environment }}',
        );
    }

    private function definition(bool $oidc): PipelineDefinition
    {
        return new PipelineDefinition(
            environments: ['production'],
            imageRepository: 'ghcr.io/acme/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            oidc: $oidc,
            remoteDeployDir: '/opt/vortos',
            appNetwork: 'vortos-net',
        );
    }

    public function test_commands_run_on_the_app_network_reaching_prod_state(): void
    {
        $script = $this->script($this->definition(true));

        // Every console command runs via `docker run ... --network vortos-net` so the release-ledger
        // DB (a service on that network) is reachable — the crux of G1.
        self::assertStringContainsString('--network vortos-net', $script);
        self::assertStringContainsString('--env-file /opt/vortos/.env.prod', $script);
    }

    public function test_pulls_and_runs_the_pinned_image(): void
    {
        $script = $this->script($this->definition(true));

        self::assertStringContainsString('docker pull ghcr.io/acme/app@${{ needs.build.outputs.image }}', $script);
        self::assertStringContainsString('ghcr.io/acme/app@${{ needs.build.outputs.image }} php bin/console', $script);
    }

    public function test_records_manifest_before_deploying_and_provisions_first(): void
    {
        $script = $this->script($this->definition(true));

        $recordPos = strpos($script, 'vortos:release:record-manifest');
        $provisionPos = strpos($script, 'vortos:deploy:provision');
        $doctorPos = strpos($script, 'deploy:doctor');
        $deployPos = strpos($script, "php bin/console deploy --env=");

        self::assertIsInt($recordPos);
        self::assertIsInt($provisionPos);
        self::assertIsInt($doctorPos);
        self::assertIsInt($deployPos);
        self::assertLessThan($provisionPos, $recordPos);
        self::assertLessThan($doctorPos, $provisionPos);
        self::assertLessThan($deployPos, $doctorPos);
    }

    public function test_record_manifest_carries_arch_repo_and_digest(): void
    {
        $script = $this->script($this->definition(true));

        self::assertStringContainsString('--repository=ghcr.io/acme/app', $script);
        self::assertStringContainsString('--digest=${{ needs.build.outputs.image }}', $script);
        self::assertStringContainsString('--arch=linux/arm64', $script);
        self::assertStringContainsString('--git-sha=${{ github.sha }}', $script);
    }

    public function test_ssh_key_posture_forwards_age_kek_and_mounts_delivered_store(): void
    {
        $script = $this->script($this->definition(false));

        self::assertStringContainsString('export VORTOS_AGE_IDENTITY=\'${{ secrets.VORTOS_AGE_IDENTITY }}\'', $script);
        self::assertStringContainsString('-e VORTOS_AGE_IDENTITY', $script);
        self::assertStringContainsString('-v /opt/vortos/vortos-secrets.age:/app/vortos-secrets.age:ro', $script);
    }

    public function test_oidc_posture_is_free_of_standing_secrets(): void
    {
        $script = $this->script($this->definition(true));

        self::assertStringNotContainsString('VORTOS_AGE_IDENTITY', $script);
        self::assertStringNotContainsString('vortos-secrets.age', $script);
        // Only GITHUB_TOKEN (for the VPS registry pull) is permitted under OIDC.
        preg_match_all('/secrets\.(\w+)/', $script, $m);
        foreach ($m[1] as $name) {
            self::assertSame('GITHUB_TOKEN', $name);
        }
    }
}
