<?php

declare(strict_types=1);

namespace Vortos\Mcp\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Vortos\Mcp\Tool\GetModuleDocsTool;

final class GetModuleDocsToolTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/vortos_mcp_docs_' . uniqid('', true);
        mkdir($this->projectDir, 0777, true);
    }

    #[After]
    protected function cleanUp(): void
    {
        @unlink($this->projectDir . '/composer.lock');
        @rmdir($this->projectDir);
    }

    public function test_unknown_module_lists_available_modules(): void
    {
        $output = (new GetModuleDocsTool($this->projectDir))->execute(['module' => 'not-a-real-module']);

        $this->assertStringContainsString("Unknown module 'not-a-real-module'", $output);
        $this->assertStringContainsString('deploy', $output);
    }

    public function test_framework_bundled_module_is_still_checked_against_composer_lock(): void
    {
        $this->writeComposerLock([
            'packages' => [
                ['name' => 'vortos/vortos-domain', 'version' => 'v1.0.0'],
            ],
        ]);

        $output = (new GetModuleDocsTool($this->projectDir))->execute(['module' => 'domain']);

        $this->assertStringContainsString('✓ `vortos/vortos-domain` is installed (v1.0.0)', $output);
    }

    public function test_split_package_reports_not_installed_without_composer_lock(): void
    {
        $output = (new GetModuleDocsTool($this->projectDir))->execute(['module' => 'deploy']);

        $this->assertStringContainsString('composer.lock not found', $output);
    }

    public function test_split_package_reports_installed_when_present_in_composer_lock(): void
    {
        $this->writeComposerLock([
            'packages' => [
                ['name' => 'vortos/vortos-deploy', 'version' => 'v1.2.3'],
            ],
        ]);

        $output = (new GetModuleDocsTool($this->projectDir))->execute(['module' => 'deploy']);

        $this->assertStringContainsString('✓ `vortos/vortos-deploy` is installed (v1.2.3)', $output);
        $this->assertStringNotContainsString('composer require vortos/vortos-deploy', $output);
    }

    public function test_split_package_reports_missing_with_install_command_when_composer_lock_present(): void
    {
        $this->writeComposerLock(['packages' => []]);

        $output = (new GetModuleDocsTool($this->projectDir))->execute(['module' => 'backup']);

        $this->assertStringContainsString('✗ `vortos/vortos-backup` is NOT installed', $output);
        $this->assertStringContainsString('composer require vortos/vortos-backup', $output);
    }

    public function test_dev_package_is_detected_from_packages_dev(): void
    {
        $this->writeComposerLock([
            'packages-dev' => [
                ['name' => 'vortos/vortos-make', 'version' => 'v1.0.0'],
            ],
        ]);

        $output = (new GetModuleDocsTool($this->projectDir))->execute(['module' => 'make']);

        $this->assertStringContainsString('✓ `vortos/vortos-make` is installed (v1.0.0)', $output);
    }

    public function test_all_modules_render_without_error_when_no_filter_given(): void
    {
        $output = (new GetModuleDocsTool($this->projectDir))->execute([]);

        $this->assertStringContainsString('## ops_kit', $output);
        $this->assertStringContainsString('## deploy', $output);
        $this->assertStringContainsString('## health', $output);
        $this->assertStringContainsString('## analytics_posthog', $output);
    }

    /** @param array<string, mixed> $lock */
    private function writeComposerLock(array $lock): void
    {
        file_put_contents($this->projectDir . '/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
    }
}
