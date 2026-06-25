<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\OpsKit\Architecture\AgnosticismScanner;
use Vortos\OpsKit\Architecture\ProviderNameMatcher;

/**
 * The port itself, the value objects, the privacy pipeline, and the FF bridge must
 * never name a provider — e.g. PostHog's `$feature_flag_called` event-name literal
 * lives only in the split's `PosthogEventMapper`, never here.
 */
final class PortPurityTest extends TestCase
{
    public function test_no_provider_symbol_in_pure_namespaces(): void
    {
        $scanner = new AgnosticismScanner(ProviderNameMatcher::default(), exemptNamespaceSegments: [], exemptPathFragments: ['/Tests/']);
        $root = dirname(__DIR__, 2);

        $occurrences = [];
        foreach ([
            $root . '/AnalyticsInterface.php',
            $root . '/Event',
            $root . '/Privacy',
            $root . '/Bridge',
        ] as $path) {
            foreach ($scanner->scan($path) as $occurrence) {
                $occurrences[] = $occurrence->describe();
            }
        }

        $this->assertSame([], $occurrences, "Provider names leaked into a pure namespace:\n  - " . implode("\n  - ", $occurrences));
    }
}
