<?php

declare(strict_types=1);

namespace Vortos\Tests\Mcp;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Mcp\Client\ClientConfigWriter;
use Vortos\Mcp\Client\ClientDetector;
use Vortos\Mcp\Client\KnownClients;
use Vortos\Mcp\Command\McpInstallCommand;

final class McpInstallCommandTest extends TestCase
{
    private string $projectDir;
    private string $homeDir;
    private ?string $previousHome;
    private ?string $previousUserProfile;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/vortos_mcp_project_' . uniqid('', true);
        $this->homeDir = sys_get_temp_dir() . '/vortos_mcp_home_' . uniqid('', true);
        $this->previousHome = $_SERVER['HOME'] ?? null;
        $this->previousUserProfile = $_SERVER['USERPROFILE'] ?? null;

        mkdir($this->projectDir, 0777, true);
        mkdir($this->homeDir, 0777, true);
        $_SERVER['HOME'] = $this->homeDir;
    }

    protected function tearDown(): void
    {
        if ($this->previousHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->previousHome;
        }
        if ($this->previousUserProfile === null) {
            unset($_SERVER['USERPROFILE']);
        } else {
            $_SERVER['USERPROFILE'] = $this->previousUserProfile;
        }

        $this->removeDirectory($this->projectDir);
        $this->removeDirectory($this->homeDir);
    }

    public function test_auto_installs_for_detected_project_client(): void
    {
        mkdir($this->projectDir . '/.cursor', 0777, true);

        $tester = $this->tester();
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileExists($this->projectDir . '/.cursor/mcp.json');
        $this->assertFileDoesNotExist($this->projectDir . '/.claude/settings.json');
        $this->assertFileDoesNotExist($this->projectDir . '/.windsurf/mcp.json');
    }

    public function test_auto_prompts_when_multiple_clients_are_detected_interactively(): void
    {
        mkdir($this->homeDir . '/.codex', 0777, true);
        mkdir($this->projectDir . '/.cursor', 0777, true);

        $tester = $this->tester();
        $tester->setInputs(['1']);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('all - All detected clients', $tester->getDisplay());
        $this->assertFileExists($this->projectDir . '/.cursor/mcp.json');
        $this->assertFileDoesNotExist($this->homeDir . '/.codex/config.toml');
    }

    public function test_auto_can_install_all_detected_clients_interactively(): void
    {
        mkdir($this->homeDir . '/.codex', 0777, true);
        mkdir($this->projectDir . '/.cursor', 0777, true);

        $tester = $this->tester();
        $tester->setInputs(['2']);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileExists($this->homeDir . '/.codex/config.toml');
        $this->assertFileExists($this->projectDir . '/.cursor/mcp.json');
    }

    public function test_auto_installs_all_detected_clients_non_interactively(): void
    {
        mkdir($this->homeDir . '/.codex', 0777, true);
        mkdir($this->projectDir . '/.cursor', 0777, true);

        $tester = $this->tester();
        $tester->execute([], ['interactive' => false]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileExists($this->homeDir . '/.codex/config.toml');
        $this->assertFileExists($this->projectDir . '/.cursor/mcp.json');
    }

    public function test_auto_installs_for_detected_global_client(): void
    {
        mkdir($this->homeDir . '/.claude', 0777, true);

        $tester = $this->tester();
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileExists($this->projectDir . '/.claude/settings.json');
    }

    public function test_auto_reports_when_no_supported_client_is_detected(): void
    {
        $tester = $this->tester();
        $tester->setInputs([]);
        $tester->execute([], ['interactive' => false]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('No supported AI client config was detected.', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->homeDir . '/.codex/config.toml');
        $this->assertFileDoesNotExist($this->projectDir . '/.claude/settings.json');
        $this->assertFileDoesNotExist($this->projectDir . '/.cursor/mcp.json');
        $this->assertFileDoesNotExist($this->projectDir . '/.windsurf/mcp.json');
    }

    public function test_auto_prompts_for_client_when_none_is_detected_interactively(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['2']);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Choose AI client for MCP', $tester->getDisplay());
        $this->assertFileExists($this->projectDir . '/.cursor/mcp.json');
    }

    public function test_explicit_client_still_installs_without_detection(): void
    {
        $tester = $this->tester();
        $tester->execute(['--client' => 'windsurf']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileExists($this->projectDir . '/.windsurf/mcp.json');
    }

    public function test_codex_client_writes_global_toml_config(): void
    {
        $tester = $this->tester();
        $tester->execute(['--client' => 'codex']);

        $this->assertSame(0, $tester->getStatusCode());
        $config = (string) file_get_contents($this->homeDir . '/.codex/config.toml');

        $this->assertStringContainsString('[mcp_servers.vortos]', $config);
        $this->assertStringContainsString('command = "php"', $config);
        $this->assertStringContainsString('args = ["' . $this->projectDir . '/bin/console", "vortos:mcp:serve"]', $config);
        $this->assertFileDoesNotExist($this->projectDir . '/.codex/config.toml');
    }

    public function test_codex_client_escapes_windows_paths_in_toml_config(): void
    {
        $projectDir = 'C:\\Users\\Ada Lovelace\\demo';
        $knownClients = new KnownClients();
        $writer = new ClientConfigWriter($knownClients);

        $writer->write('codex', $projectDir);

        $config = (string) file_get_contents($this->homeDir . '/.codex/config.toml');

        $this->assertStringContainsString(
            'args = ["C:\\\\Users\\\\Ada Lovelace\\\\demo/bin/console", "vortos:mcp:serve"]',
            $config,
        );
    }

    public function test_codex_client_uses_userprofile_when_home_is_not_set(): void
    {
        unset($_SERVER['HOME']);
        $_SERVER['USERPROFILE'] = $this->homeDir;

        $knownClients = new KnownClients();
        $writer = new ClientConfigWriter($knownClients);

        $result = $writer->write('codex', $this->projectDir);

        $this->assertSame($this->homeDir . '/.codex/config.toml', $result['path']);
        $this->assertFileExists($this->homeDir . '/.codex/config.toml');
    }

    private function tester(): CommandTester
    {
        $knownClients = new KnownClients();
        $command = new McpInstallCommand(
            $this->projectDir,
            new ClientDetector($this->projectDir, $knownClients),
            new ClientConfigWriter($knownClients),
        );

        $application = new Application();
        $application->add($command);

        return new CommandTester($application->find('vortos:mcp:install'));
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
