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

    public function test_frankenphp_prod_uses_pulled_image_not_build(): void
    {
        $compose = $this->read('frankenphp/docker-compose.prod.yaml');

        self::assertStringContainsString('image: ${VORTOS_IMAGE_REF', $compose);
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
}
