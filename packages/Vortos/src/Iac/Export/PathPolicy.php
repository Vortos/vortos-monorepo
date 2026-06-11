<?php

declare(strict_types=1);

namespace Vortos\Iac\Export;

use Vortos\Iac\Exception\PathViolationException;

/**
 * Output-path rules, enforced twice: at container compile time (so a bad
 * path fails the build) and again by SafeFileWriter at write time (so even
 * a hand-edited compiled container cannot write outside the project).
 */
final class PathPolicy
{
    private const SUFFIX = '.tf.json';
    private const SEGMENT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /** Validates a declared output path (relative, jailed, .tf.json). */
    public static function validate(string $path): void
    {
        if ($path === '') {
            throw new PathViolationException('Output path must not be empty.');
        }

        if (!str_ends_with($path, self::SUFFIX)) {
            throw new PathViolationException(sprintf(
                "Output path '%s' must end with '%s' — generated files are Terraform JSON.",
                $path,
                self::SUFFIX,
            ));
        }

        if (str_contains($path, '\\')) {
            throw new PathViolationException(sprintf("Output path '%s' must use forward slashes.", $path));
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new PathViolationException(sprintf(
                "Output path '%s' must be relative to the project directory.",
                $path,
            ));
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || !preg_match(self::SEGMENT_PATTERN, $segment)) {
                throw new PathViolationException(sprintf(
                    "Output path '%s' contains a forbidden segment ('%s').",
                    $path,
                    $segment,
                ));
            }
        }
    }

    /**
     * Resolves a validated relative path against the project dir and
     * re-checks the real parent directory is still inside it — catches
     * symlink escapes that pure string validation cannot.
     */
    public static function resolveInside(string $projectDir, string $relativePath): string
    {
        self::validate($relativePath);

        $projectReal = realpath($projectDir);

        if ($projectReal === false) {
            throw new PathViolationException(sprintf("Project directory '%s' does not exist.", $projectDir));
        }

        $target = $projectReal . '/' . $relativePath;
        $parent = dirname($target);

        if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new PathViolationException(sprintf("Cannot create output directory '%s'.", $parent));
        }

        $parentReal = realpath($parent);

        if ($parentReal === false || !str_starts_with($parentReal . '/', $projectReal . '/')) {
            throw new PathViolationException(sprintf(
                "Output path '%s' escapes the project directory (symlink?).",
                $relativePath,
            ));
        }

        return $parentReal . '/' . basename($target);
    }
}
