<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\OpsKit\Architecture\AgnosticismScanner;
use Vortos\OpsKit\Architecture\ProviderNameMatcher;

final class AgnosticismScannerTest extends TestCase
{
    private string $lintFixtures;

    protected function setUp(): void
    {
        $this->lintFixtures = \dirname(__DIR__) . '/Fixtures/Lint';
    }

    public function test_flags_provider_name_in_class_outside_driver_namespace(): void
    {
        $occurrences = $this->scanner()->scanFile($this->lintFixtures . '/leaked/CaddyConfigService.php');

        $this->assertCount(1, $occurrences);
        $this->assertSame('caddy', $occurrences[0]->provider);
        $this->assertStringContainsString('CaddyConfigService', $occurrences[0]->symbol);
    }

    public function test_flags_provider_name_in_namespace_declaration(): void
    {
        $occurrences = $this->scanner()->scanFile($this->lintFixtures . '/leaked/NamespaceLeak.php');

        $this->assertCount(1, $occurrences);
        $this->assertSame('caddy', $occurrences[0]->provider);
        $this->assertSame('App\\Caddy', $occurrences[0]->symbol);
    }

    public function test_does_not_flag_comments_variables_or_string_literals(): void
    {
        $occurrences = $this->scanner()->scanFile($this->lintFixtures . '/clean/EdgeConfigService.php');
        $this->assertSame([], $occurrences);
    }

    public function test_does_not_flag_provider_inside_driver_namespace(): void
    {
        $occurrences = $this->scanner()->scanFile($this->lintFixtures . '/driver/CaddyEdgeDriver.php');
        $this->assertSame([], $occurrences);
    }

    public function test_directory_scan_finds_only_the_leaks(): void
    {
        $occurrences = $this->scanner()->scan($this->lintFixtures);

        // leaked/ has two; clean/, driver/ and composition/ have none — but composition
        // references a provider via `use`, so it is only clean when path-exempted.
        $files = array_unique(array_map(static fn ($o) => basename($o->file), $occurrences));
        sort($files);
        $this->assertContains('CaddyConfigService.php', $files);
        $this->assertContains('NamespaceLeak.php', $files);
        $this->assertNotContains('EdgeConfigService.php', $files);
        $this->assertNotContains('CaddyEdgeDriver.php', $files);
    }

    public function test_path_fragment_exemption_skips_composition_root(): void
    {
        $withoutExemption = $this->scanner()->scanFile($this->lintFixtures . '/composition/services.php');
        $this->assertNotSame([], $withoutExemption, 'composition file references a provider via use — flagged when not exempt');

        $exempt = new AgnosticismScanner(ProviderNameMatcher::default(), ['Driver'], ['/composition/']);
        $this->assertSame([], $exempt->scan($this->lintFixtures . '/composition'));
    }

    public function test_occurrence_describe_is_actionable(): void
    {
        $occurrence = $this->scanner()->scanFile($this->lintFixtures . '/leaked/CaddyConfigService.php')[0];
        $this->assertStringContainsString('caddy', $occurrence->describe());
        $this->assertStringContainsString('Driver', $occurrence->describe());
    }

    private function scanner(): AgnosticismScanner
    {
        return new AgnosticismScanner(ProviderNameMatcher::default());
    }
}
