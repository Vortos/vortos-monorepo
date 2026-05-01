<?php

declare(strict_types=1);

namespace Vortos\Tests\Migration\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Foundation\Module\ModulePathResolver;
use Vortos\Migration\Command\MigratePublishCommand;
use Vortos\Migration\Generator\MigrationClassGenerator;
use Vortos\Migration\Service\ModuleStubScanner;

final class MigratePublishCommandTest extends TestCase
{
    private string $tempDir;
    private string $migrationsDir;

    /** @var list<string> */
    private array $registeredModules = [];

    protected function setUp(): void
    {
        $this->tempDir          = sys_get_temp_dir() . '/vortos_publish_test_' . uniqid('', true);
        $this->migrationsDir    = $this->tempDir . '/migrations';
        $this->registeredModules = [];

        mkdir($this->migrationsDir, 0755, true);
        mkdir($this->tempDir . '/vendor/composer', 0755, true);
        $this->writeInstalledJson();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_publishes_sql_stub_as_doctrine_migration_class(): void
    {
        $this->makeSqlStub('Messaging', '001_vortos_outbox.sql', 'CREATE TABLE outbox (id UUID PRIMARY KEY)');

        $tester = $this->runCommand();

        $this->assertSame(0, $tester->getStatusCode());

        $published = glob($this->migrationsDir . '/Version*.php') ?: [];
        $this->assertCount(1, $published);
        $this->assertStringContainsString('AbstractMigration', (string) file_get_contents($published[0]));
    }

    public function test_generated_class_contains_the_stub_sql(): void
    {
        $this->makeSqlStub('Messaging', '001_outbox.sql', 'CREATE TABLE outbox (id UUID PRIMARY KEY)');

        $this->runCommand();

        $published = glob($this->migrationsDir . '/Version*.php') ?: [];
        $this->assertCount(1, $published);

        $content = (string) file_get_contents($published[0]);
        $this->assertStringContainsString('CREATE TABLE outbox', $content);
    }

    public function test_publishes_stubs_from_multiple_modules(): void
    {
        $this->makeSqlStub('Messaging', '001_outbox.sql', 'CREATE TABLE outbox (id INT)');
        $this->makeSqlStub('Notification', '001_pushes.sql', 'CREATE TABLE pushes (id INT)');

        $this->runCommand();

        $published = glob($this->migrationsDir . '/Version*.php') ?: [];
        $this->assertCount(2, $published);
    }

    public function test_skips_already_published_stubs(): void
    {
        $this->makeSqlStub('Messaging', '001_outbox.sql', 'CREATE TABLE outbox (id INT)');

        $this->runCommand();
        $this->runCommand();

        $published = glob($this->migrationsDir . '/Version*.php') ?: [];
        $this->assertCount(1, $published, 'Second run should not generate additional files.');
    }

    public function test_manifest_is_created_after_publishing(): void
    {
        $this->makeSqlStub('Messaging', '001_outbox.sql', 'CREATE TABLE outbox (id INT)');

        $this->runCommand();

        $this->assertFileExists($this->migrationsDir . '/.vortos-published.json');
    }

    public function test_manifest_records_stub_to_class_mapping(): void
    {
        $this->makeSqlStub('Messaging', '001_outbox.sql', 'CREATE TABLE outbox (id INT)');

        $this->runCommand();

        $data = json_decode(
            (string) file_get_contents($this->migrationsDir . '/.vortos-published.json'),
            true,
        );

        $this->assertArrayHasKey('published', $data);
        $this->assertNotEmpty($data['published']);

        $entry = array_values($data['published'])[0];
        $this->assertArrayHasKey('class', $entry);
        $this->assertStringContainsString('App\\Migrations\\Version', $entry['class']);
    }

    public function test_dry_run_does_not_write_files(): void
    {
        $this->makeSqlStub('Messaging', '001_outbox.sql', 'CREATE TABLE outbox (id INT)');

        $this->runCommand(['--dry-run' => true]);

        $published = glob($this->migrationsDir . '/Version*.php') ?: [];
        $this->assertCount(0, $published);
    }

    public function test_dry_run_does_not_create_manifest(): void
    {
        $this->makeSqlStub('Messaging', '001_outbox.sql', 'CREATE TABLE outbox (id INT)');

        $this->runCommand(['--dry-run' => true]);

        $this->assertFileDoesNotExist($this->migrationsDir . '/.vortos-published.json');
    }

    public function test_output_reports_published_and_skipped(): void
    {
        $this->makeSqlStub('Messaging', '001_outbox.sql', 'CREATE TABLE outbox (id INT)');
        $this->makeSqlStub('Messaging', '002_failed.sql', 'CREATE TABLE failed (id INT)');

        $this->runCommand();

        $this->makeSqlStub('Notification', '001_notify.sql', 'CREATE TABLE notifications (id INT)');

        $tester = $this->runCommand();
        $output = $tester->getDisplay();

        $this->assertStringContainsString('Skipped', $output);
        $this->assertStringContainsString('Published', $output);
    }

    public function test_output_confirms_nothing_to_do_when_all_published(): void
    {
        $this->makeSqlStub('Messaging', '001_outbox.sql', 'CREATE TABLE outbox (id INT)');

        $this->runCommand();
        $tester = $this->runCommand();

        $this->assertStringContainsString('already published', $tester->getDisplay());
    }

    public function test_generated_class_has_strict_types(): void
    {
        $this->makeSqlStub('Messaging', '001_outbox.sql', 'CREATE TABLE outbox (id INT)');

        $this->runCommand();

        $published = glob($this->migrationsDir . '/Version*.php') ?: [];
        $content   = (string) file_get_contents($published[0]);

        $this->assertStringContainsString("declare(strict_types=1);", $content);
    }

    public function test_each_stub_generates_unique_class_name(): void
    {
        $this->makeSqlStub('ModuleA', '001_table_a.sql', 'CREATE TABLE a (id INT)');
        $this->makeSqlStub('ModuleB', '001_table_b.sql', 'CREATE TABLE b (id INT)');

        $this->runCommand();

        $published  = glob($this->migrationsDir . '/Version*.php') ?: [];
        $classNames = array_map('basename', $published);

        $this->assertCount(2, array_unique($classNames));
    }

    private function runCommand(array $options = []): CommandTester
    {
        $scanner   = new ModuleStubScanner(new ModulePathResolver($this->tempDir), $this->tempDir);
        $generator = new MigrationClassGenerator();
        $command   = new MigratePublishCommand($scanner, $generator, $this->tempDir);

        $app = new Application();
        $app->add($command);

        $tester = new CommandTester($app->find('vortos:migrate:publish'));
        $tester->execute($options);

        return $tester;
    }

    private function makeSqlStub(string $module, string $filename, string $sql): void
    {
        $dir = $this->tempDir . '/packages/Vortos/src/' . $module . '/Resources/migrations';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/' . $filename, $sql);

        if (!in_array($module, $this->registeredModules, true)) {
            $this->registeredModules[] = $module;
            $this->writeInstalledJson();
        }
    }

    private function writeInstalledJson(): void
    {
        $packages = array_map(static fn(string $m) => [
            'name'         => 'vortos/vortos-' . strtolower($m),
            'install-path' => '../../packages/Vortos/src/' . $m,
        ], $this->registeredModules);

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => $packages]),
        );
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
