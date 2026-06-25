<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Off-host identity custody (§12.6/§12.7): the `age` private identity is never
 * read from a tracked file and never persisted to one — it is sourced exclusively
 * from an environment variable, at use-time, inside {@see \Vortos\Secrets\Driver\Age\AgeKeyProvider}.
 * {@see \Vortos\Secrets\Crypto\Identity::revealKeyPairForUnsealing()} mirrors
 * {@see \Vortos\Secrets\Value\SecretValue::reveal()} as a single, auditable
 * boundary crossing and must stay confined to that one driver.
 */
final class KeyCustodyArchTest extends TestCase
{
    private const ALLOWED_GETENV_FILE = 'Driver/Age/AgeKeyProvider.php';
    private const ALLOWED_UNSEAL_CALL_SITE = 'Driver/Age/AgeKeyProvider.php';

    public function test_identity_seed_is_read_via_getenv_only_inside_age_key_provider(): void
    {
        $violations = $this->scan(static function (string $code, string $relativePath): ?string {
            if (!str_contains($code, 'getenv(')) {
                return null;
            }

            return $relativePath !== KeyCustodyArchTest::ALLOWED_GETENV_FILE
                ? "{$relativePath} calls getenv()"
                : null;
        });

        $this->assertSame(
            [],
            $violations,
            "Identity material must only be read via getenv() inside AgeKeyProvider:\n  - " . implode("\n  - ", $violations),
        );
    }

    public function test_reveal_key_pair_for_unsealing_is_called_only_from_age_key_provider(): void
    {
        $violations = $this->scan(static function (string $code, string $relativePath): ?string {
            if (!str_contains($code, '->revealKeyPairForUnsealing()')) {
                return null;
            }

            return $relativePath !== KeyCustodyArchTest::ALLOWED_UNSEAL_CALL_SITE
                ? "{$relativePath} calls Identity::revealKeyPairForUnsealing()"
                : null;
        });

        $this->assertSame(
            [],
            $violations,
            "Identity::revealKeyPairForUnsealing() must only be called from AgeKeyProvider:\n  - " . implode("\n  - ", $violations),
        );
    }

    public function test_no_class_persists_identity_material_to_a_file(): void
    {
        $violations = $this->scan(static function (string $code, string $relativePath): ?string {
            if ($relativePath !== KeyCustodyArchTest::ALLOWED_GETENV_FILE) {
                return null;
            }

            foreach (['file_put_contents', 'fwrite', 'fputs'] as $writeFunction) {
                if (str_contains($code, $writeFunction . '(')) {
                    return "{$relativePath} calls {$writeFunction}() — identity material must never touch a file";
                }
            }

            return null;
        });

        $this->assertSame([], $violations, implode("\n  - ", $violations));
    }

    /** @param callable(string, string): (string|null) $check */
    private function scan(callable $check): array
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

            if (str_starts_with($relativePath, 'Tests/')) {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());
            $violation = $check($code, $relativePath);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }
}
