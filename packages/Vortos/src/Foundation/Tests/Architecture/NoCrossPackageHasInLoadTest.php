<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Architecture ratchet: an Extension::load() must NOT call has()/hasDefinition()/hasAlias() on a
 * class owned by ANOTHER vortos package.
 *
 * Rationale: load() runs during Symfony's MergeExtensionConfigurationPass, where each extension
 * loads in isolation and cannot reliably see another extension's service definitions. A cross-
 * package has() there is order-dependent — it silently returns the wrong answer and the dependent
 * service/command vanishes. Cross-package decisions belong in a CompilerPass (via
 * PackageInterface::build()), where has() is complete and order-independent.
 *
 * What is allowed (and intentionally NOT flagged):
 *  - has() on the extension's OWN package classes (deterministic within one load()).
 *  - has() guarded by class_exists()/interface_exists() on the same or adjacent line (autoloader-
 *    based, order-free — the sanctioned "use it if the class is installed" idiom).
 *  - has() on non-vortos infrastructure (e.g. Doctrine\DBAL\Connection, \Redis) — these are not
 *    another vortos package and are provided by the persistence/cache layer.
 *  - Dynamic ids (variables / string literals) — cannot be statically attributed to a package.
 *
 * This test is FAIL-CLOSED: the allowlist is empty. Any newly introduced cross-package
 * has()-in-load() fails CI. If you must add one, the correct fix is a CompilerPass — see
 * {@see \Vortos\Foundation\DependencyInjection\Compiler\ConditionalWiringPass}. The allowlist may
 * only ever shrink.
 *
 * @see \Vortos\Deploy\DependencyInjection\Compiler\DeployWiringPass
 * @see \Vortos\Authorization\DependencyInjection\Compiler\AuthzTokenFreshnessWiringPass
 * @see \Vortos\Alerts\DependencyInjection\Compiler\SloRegistryDefaultPass
 */
final class NoCrossPackageHasInLoadTest extends TestCase
{
    /**
     * Known, tracked offenders that remain to be migrated, keyed by extension file basename with
     * the set of foreign FQCNs still referenced. MUST stay empty except during an in-flight
     * migration; it may only shrink.
     *
     * @var array<string, list<string>>
     */
    private const KNOWN_OFFENDERS = [];

    /**
     * @return iterable<string, array{string}>
     */
    public static function extensionFiles(): iterable
    {
        $base = \dirname(__DIR__, 4) . '/src';
        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $it */
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $file) {
            $path = $file->getPathname();
            if (!str_ends_with($path, 'Extension.php')) {
                continue;
            }
            if (!str_contains($path, '/DependencyInjection/')) {
                continue;
            }
            yield basename($path) => [$path];
        }
    }

    #[DataProvider('extensionFiles')]
    public function test_no_cross_package_has_in_load(string $path): void
    {
        $source = (string) file_get_contents($path);
        $ownPackage = self::ownPackage($source);

        // Skip files we cannot attribute (should not happen for src extensions).
        if ($ownPackage === null) {
            $this->addToAssertionCount(1);
            return;
        }

        $imports = self::imports($source);
        $lines = explode("\n", $source);
        $violations = [];

        foreach ($lines as $i => $line) {
            if (!preg_match_all('/->(?:has|hasDefinition|hasAlias)\(\s*\\\\?([A-Za-z0-9_\\\\]+)::class/', $line, $m)) {
                continue;
            }

            // A class_exists()/interface_exists() guard on this or the previous two lines makes
            // the has() autoloader-gated and order-free — the sanctioned idiom.
            $window = $line . "\n" . ($lines[$i - 1] ?? '') . "\n" . ($lines[$i - 2] ?? '');
            if (str_contains($window, 'class_exists(') || str_contains($window, 'interface_exists(')) {
                continue;
            }

            foreach ($m[1] as $ref) {
                $fqcn = self::resolve($ref, $imports);
                if (!str_starts_with($fqcn, 'Vortos\\')) {
                    continue; // non-vortos infra (Doctrine, Redis, PSR, …) — allowed.
                }

                $refPackage = explode('\\', $fqcn)[1] ?? '';
                if ($refPackage === $ownPackage) {
                    continue; // own package — deterministic within one load().
                }

                if (\in_array($fqcn, self::KNOWN_OFFENDERS[basename($path)] ?? [], true)) {
                    continue; // tracked, being migrated.
                }

                $violations[] = sprintf('line %d: %s (owned by vortos-%s)', $i + 1, $fqcn, strtolower($refPackage));
            }
        }

        self::assertSame(
            [],
            $violations,
            basename($path) . " calls has()/hasDefinition()/hasAlias() on a foreign package's class "
            . "inside load(). Move the cross-package decision into a CompilerPass "
            . "(PackageInterface::build()). Offenders:\n  - " . implode("\n  - ", $violations),
        );
    }

    private static function ownPackage(string $source): ?string
    {
        if (preg_match('/^namespace\s+Vortos\\\\([A-Za-z0-9_]+)/m', $source, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array<string, string> short-name/alias => FQCN
     */
    private static function imports(string $source): array
    {
        $map = [];
        if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?;/m', $source, $rows, \PREG_SET_ORDER)) {
            foreach ($rows as $row) {
                $fqcn = $row[1];
                $alias = $row[2] ?? '';
                $short = $alias !== '' ? $alias : (substr($fqcn, (int) strrpos($fqcn, '\\') + 1));
                $map[$short] = ltrim($fqcn, '\\');
            }
        }

        return $map;
    }

    /**
     * @param array<string, string> $imports
     */
    private static function resolve(string $ref, array $imports): string
    {
        $ref = ltrim($ref, '\\');

        // Inline fully-qualified reference (contains a namespace separator).
        if (str_contains($ref, '\\')) {
            return $ref;
        }

        // Imported short name → FQCN; otherwise it is a same-namespace (own package) class.
        return $imports[$ref] ?? $ref;
    }
}
