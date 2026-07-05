<?php

declare(strict_types=1);

namespace Vortos\Docker\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the production compose/Dockerfile stubs against the regressions the first prod deploy hit:
 *   B2  — prod `write_db` / `read_db` must carry the POSTGRES / MONGO env mapping (else the image
 *         cannot initialise from `.env.prod`'s VORTOS_ names).
 *   G2  — the frankenphp prod compose must reference a pulled, self-contained image (not `build:`)
 *         and must NOT bind-mount the working tree over the baked code; the Dockerfile must expose a
 *         self-contained `app` stage.
 */
final class ProdComposeStubTest extends TestCase
{
    private const STUBS = __DIR__ . '/../stubs';

    private function read(string $relative): string
    {
        $path = self::STUBS . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return list<array{0: string}> */
    public static function runtimes(): array
    {
        return [['frankenphp'], ['phpfpm']];
    }

    #[DataProvider('runtimes')]
    public function test_prod_write_db_maps_postgres_env(string $runtime): void
    {
        $compose = $this->read($runtime . '/docker-compose.prod.yaml');

        self::assertStringContainsString('POSTGRES_PASSWORD: ${VORTOS_WRITE_DB_PASSWORD}', $compose);
        self::assertStringContainsString('POSTGRES_DB:       ${VORTOS_WRITE_DB_NAME}', $compose);
    }

    #[DataProvider('runtimes')]
    public function test_prod_read_db_maps_mongo_env(string $runtime): void
    {
        $compose = $this->read($runtime . '/docker-compose.prod.yaml');

        self::assertStringContainsString('MONGO_INITDB_ROOT_PASSWORD: ${VORTOS_READ_DB_PASSWORD}', $compose);
    }

    public function test_frankenphp_prod_never_builds_on_the_box(): void
    {
        // G2: the prod baseline must never build on the box. (The app image ref itself now lives in
        // the cutover compose (ComposeFile), pulled by digest — the prod baseline carries only infra
        // + edge + socket-proxy.)
        $compose = $this->read('frankenphp/docker-compose.prod.yaml');

        self::assertStringNotContainsString('build:', $compose);
    }

    public function test_frankenphp_prod_does_not_bind_mount_the_working_tree_over_code(): void
    {
        $compose = $this->read('frankenphp/docker-compose.prod.yaml');

        self::assertStringNotContainsString('./:/var/www/html', $compose);
    }

    public function test_frankenphp_dockerfile_has_self_contained_app_stage(): void
    {
        $dockerfile = $this->read('frankenphp/docker/php/Dockerfile');

        self::assertStringContainsString('AS app', $dockerfile);
        self::assertStringContainsString('composer install --no-dev', $dockerfile);
        self::assertStringContainsString('COPY . .', $dockerfile);
    }

    // ── B12: the shared app network must be EXTERNAL (the deploy tooling requires it) ──

    #[DataProvider('runtimes')]
    public function test_prod_vortos_net_is_external(string $runtime): void
    {
        $compose = $this->read($runtime . '/docker-compose.prod.yaml');

        // vortos-net must be external:true to match ComposeFile/EdgeConfigGenerator (B12).
        self::assertMatchesRegularExpression('/vortos-net:\s*\n\s*external:\s*true/', $compose);
        self::assertStringNotContainsString('driver: bridge', $compose);
    }

    // ── B16 part 1: a least-privilege docker-socket-proxy, never the raw socket in the app image ──

    #[DataProvider('runtimes')]
    public function test_prod_has_least_privilege_socket_proxy(string $runtime): void
    {
        $compose = $this->read($runtime . '/docker-compose.prod.yaml');

        self::assertStringContainsString('docker-socket-proxy:', $compose);
        self::assertStringContainsString('tecnativa/docker-socket-proxy', $compose);
        // Raw socket mounted read-only (on the proxy).
        self::assertStringContainsString('/var/run/docker.sock:/var/run/docker.sock:ro', $compose);
        // Dangerous endpoints denied; only the cutover-necessary ones allowed.
        self::assertStringContainsString('BUILD: "0"', $compose);
        self::assertStringContainsString('VOLUMES: "0"', $compose);
        self::assertStringContainsString('SECRETS: "0"', $compose);
        self::assertStringContainsString('CONTAINERS: "1"', $compose);
    }

    #[DataProvider('runtimes')]
    public function test_only_the_socket_proxy_mounts_the_raw_docker_socket(string $runtime): void
    {
        $compose = $this->read($runtime . '/docker-compose.prod.yaml');

        // The socket mount line must appear exactly once — no app/infra service may mount it.
        self::assertSame(
            1,
            substr_count($compose, 'docker.sock:/var/run/docker.sock'),
            'The raw docker socket must be mounted only by docker-socket-proxy.',
        );
    }

    // ── B16 topology (frankenphp): edge owns 80/443; the app is NOT a prod-compose service ──

    public function test_frankenphp_edge_owns_80_443_and_app_is_deploy_managed(): void
    {
        $compose = $this->read('frankenphp/docker-compose.prod.yaml');

        self::assertStringContainsString('edge:', $compose);
        self::assertStringContainsString('"80:80"', $compose);
        self::assertStringContainsString('"443:443"', $compose);

        // No app/worker service here — the colors are created by the deploy cutover.
        self::assertDoesNotMatchRegularExpression('/\n  backend:/', $compose);
        self::assertDoesNotMatchRegularExpression('/\n  worker:/', $compose);

        // The image ref is now carried by the cutover compose (ComposeFile), not the prod baseline.
        self::assertStringNotContainsString('VORTOS_IMAGE_REF', $compose);

        // Only one 80:80 / 443:443 publish (the edge's).
        self::assertSame(1, substr_count($compose, '"80:80"'));
    }

    // ── B11: entrypoint stubs must invoke the command the framework actually registers ──

    #[DataProvider('runtimes')]
    public function test_entrypoint_invokes_registered_cache_warmup_command(string $runtime): void
    {
        $entrypoint = $this->read($runtime . '/docker/php/entrypoint.sh');

        self::assertStringContainsString('vortos:cache:warmup', $entrypoint);
        // The framework registers `vortos:cache:warmup`, not the bare `cache:warmup` the old stub ran.
        self::assertDoesNotMatchRegularExpression('/console\s+cache:warmup(\s|$)/', $entrypoint);
    }

    // ── B16 part 1: the self-contained image ships a docker client for the cutover ──

    public function test_frankenphp_app_image_has_docker_cli_for_cutover(): void
    {
        $dockerfile = $this->read('frankenphp/docker/php/Dockerfile');

        self::assertMatchesRegularExpression('/COPY --from=docker:[^\s]+ .*\/docker /', $dockerfile);
        self::assertStringContainsString('cli-plugins/docker-compose', $dockerfile);
    }
}
