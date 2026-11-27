<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Driver\GitHubActions;

use Vortos\Pipeline\Exception\InvalidPipelineException;

/**
 * GitHub refuses a whole workflow when one of its strings, as GitHub evaluates it, is longer than 21000
 * characters ("Exceeded max expression length 21000"). The run shows only "This run likely failed because of
 * a workflow file issue", no job starts, and a push to the deployment branch deploys nothing.
 *
 * A string holding `${{ }}` is evaluated as `format('<literal>', <expr0>, <expr1>, ...)`: each expression
 * becomes `{N}` plus an argument, and the literal's quotes and braces are doubled. That rendered length is
 * what counts, not the text in the file: on 2026-11-28 a deploy step of 20906 characters rendered to 21082
 * and was refused; the previous one, 19554 characters, rendered to 19735 and ran.
 *
 * Checked by WorkflowYamlWriter, which writes every workflow, so the limit fails the generator (and the
 * pipeline drift check) on the laptop, not the release on GitHub. The margin below the real limit absorbs any
 * difference between this rendering and GitHub's own.
 */
final class ExpressionLengthLimit
{
    public const GITHUB_LIMIT = 21000;
    public const MAX = 20000;

    private function __construct() {}

    /** The length GitHub measures for this string; 0 when it holds no expression (then no limit applies). */
    public static function renderedLength(string $value): int
    {
        $parts = preg_split('/\$\{\{(.*?)\}\}/s', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false || count($parts) === 1) {
            return 0;
        }

        $length = strlen("format('')");
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $length += strlen($part) + substr_count($part, "'") + substr_count($part, '{') + substr_count($part, '}');
            } else {
                $length += strlen(sprintf('{%d}', intdiv($i, 2))) + strlen(', ' . trim($part));
            }
        }

        return $length;
    }

    /**
     * Refuses a workflow holding a string GitHub would refuse.
     *
     * @param array<mixed> $workflow
     */
    public static function assertWithin(array $workflow, string $path = ''): void
    {
        foreach ($workflow as $key => $value) {
            $here = $path === '' ? (string) $key : $path . '.' . $key;
            if (is_array($value)) {
                self::assertWithin($value, $here);
                continue;
            }
            if ($value instanceof Yaml\CommentedScalar) {
                $value = $value->value;
            }
            if (!is_string($value)) {
                continue;
            }
            $length = self::renderedLength($value);
            if ($length > self::MAX) {
                throw new InvalidPipelineException(sprintf(
                    'The workflow value at %s renders to %d characters as a GitHub expression; GitHub refuses the whole workflow above %d, and this generator stops at %d. Shorten it: name a repeated command once (a shell function) or move work into a script the step runs.',
                    $here,
                    $length,
                    self::GITHUB_LIMIT,
                    self::MAX,
                ));
            }
        }
    }
}
