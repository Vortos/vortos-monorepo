<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\DependencyInjection;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\FeatureFlags\DependencyInjection\FeatureFlagsExtension;
use Vortos\FeatureFlags\Exposure\Ledger\ExposureLedgerFlusher;
use Vortos\FeatureFlags\Exposure\Ledger\LedgerExposureObserver;
use Vortos\FeatureFlags\Exposure\Ledger\SubjectPseudonymiser;

/**
 * What the container does with the ledger — including the part that is invisible until it
 * silently does not run.
 */
final class ExposureLedgerRegistrationTest extends TestCase
{
    private const PEPPER = 'e6f1c0aa8b2d4f7391c5ae0b7d2f48366a9c1e5b0d8342f7ac916be4d05f2371';

    /** @var array<string,string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (['FEATURE_FLAGS_LEDGER', 'FEATURE_FLAGS_LEDGER_PEPPER'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? false;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                unset($_ENV[$key]);
                continue;
            }

            $_ENV[$key] = $value;
        }
    }

    public function test_the_ledger_is_absent_unless_explicitly_enabled(): void
    {
        // Default-off is a privacy decision, not a convenience one: enabling this writes
        // personal data, which an operator opts into rather than inherits from an upgrade.
        unset($_ENV['FEATURE_FLAGS_LEDGER']);

        $container = $this->compile();

        self::assertFalse($container->hasDefinition(LedgerExposureObserver::class));
    }

    public function test_enabling_it_without_a_pepper_fails_the_build(): void
    {
        // Loudly, at container-compile time. A ledger that boots and then stores weakly-hashed
        // identities is worse than one that refuses to boot, because by the time anyone
        // notices the data is already written.
        $_ENV['FEATURE_FLAGS_LEDGER'] = '1';
        unset($_ENV['FEATURE_FLAGS_LEDGER_PEPPER']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/FEATURE_FLAGS_LEDGER_PEPPER/');

        $this->compile();
    }

    public function test_enabling_it_with_a_weak_pepper_fails_the_build(): void
    {
        $_ENV['FEATURE_FLAGS_LEDGER']        = '1';
        $_ENV['FEATURE_FLAGS_LEDGER_PEPPER'] = 'too-short';

        $this->expectException(InvalidArgumentException::class);

        $this->compile();
    }

    public function test_the_observer_is_tagged_so_the_registry_finds_it(): void
    {
        $container = $this->enabled();

        // The registry collects observers through a tagged iterator and knows about neither
        // this nor the analytics bridge. An untagged observer is simply never called, and
        // nothing anywhere reports that.
        self::assertTrue(
            $container->getDefinition(LedgerExposureObserver::class)
                ->hasTag(FeatureFlagsExtension::EXPOSURE_OBSERVER_TAG),
        );
    }

    public function test_the_flusher_carries_the_tag_that_keeps_it_alive(): void
    {
        $container = $this->enabled();

        // The subtle one. RegisterTerminablePass discovers terminable middleware by SCANNING
        // definitions for the interface, not by reading this tag — but nothing injects the
        // flusher, so RemoveUnusedDefinitionsPass deletes it as unreferenced BEFORE that scan
        // runs, and the scan then finds nothing. Without the tag the ledger would compile
        // cleanly, boot cleanly, buffer every exposure, and write none of them.
        self::assertTrue(
            $container->getDefinition(ExposureLedgerFlusher::class)->hasTag('vortos.http.terminable'),
        );
    }

    public function test_the_pseudonymiser_receives_the_configured_pepper(): void
    {
        $container = $this->enabled();

        self::assertSame(
            self::PEPPER,
            $container->getDefinition(SubjectPseudonymiser::class)->getArgument('$pepper'),
        );
    }

    private function enabled(): ContainerBuilder
    {
        $_ENV['FEATURE_FLAGS_LEDGER']        = '1';
        $_ENV['FEATURE_FLAGS_LEDGER_PEPPER'] = self::PEPPER;

        return $this->compile();
    }

    private function compile(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new FeatureFlagsExtension())->load([], $container);

        return $container;
    }
}
