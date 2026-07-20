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

    private function definition(bool $oidc, string $registryProvider = 'ghcr', ?string $sealedEnvFile = null): PipelineDefinition
    {
        return new PipelineDefinition(
            environments: ['production'],
            imageRepository: 'ghcr.io/acme/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            oidc: $oidc,
            registryProvider: $registryProvider,
            remoteDeployDir: '/opt/vortos',
            appNetwork: 'vortos-net',
            sealedEnvFile: $sealedEnvFile,
        );
    }

    public function test_no_sealed_env_materialization_by_default(): void
    {
        $script = $this->script($this->definition(false));

        self::assertStringNotContainsString('open-env.php', $script);
    }

    public function test_materializes_sealed_env_before_any_env_consuming_command(): void
    {
        $script = $this->script($this->definition(false, sealedEnvFile: 'deploy/secrets/env.prod.sealed'));

        // Emits the decrypt one-shot: no kernel boot, identity forwarded, deploy dir mounted rw, runs the
        // app's reveal script over the sealed blob → .env.prod.
        self::assertStringContainsString(
            '--user 0:0 --entrypoint php -e VORTOS_AGE_IDENTITY -v /opt/vortos:/opt/vortos ghcr.io/acme/app@${{ needs.build.outputs.image }} '
            . 'deploy/secrets/open-env.php deploy/secrets/env.prod.sealed /opt/vortos/.env.prod',
            $script,
        );

        // ...and it runs BEFORE the first command that reads --env-file .env.prod (migrate:analyze).
        $revealPos = strpos($script, 'open-env.php');
        $analyzePos = strpos($script, 'vortos:migrate:analyze');
        self::assertIsInt($revealPos);
        self::assertIsInt($analyzePos);
        self::assertLessThan($analyzePos, $revealPos, 'sealed env must be materialized before any command reads it');
    }

    public function test_sealed_env_materialization_skipped_under_oidc(): void
    {
        // OIDC posture forwards no identity, so the sealed env cannot be opened — the step is omitted.
        $script = $this->script($this->definition(true, sealedEnvFile: 'deploy/secrets/env.prod.sealed'));

        self::assertStringNotContainsString('open-env.php', $script);
    }

    public function test_commands_run_on_the_app_network_reaching_prod_state(): void
    {
        $script = $this->script($this->definition(true));

        // Every console command runs via `docker run ... --network vortos-net` so the release-ledger
        // DB (a service on that network) is reachable — the crux of G1.
        self::assertStringContainsString('--network vortos-net', $script);
        self::assertStringContainsString('--env-file /opt/vortos/.env.prod', $script);
    }

    public function test_bind_mounts_edge_dir_for_boot_file_and_reconcile(): void
    {
        $script = $this->script($this->definition(true));

        // The on-box one-shot persists the edge boot config AND reconciles the edge compose via local
        // writes; that needs the host EDGE_DIR bind-mounted read-write so those writes land on the box.
        // EDGE_DIR is read from the delivered env, falling back to the parent of EDGE_CONFIG_DIR; the
        // ${..:+} expansion keeps the mount off when neither is set.
        self::assertStringContainsString(
            "VORTOS_EDGE_CONFIG_DIR=\"\$(sed -n 's/^EDGE_CONFIG_DIR=//p' /opt/vortos/.env.prod 2>/dev/null | tail -n1 || true)\"",
            $script,
        );
        self::assertStringContainsString('VORTOS_EDGE_DIR="${VORTOS_EDGE_DIR:-${VORTOS_EDGE_CONFIG_DIR%/*}}"', $script);
        self::assertStringContainsString('${VORTOS_EDGE_DIR:+-v "$VORTOS_EDGE_DIR:$VORTOS_EDGE_DIR" }', $script);
    }

    public function test_pulls_and_runs_the_pinned_image(): void
    {
        $script = $this->script($this->definition(true));

        self::assertStringContainsString('docker pull ghcr.io/acme/app@${{ needs.build.outputs.image }}', $script);
        self::assertStringContainsString('ghcr.io/acme/app@${{ needs.build.outputs.image }} php bin/console', $script);
    }

    public function test_migrate_analyze_gate_runs_before_provision_applies_migrations(): void
    {
        // R8-9 (B3): the destructive-DDL gate must run before any migration is applied (provision).
        $script = $this->script($this->definition(true));

        $analyzePos = strpos($script, 'vortos:migrate:analyze --json');
        $provisionPos = strpos($script, 'vortos:deploy:provision');

        self::assertIsInt($analyzePos, 'analyze gate must be emitted');
        self::assertIsInt($provisionPos);
        self::assertLessThan($provisionPos, $analyzePos, 'analyze must precede the migration-applying provision');
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

    public function test_runtime_env_file_gid_is_group_added_so_the_one_shot_can_read_it(): void
    {
        // GAP-A: the nested `docker compose up` parses env_file: as the image's non-root uid. Each
        // mounted runtime env file's gid must be group-added to the one-shot (like the secrets store)
        // so it can group-read a 0640 file without world-read.
        $script = $this->script($this->definition(true));

        self::assertStringContainsString("VORTOS_ENVFILE_GID_0=\"\$(stat -c '%g' /opt/vortos/.env.prod)\"", $script);
        self::assertStringContainsString('--group-add "$VORTOS_ENVFILE_GID_0"', $script);

        // The gid must be read before the docker run that consumes $groupAdd.
        $statPos = strpos($script, 'VORTOS_ENVFILE_GID_0=');
        $runPos = strpos($script, '--group-add "$VORTOS_ENVFILE_GID_0"');
        self::assertIsInt($statPos);
        self::assertIsInt($runPos);
        self::assertLessThan($runPos, $statPos);
    }

    public function test_each_unique_runtime_env_file_gets_its_own_group_added_gid(): void
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

        // Two unique env files → gid vars 0 and 1, no third for the duplicate.
        self::assertStringContainsString("VORTOS_ENVFILE_GID_0=\"\$(stat -c '%g' /opt/vortos/.env.prod)\"", $script);
        self::assertStringContainsString("VORTOS_ENVFILE_GID_1=\"\$(stat -c '%g' /etc/app/secrets.env)\"", $script);
        self::assertStringNotContainsString('VORTOS_ENVFILE_GID_2', $script);
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

    // ── Pre-cutover command seam ─────────────────────────────────────────────────

    public function test_no_pre_cutover_commands_by_default(): void
    {
        $script = $this->script($this->definition(false));

        $this->assertStringNotContainsString('vortos:search:pg:install', $script);
    }

    /**
     * Package installers whose portable migration cannot express engine-specific DDL (and app-level
     * seeds) must be emittable from the definition. Previously the generated workflow had no seam
     * for them, so they were hand-added to the generated file and silently lost on regeneration —
     * a missing `vortos:search:pg:install` took production down on 2026-07-20.
     */
    public function test_pre_cutover_commands_are_emitted_before_cutover(): void
    {
        $definition = new PipelineDefinition(
            environments: ['production'],
            imageRepository: 'ghcr.io/acme/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            oidc: false,
            remoteDeployDir: '/opt/vortos',
            appNetwork: 'vortos-net',
            preCutoverCommands: ['vortos:search:pg:install', 'vortos:auth:seed --prune'],
        );

        $script = $this->script($definition);

        $install = strpos($script, 'vortos:search:pg:install');
        $seed = strpos($script, 'vortos:auth:seed --prune');
        $provision = strpos($script, 'vortos:deploy:provision');
        $cutover = strpos($script, 'php bin/console deploy --env=');

        $this->assertNotFalse($install);
        $this->assertNotFalse($seed);

        // After provision (the schema they depend on exists) and before cutover (the new color must
        // never serve traffic against a half-installed schema).
        $this->assertGreaterThan($provision, $install);
        $this->assertLessThan($cutover, $install);
        $this->assertLessThan($cutover, $seed);

        // Order within the list is preserved.
        $this->assertLessThan($seed, $install);
    }

    public function test_pre_cutover_commands_reject_shell_metacharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/shell metacharacters/');

        new PipelineDefinition(
            environments: ['production'],
            preCutoverCommands: ['vortos:search:pg:install; rm -rf /'],
        );
    }

    public function test_pre_cutover_commands_reject_console_prefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/console command only/');

        new PipelineDefinition(
            environments: ['production'],
            preCutoverCommands: ['php bin/console vortos:search:pg:install'],
        );
    }
}
