<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * {@see \Vortos\Secrets\Value\SecretValue::reveal()} is the sole plaintext exit
 * point of the redact-by-construction value object. This test enumerates the
 * complete, audited allow-list of production call sites and fails the build the
 * moment any new call site appears outside it — turning "reveal() is only called
 * in deliberate, narrow places" from a developer-discipline convention into an
 * enforced invariant.
 */
final class SecretValueRedactionArchTest extends TestCase
{
    private const ALLOWED_CALL_SITES = [
        'Driver/Env/EnvSecretsProvider.php',
        'Service/SecretInjectionPlan.php',
        'Crypto/Identity.php',
        'Key/DataKey.php',
    ];

    public function test_reveal_is_called_only_from_the_audited_allow_list(): void
    {
        $root = dirname(__DIR__, 2);
        $violations = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($root) + 1);

            // Testing/ ships TCK base classes for downstream driver authors to
            // assert against — conformance assertions, not production runtime code.
            if (str_starts_with($relativePath, 'Tests/') || str_starts_with($relativePath, 'Testing/')) {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());
            if (!str_contains($code, '->reveal()')) {
                continue;
            }

            if (!in_array($relativePath, self::ALLOWED_CALL_SITES, true)) {
                $violations[] = $relativePath;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "SecretValue::reveal() must only be called from the audited allow-list:\n  - " . implode("\n  - ", $violations),
        );
    }

    public function test_allow_list_call_sites_still_exist_and_still_call_reveal(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (self::ALLOWED_CALL_SITES as $relativePath) {
            $path = $root . '/' . $relativePath;
            $this->assertFileExists($path, "Allow-listed call site no longer exists: {$relativePath}");

            $code = (string) file_get_contents($path);
            $this->assertStringContainsString(
                '->reveal()',
                $code,
                "Allow-listed call site no longer calls reveal() — remove it from the allow-list: {$relativePath}",
            );
        }
    }
}
