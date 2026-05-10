<?php

declare(strict_types=1);

namespace Vortos\Tests\Docker;

use PHPUnit\Framework\TestCase;
use Vortos\Docker\Service\DockerFilePublisher;

final class DockerFilePublisherTest extends TestCase
{
    private string $projectDir;
    private string $stubRoot;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/vortos_docker_project_' . uniqid('', true);
        $this->stubRoot = sys_get_temp_dir() . '/vortos_docker_stubs_' . uniqid('', true);

        mkdir($this->projectDir, 0777, true);
        mkdir($this->stubRoot . '/phpfpm/docker/php', 0777, true);
        file_put_contents($this->stubRoot . '/phpfpm/docker-compose.yaml', 'services: []' . PHP_EOL);
        file_put_contents($this->stubRoot . '/phpfpm/docker/php/Dockerfile', 'FROM php' . PHP_EOL);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
        $this->removeDirectory($this->stubRoot);
    }

    public function test_publish_copies_files_and_skips_unchanged_files(): void
    {
        $publisher = new DockerFilePublisher($this->stubRoot);

        $first = $publisher->publish('phpfpm', $this->projectDir);
        $second = $publisher->publish('phpfpm', $this->projectDir);

        $this->assertCount(2, $first->copied);
        $this->assertCount(2, $second->skipped);
    }

    public function test_publish_can_skip_existing_changed_files_when_overwrite_disabled(): void
    {
        file_put_contents($this->projectDir . '/docker-compose.yaml', 'custom' . PHP_EOL);

        $result = (new DockerFilePublisher($this->stubRoot))->publish(
            'phpfpm',
            $this->projectDir,
            overwrite: false,
        );

        $this->assertContains('docker-compose.yaml', $result->skipped);
        $this->assertSame('custom' . PHP_EOL, file_get_contents($this->projectDir . '/docker-compose.yaml'));
    }

    public function test_dev_compose_stubs_load_env_file(): void
    {
        $stubRoot = dirname(__DIR__, 2) . '/src/Docker/stubs';

        foreach (['frankenphp', 'phpfpm'] as $runtime) {
            $compose = (string) file_get_contents($stubRoot . '/' . $runtime . '/docker-compose.yaml');

            $this->assertStringContainsString(
                "env_file:\n      - ./.env",
                $compose,
                sprintf('%s docker-compose.yaml must load .env.', $runtime),
            );
        }
    }

    public function test_publish_can_remove_disabled_optional_services_from_compose(): void
    {
        $stubRoot = dirname(__DIR__, 2) . '/src/Docker/stubs';
        $publisher = new DockerFilePublisher($stubRoot);

        $publisher->publish('frankenphp', $this->projectDir, options: [
            'services' => [
                'read_db' => false,
                'redis' => false,
                'kafka' => false,
                'worker' => false,
            ],
        ]);

        $compose = (string) file_get_contents($this->projectDir . '/docker-compose.yaml');

        $this->assertStringNotContainsString('  read_db:', $compose);
        $this->assertStringNotContainsString('  redis:', $compose);
        $this->assertStringNotContainsString('  kafka:', $compose);
        $this->assertStringNotContainsString('  worker:', $compose);
        $this->assertStringNotContainsString('read_db_data:', $compose);
        $this->assertStringNotContainsString('redis_data:', $compose);
        $this->assertStringContainsString('  write_db:', $compose);
        $this->assertStringContainsString('  backend:', $compose);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
