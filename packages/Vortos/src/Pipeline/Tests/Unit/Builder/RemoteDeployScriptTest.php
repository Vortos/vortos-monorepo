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

    private function definition(bool $oidc, string $registryProvider = 'ghcr'): PipelineDefinition
    {
        return new PipelineDefinition(
            environments: ['production'],
            imageRepository: 'ghcr.io/acme/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            oidc: $oidc,
            registryProvider: $registryProvider,
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

    public function test_provisions_before_recording_manifest_then_doctor_then_deploy(): void
    {
        // G9: provision (which runs vortos:migrate) must precede record-manifest — the ledger table
        // record-manifest writes to is created by that migration. On a fresh DB the old order
        // (record first) died with "relation ... does not exist".
        $script = $this->script($this->definition(true));

        $provisionPos = strpos($script, 'vortos:deploy:provision');
        $recordPos = strpos($script, 'vortos:release:record-manifest');
        $doctorPos = strpos($script, 'deploy:doctor');
        $deployPos = strpos($script, "php bin/console deploy --env=");

        self::assertIsInt($provisionPos);
        self::assertIsInt($recordPos);
        self::assertIsInt($doctorPos);
        self::assertIsInt($deployPos);
        self::assertLessThan($recordPos, $provisionPos);
        self::assertLessThan($doctorPos, $recordPos);
        self::assertLessThan($deployPos, $doctorPos);
    }

    public function test_one_shots_reach_docker_only_through_the_socket_proxy(): void
    {
        // B16: the cutover shells docker compose from inside the one-shot; it must reach Docker via
        // the least-privilege proxy (DOCKER_HOST), never a raw socket.
        $script = $this->script($this->definition(true));

        self::assertStringContainsString('-e DOCKER_HOST=tcp://docker-socket-proxy:2375', $script);
        self::assertStringNotContainsString('/var/run/docker.sock', $script);
    }

    public function test_ssh_key_posture_grants_the_container_the_store_group(): void
    {
        // B15: the 0640 store is group-readable; the container gets that group via --group-add so it
        // can read the delivered store without a world-readable file.
        $script = $this->script($this->definition(false));

        self::assertStringContainsString("VORTOS_SECRETS_GID=\"\$(stat -c '%g' /opt/vortos/vortos-secrets.age)\"", $script);
        self::assertStringContainsString('--group-add "$VORTOS_SECRETS_GID"', $script);
    }

    public function test_docker_hub_registry_gets_a_real_remote_login(): void
    {
        // G7: docker-hub was previously assumed pre-authenticated; now it gets a real remote login.
        $script = $this->script($this->definition(true, 'docker-hub'));

        self::assertStringContainsString('docker login docker.io', $script);
        self::assertStringContainsString('secrets.DOCKER_TOKEN', $script);
        self::assertStringContainsString('secrets.DOCKER_USERNAME', $script);
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

    public function test_runtime_env_files_are_bind_mounted_read_only_into_the_one_shot(): void
    {
        // B19: the nested cutover compose references env_file: <runtime paths>, resolved INSIDE the
        // one-shot. Each declared runtime env-file must be bind-mounted read-only at its same absolute
        // path so `docker compose up` for the color can read it (otherwise "env file ... not found").
        $script = $this->script($this->definition(true));

        self::assertStringContainsString('-v /opt/vortos/.env.prod:/opt/vortos/.env.prod:ro', $script);
    }

    public function test_multiple_runtime_env_files_each_mounted_and_deduped(): void
    {
        $definition = new PipelineDefinition(
            environments: ['production'],
            imageRepository: 'ghcr.io/acme/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            oidc: true,
            remoteDeployDir: '/opt/vortos',
            appNetwork: 'vortos-net',
            runtimeEnvFiles: ['/opt/vortos/.env.prod', '/etc/app/secrets.env', '/opt/vortos/.env.prod'],
        );

        $script = $this->script($definition);

        self::assertStringContainsString('-v /opt/vortos/.env.prod:/opt/vortos/.env.prod:ro', $script);
        self::assertStringContainsString('-v /etc/app/secrets.env:/etc/app/secrets.env:ro', $script);
        // The docker-run template is reused across all console commands, so each mount repeats once
        // per command. The duplicate .env.prod entry must not double it: its count must equal the
        // count of the unique secrets.env mount.
        self::assertSame(
            substr_count($script, '-v /etc/app/secrets.env:/etc/app/secrets.env:ro'),
            substr_count($script, '-v /opt/vortos/.env.prod:/opt/vortos/.env.prod:ro'),
            'a duplicate env-file path is not mounted twice per docker run',
        );
    }

    public function test_relative_runtime_env_file_paths_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PipelineDefinition(
            environments: ['production'],
            imageRepository: 'ghcr.io/acme/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            oidc: true,
            runtimeEnvFiles: ['relative/.env'],
        );
    }

    public function test_file_secrets_are_created_mounted_and_materialized_before_cutover(): void
    {
        // G8: tmpfs dirs are created (0700), bind-mounted RW into the one-shot, and the file secrets
        // are materialized right before the cutover deploy so the color's compose mounts find them.
        $definition = new PipelineDefinition(
            environments: ['production'],
            imageRepository: 'ghcr.io/acme/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            oidc: true,
            remoteDeployDir: '/opt/vortos',
            appNetwork: 'vortos-net',
            runtimeFileSecretDirs: ['/run/vortos-secrets'],
        );

        $script = $this->script($definition);

        self::assertStringContainsString('mkdir -p /run/vortos-secrets && chmod 700 /run/vortos-secrets', $script);
        self::assertStringContainsString('-v /run/vortos-secrets:/run/vortos-secrets ', $script);
        self::assertStringContainsString('vortos:deploy:materialize-file-secrets --env=', $script);

        $materializePos = strpos($script, 'vortos:deploy:materialize-file-secrets');
        $deployPos = strpos($script, 'php bin/console deploy --env=');
        self::assertIsInt($materializePos);
        self::assertIsInt($deployPos);
        self::assertLessThan($deployPos, $materializePos, 'file secrets must be materialized before cutover');
    }

    public function test_no_file_secret_plumbing_when_none_declared(): void
    {
        $script = $this->script($this->definition(true));

        self::assertStringNotContainsString('materialize-file-secrets', $script);
        self::assertStringNotContainsString('mkdir -p /run', $script);
    }

    public function test_non_tmpfs_file_secret_dir_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PipelineDefinition(
            environments: ['production'],
            imageRepository: 'ghcr.io/acme/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            oidc: true,
            runtimeFileSecretDirs: ['/opt/vortos/secrets'], // not tmpfs
        );
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
