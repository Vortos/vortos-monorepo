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
    private const KNOWN_OFFENDERS = [
        // AuthExtension::hasRedisService() gates token storage, sessions and the auth audit sink on
        // hasDefinition(\Redis::class). It is a genuine instance of this rule — vortos-cache
        // registers that service — but it is NOT a straight swap to a compiler pass: AuthExtension
        // also registers \Redis::class ITSELF a few lines earlier, so some call sites are
        // self-satisfied and deterministic while others are not, and the five call sites decide how
        // credentials are stored. Untangling that belongs in a change that can be reviewed and
        // tested on its own, not bundled into an unrelated release.
        //
        // Tracked, not forgiven. This list may only ever shrink.
        'AuthExtension.php' => ['Redis'],
    ];

    /**
     * Third-party classes whose SERVICE is registered by a vortos package's extension.
     *
     * Ownership of the class is irrelevant; what matters is that another extension has to have run
     * for the service to exist. Treating these as "not our problem" is what let the durable-storage
     * fallbacks stay order-dependent.
     *
     * @var list<string>
     */
    private const CROSS_PACKAGE_INFRASTRUCTURE = [
        'Doctrine\\DBAL\\Connection',
        'Redis',
    ];

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
        $literals = self::stringVariables($source);
        $lines = explode("\n", $source);
        $violations = [];

        foreach ($lines as $i => $line) {
            $refs = [];

            if (preg_match_all('/->(?:has|hasDefinition|hasAlias)\(\s*\\\\?([A-Za-z0-9_\\\\]+)::class/', $line, $m)) {
                $refs = $m[1];
            }

            // A has() on a VARIABLE holding a class name is the same race, and used to be invisible
            // here. Two real defects hid behind exactly this:
            //   AuditExtension     — $metricsIface (vortos-metrics), so audit metrics silently
            //                        resolved to null and recorded nothing.
            //   SchedulerExtension — $policyEngineClass (vortos-authorization), so the scheduler's
            //                        RBAC policy silently degraded to NullSchedulePolicy: a
            //                        security control failing OPEN because of extension ordering.
            // Variables assigned a literal FQCN in the same file are resolved and checked.
            if (preg_match_all('/->(?:has|hasDefinition|hasAlias)\(\s*\$([A-Za-z_][A-Za-z0-9_]*)/', $line, $vm)) {
                foreach ($vm[1] as $variable) {
                    if (isset($literals[$variable])) {
                        $refs[] = $literals[$variable];
                    }
                }
            }

            if ($refs === []) {
                continue;
            }

            // There is deliberately NO class_exists()/interface_exists() exemption here.
            //
            // This test used to skip any has() with such a guard within three lines, calling it
            // "the sanctioned idiom". It is not an idiom, it is the bug. class_exists() answers
            // "is the package INSTALLED?" — order-free and true from the first autoload. The has()
            // beside it answers "has that package's extension REGISTERED this service YET?", which
            // during load() is a race whatever guards it. ANDing an order-free question with an
            // order-dependent one yields an order-dependent answer.
            //
            // The exemption hid a live defect: HealthExtension computed
            //     class_exists(HeartbeatEmitterInterface) && $container->has(HeartbeatEmitterInterface)
            // froze it into a constructor argument, lost the race, and DetectorIndependenceDoctorCheck
            // then failed a production deploy reporting a detector missing that was wired (FB-38).
            //
            // class_exists() remains correct for deciding whether to REFERENCE a class at all —
            // `if (interface_exists(X)) { ...new Reference(X, NULL_ON_INVALID_REFERENCE) }`. That
            // form contains no has() and is not matched by this rule.

            foreach ($refs as $ref) {
                $fqcn = self::resolve($ref, $imports);

                // Shared infrastructure whose CLASS is third-party but whose SERVICE is registered
                // by another vortos package's extension.
                //
                // These used to be exempt as "non-vortos infra". That reasoning confused who owns
                // the class with who registers the service: Doctrine\DBAL\Connection is registered
                // by vortos-persistence, \Redis by vortos-cache. Asking has() for either during
                // load() is the same race as asking for any other foreign service, and losing it
                // silently swaps durable storage for in-memory storage — state that does not
                // survive a restart and is not shared between processes, with nothing reporting it.
                //
                // HealthExtension lost exactly this race in production: the uptime sync record fell
                // back to InMemorySyncRecordStore, so `health:monitor:sync` could never observe its
                // own last hash and re-pushed the monitor on every run.
                if (!str_starts_with($fqcn, 'Vortos\\') && !\in_array($fqcn, self::CROSS_PACKAGE_INFRASTRUCTURE, true)) {
                    continue; // genuinely local/PSR types — allowed.
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

    /**
     * Variables assigned a literal class-name string, e.g. `$engine = 'Vortos\\Foo\\Bar';`.
     *
     * Only literal assignments are resolved. A genuinely computed id cannot be attributed to a
     * package and is still out of scope — but a constant string in a variable is exactly as
     * order-dependent as writing the class inline, and hiding behind one should not exempt it.
     *
     * @return array<string, string> variable name => FQCN
     */
    private static function stringVariables(string $source): array
    {
        $map = [];

        if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*\'((?:[A-Za-z0-9_]+\\\\)+[A-Za-z0-9_]+)\'\s*;/', $source, $rows, \PREG_SET_ORDER)) {
            foreach ($rows as $row) {
                $map[$row[1]] = $row[2];
            }
        }

        return $map;
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
