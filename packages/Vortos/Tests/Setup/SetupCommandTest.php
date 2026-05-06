<?php

declare(strict_types=1);

namespace Vortos\Tests\Setup;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Docker\Service\DockerFilePublisher;
use Vortos\Setup\Command\SetupCommand;
use Vortos\Setup\Service\EnvironmentFileWriter;
use Vortos\Setup\Service\SetupEnvironmentChecker;
use Vortos\Setup\Service\SetupStateStore;

final class SetupCommandTest extends TestCase
{
    private string $projectDir;
    private string $stubRoot;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/vortos_setup_project_' . uniqid('', true);
        $this->stubRoot = sys_get_temp_dir() . '/vortos_setup_stubs_' . uniqid('', true);

        mkdir($this->projectDir, 0777, true);
        mkdir($this->stubRoot . '/frankenphp/docker/php', 0777, true);
        file_put_contents($this->stubRoot . '/frankenphp/docker-compose.yaml', 'services: []' . PHP_EOL);
        file_put_contents($this->stubRoot . '/frankenphp/docker/php/Dockerfile', 'FROM php' . PHP_EOL);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
        $this->removeDirectory($this->stubRoot);
    }

    public function test_dry_run_does_not_write_files(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--preset' => 'local',
            '--dry-run' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileDoesNotExist($this->projectDir . '/.env.local');
        $this->assertFileDoesNotExist($this->projectDir . '/.vortos-setup.json');
    }

    public function test_dry_run_uses_compact_output_instead_of_wide_tables(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--preset' => 'local',
            '--dry-run' => true,
            '--no-interaction' => true,
        ]);

        $output = $tester->getDisplay();

        $this->assertStringContainsString('Environment plan', $output);
        $this->assertStringContainsString('Setup plan (dry run)', $output);
        $this->assertStringContainsString('Written:', $output);
        $this->assertStringContainsString('Do not commit .env.local or .vortos-setup.json.', $output);
        $this->assertStringNotContainsString('Written   Updated   Unchanged', $output);
    }

    public function test_minimal_profile_uses_local_in_memory_defaults(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--profile' => 'minimal',
            '--no-interaction' => true,
        ]);

        $env = (string) file_get_contents($this->projectDir . '/.env.local');
        $state = json_decode((string) file_get_contents($this->projectDir . '/.vortos-setup.json'), true);

        $this->assertSame('minimal', $state['profile']);
        $this->assertSame('minimal', $state['preset']);
        $this->assertSame('in-memory', $this->envValue($env, 'VORTOS_CACHE_DRIVER'));
        $this->assertSame('in-memory', $this->envValue($env, 'VORTOS_MESSAGING_DRIVER'));
    }

    public function test_docker_profile_uses_frankenphp_docker_defaults(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--profile' => 'docker',
            '--skip-docker-publish' => true,
            '--no-interaction' => true,
        ]);

        $state = json_decode((string) file_get_contents($this->projectDir . '/.vortos-setup.json'), true);

        $this->assertSame('docker', $state['profile']);
        $this->assertSame('docker-frankenphp', $state['preset']);
        $this->assertTrue($state['docker']);
    }

    public function test_invalid_profile_returns_failure_without_writing_files(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--profile' => 'unknown',
            '--no-interaction' => true,
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Unknown setup profile "unknown".', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->projectDir . '/.env.local');
    }

    public function test_custom_profile_requires_interactive_mode(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--profile' => 'custom',
        ], ['interactive' => false]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('The custom profile requires interactive setup.', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->projectDir . '/.env.local');
    }

    public function test_setup_is_idempotent_and_preserves_generated_secrets(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--preset' => 'local',
            '--no-interaction' => true,
        ]);

        $firstEnv = (string) file_get_contents($this->projectDir . '/.env.local');
        $this->assertStringContainsString('VORTOS_CACHE_DRIVER=in-memory', $firstEnv);
        $this->assertFileExists($this->projectDir . '/.vortos-setup.json');

        $tester = $this->tester();
        $tester->execute([
            '--preset' => 'local',
            '--no-interaction' => true,
        ]);

        $secondEnv = (string) file_get_contents($this->projectDir . '/.env.local');
        $this->assertSame($this->envValue($firstEnv, 'JWT_SECRET'), $this->envValue($secondEnv, 'JWT_SECRET'));
        $this->assertSame($this->envValue($firstEnv, 'HEALTH_TOKEN'), $this->envValue($secondEnv, 'HEALTH_TOKEN'));
        $this->assertSame($this->envValue($firstEnv, 'POSTGRES_PASSWORD'), $this->envValue($secondEnv, 'POSTGRES_PASSWORD'));
        $this->assertSame($this->envValue($firstEnv, 'MONGO_INITDB_ROOT_PASSWORD'), $this->envValue($secondEnv, 'MONGO_INITDB_ROOT_PASSWORD'));
    }

    public function test_setup_generates_local_passwords_and_sections_env_file(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--preset' => 'docker-frankenphp',
            '--no-interaction' => true,
            '--skip-docker-publish' => true,
        ]);

        $env = (string) file_get_contents($this->projectDir . '/.env.local');

        $this->assertStringContainsString('### Vortos setup', $env);
        $this->assertStringContainsString('### Generated locally. Do not commit this file.', $env);
        $this->assertStringContainsString('### Security', $env);
        $this->assertStringContainsString('### Database', $env);
        $this->assertNotContains($this->envValue($env, 'POSTGRES_PASSWORD'), ['12345', 'password', 'postgres', 'secret']);
        $this->assertNotContains($this->envValue($env, 'MONGO_INITDB_ROOT_PASSWORD'), ['12345', 'password', 'postgres', 'secret']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $this->envValue($env, 'POSTGRES_PASSWORD'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $this->envValue($env, 'MONGO_INITDB_ROOT_PASSWORD'));
    }

    public function test_regenerate_secrets_replaces_existing_secrets(): void
    {
        $tester = $this->tester();
        $tester->execute([
            '--preset' => 'local',
            '--no-interaction' => true,
        ]);

        $firstEnv = (string) file_get_contents($this->projectDir . '/.env.local');

        $tester = $this->tester();
        $tester->execute([
            '--preset' => 'local',
            '--regenerate-secrets' => true,
            '--no-interaction' => true,
        ]);

        $secondEnv = (string) file_get_contents($this->projectDir . '/.env.local');

        $this->assertNotSame($this->envValue($firstEnv, 'JWT_SECRET'), $this->envValue($secondEnv, 'JWT_SECRET'));
        $this->assertNotSame($this->envValue($firstEnv, 'HEALTH_TOKEN'), $this->envValue($secondEnv, 'HEALTH_TOKEN'));
        $this->assertNotSame($this->envValue($firstEnv, 'POSTGRES_PASSWORD'), $this->envValue($secondEnv, 'POSTGRES_PASSWORD'));
    }

    public function test_setup_derives_project_name_for_environment_defaults(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--preset' => 'docker-frankenphp',
            '--no-interaction' => true,
            '--skip-docker-publish' => true,
        ]);

        $env = (string) file_get_contents($this->projectDir . '/.env.local');
        $projectName = $this->expectedProjectName();

        $this->assertSame($projectName, $this->envValue($env, 'APP_NAME'));
        $this->assertSame($projectName, $this->envValue($env, 'POSTGRES_DB'));
        $this->assertSame($projectName, $this->envValue($env, 'POSTGRES_DB_NAME'));
        $this->assertSame($projectName, $this->envValue($env, 'MONGO_DB_NAME'));
        $this->assertSame('dev_' . $projectName . '_', $this->envValue($env, 'VORTOS_CACHE_PREFIX'));
        $this->assertStringContainsString('@write_db:5432/' . $projectName, $this->envValue($env, 'DATABASE_URL'));
    }

    public function test_setup_ignores_skeleton_app_name_placeholder(): void
    {
        file_put_contents($this->projectDir . '/.env', 'APP_NAME=myapp' . PHP_EOL);

        $tester = $this->tester();
        $tester->execute([
            '--preset' => 'local',
            '--no-interaction' => true,
        ]);

        $env = (string) file_get_contents($this->projectDir . '/.env.local');
        $projectName = $this->expectedProjectName();

        $this->assertSame($projectName, $this->envValue($env, 'APP_NAME'));
        $this->assertSame($projectName, $this->envValue($env, 'POSTGRES_DB_NAME'));
    }

    public function test_existing_app_name_is_preserved_on_setup(): void
    {
        file_put_contents($this->projectDir . '/.env', 'APP_NAME=custom_app' . PHP_EOL);

        $tester = $this->tester();
        $tester->execute([
            '--preset' => 'local',
            '--no-interaction' => true,
        ]);

        $env = (string) file_get_contents($this->projectDir . '/.env.local');

        $this->assertSame('custom_app', $this->envValue($env, 'APP_NAME'));
        $this->assertSame('custom_app', $this->envValue($env, 'POSTGRES_DB_NAME'));
    }

    public function test_docker_preset_publishes_docker_files_with_backup_on_rerun(): void
    {
        file_put_contents($this->projectDir . '/docker-compose.yaml', 'custom' . PHP_EOL);

        $tester = $this->tester();
        $tester->execute([
            '--preset' => 'docker-frankenphp',
            '--no-interaction' => true,
        ]);

        $this->assertStringContainsString('services: []', (string) file_get_contents($this->projectDir . '/docker-compose.yaml'));
        $this->assertNotEmpty(glob($this->projectDir . '/docker-compose.yaml.bak.*') ?: []);
        $this->assertFileExists($this->projectDir . '/docker/php/Dockerfile');
    }

    public function test_local_profile_does_not_publish_docker_files(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--profile' => 'minimal',
            '--no-interaction' => true,
        ]);

        $this->assertFileDoesNotExist($this->projectDir . '/docker-compose.yaml');
        $this->assertFileDoesNotExist($this->projectDir . '/docker/php/Dockerfile');
    }

    public function test_docker_publish_can_skip_existing_changed_files_from_setup(): void
    {
        file_put_contents($this->projectDir . '/docker-compose.yaml', 'custom' . PHP_EOL);

        $tester = $this->tester();
        $tester->execute([
            '--preset' => 'docker-frankenphp',
            '--no-docker-overwrite' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame('custom' . PHP_EOL, file_get_contents($this->projectDir . '/docker-compose.yaml'));
        $this->assertEmpty(glob($this->projectDir . '/docker-compose.yaml.bak.*') ?: []);
        $this->assertStringContainsString('Skipped: 1', $tester->getDisplay());
    }

    public function test_environment_checker_normalizes_project_path(): void
    {
        mkdir($this->projectDir . '/bootstrap', 0777, true);

        $checks = (new SetupEnvironmentChecker($this->projectDir . '/bootstrap/..'))->check(false, false, false, false);

        $writable = array_values(array_filter(
            $checks,
            static fn(array $check): bool => $check['name'] === 'Writable project',
        ))[0];

        $this->assertSame($this->projectDir, $writable['detail']);
    }

    public function test_interactive_setup_can_be_cancelled_before_writes(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['minimal', 'Cancel']);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Setup cancelled', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->projectDir . '/.env.local');
        $this->assertFileDoesNotExist($this->projectDir . '/.vortos-setup.json');
    }

    public function test_interactive_setup_can_customize_from_profile_review(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['docker', 'Customize', 'local', 'Continue']);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $state = json_decode((string) file_get_contents($this->projectDir . '/.vortos-setup.json'), true);

        $this->assertSame('custom', $state['profile']);
        $this->assertSame('local', $state['preset']);
    }

    private function tester(): CommandTester
    {
        $command = new SetupCommand(
            $this->projectDir,
            new SetupStateStore($this->projectDir),
            new EnvironmentFileWriter($this->projectDir),
            new SetupEnvironmentChecker($this->projectDir),
            new DockerFilePublisher($this->stubRoot),
        );

        $application = new Application();
        $application->add($command);

        return new CommandTester($application->find('vortos:setup'));
    }

    private function envValue(string $env, string $key): string
    {
        preg_match('/^' . preg_quote($key, '/') . '=(.+)$/m', $env, $match);

        return $match[1] ?? '';
    }

    private function expectedProjectName(): string
    {
        $name = strtolower(basename($this->projectDir));
        $name = (string) preg_replace('/[^a-z0-9]+/', '_', $name);

        return trim($name, '_');
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
