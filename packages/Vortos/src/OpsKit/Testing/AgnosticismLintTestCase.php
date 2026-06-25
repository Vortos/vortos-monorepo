<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Testing;

use PHPUnit\Framework\TestCase;
use Vortos\OpsKit\Architecture\AgnosticismScanner;
use Vortos\OpsKit\Architecture\ProviderNameMatcher;

/**
 * The reusable agnosticism-lint base every package extends to enforce roadmap §13 #1
 * in the test suite.
 *
 *     final class DeployAgnosticismTest extends AgnosticismLintTestCase
 *     {
 *         protected function packagePath(): string { return dirname(__DIR__, 2); }
 *     }
 *
 * It fails the build the moment a provider name appears outside a `Driver\` namespace
 * or an exempt path. Override the hooks to tune the provider list and exemptions.
 */
abstract class AgnosticismLintTestCase extends TestCase
{
    /** The directory to scan (usually the package's src root). */
    abstract protected function packagePath(): string;

    /** @return list<string>|null null → the curated default provider list */
    protected function providerNames(): ?array
    {
        return null;
    }

    /** @return list<string> namespace segments under which provider names are allowed */
    protected function exemptNamespaceSegments(): array
    {
        return ['Driver'];
    }

    /** @return list<string> path fragments to skip (e.g. test fixtures, composition root) */
    protected function exemptPathFragments(): array
    {
        return ['/Tests/', '/config/'];
    }

    final public function test_no_provider_name_leaks_outside_drivers(): void
    {
        $occurrences = $this->scanner()->scan($this->packagePath());

        $this->assertSame(
            [],
            array_map(static fn ($o) => $o->describe(), $occurrences),
            "Provider names leaked outside Driver\\ namespaces:\n  - "
            . implode("\n  - ", array_map(static fn ($o) => $o->describe(), $occurrences)),
        );
    }

    final protected function scanner(): AgnosticismScanner
    {
        $providers = $this->providerNames();
        $matcher = $providers === null ? ProviderNameMatcher::default() : new ProviderNameMatcher($providers);

        return new AgnosticismScanner($matcher, $this->exemptNamespaceSegments(), $this->exemptPathFragments());
    }
}
