<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * RC-2 guard, tokenizer edition: no framework source outside Foundation/Process starts a process.
 *
 * Complements `Vortos\OpsKit\PHPStan\ProcessPrimitiveRule` (which also covers apps that wire it) by
 * running in the plain test suite over every package in the monorepo, so the guarantee does not
 * depend on which paths a phpstan config happens to analyse. Tests are exempt (fixtures), as is the
 * launcher itself.
 */
final class NoRawProcessPrimitivesTest extends TestCase
{
    private const BANNED_FUNCTIONS = [
        'proc_open', 'proc_close', 'proc_terminate', 'proc_get_status', 'proc_nice',
        'exec', 'shell_exec', 'system', 'passthru', 'popen', 'pcntl_exec',
    ];

    public function testNoFrameworkSourceBypassesTheLauncher(): void
    {
        $root = \dirname(__DIR__, 3); // packages/Vortos/src
        $violations = [];
        $scanned = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if ($file->getExtension() !== 'php' || str_contains($path, '/Tests/') || str_contains($path, '/Foundation/Process/') || str_contains($path, '/vendor/')) {
                continue;
            }
            $scanned++;
            foreach ($this->violations((string) file_get_contents($path)) as $violation) {
                $violations[] = substr($path, \strlen($root) + 1) . ': ' . $violation;
            }
        }

        self::assertGreaterThan(500, $scanned, 'the scan must cover the monorepo, not an empty directory');
        self::assertSame([], $violations, "Process primitives outside Foundation/Process:\n  - " . implode("\n  - ", $violations));
    }

    public function testTheScannerSeesWhatItBans(): void
    {
        self::assertSame(
            ['exec()', 'backtick', 'new Process', 'proc_open()'],
            $this->violations('<?php use Symfony\Component\Process\Process; \exec("x"); $a = `id`; new Process([]); proc_open([], [], $p); $pdo->exec("x"); Foo::system(); function exec2() {}'),
        );
    }

    /** @return list<string> */
    private function violations(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn ($t): bool => !\is_array($t) || !\in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $found = [];
        $importsProcess = str_contains($code, 'Symfony\\Component\\Process\\Process;');
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '`') {
                $found[] = 'backtick';
                // Skip the command body and its closing backtick; the next token is ordinary code again.
                for ($i++; $i < $count && $tokens[$i] !== '`'; $i++) {
                }
                continue;
            }
            if (!\is_array($token)) {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;
            $previousId = \is_array($previous) ? $previous[0] : $previous;

            if (\in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) && $next === '(') {
                $name = strtolower(ltrim($token[1], '\\'));
                if (\in_array($name, self::BANNED_FUNCTIONS, true)
                    && !\in_array($previousId, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                    $found[] = $name . '()';
                }
            }

            if ($token[0] === T_NEW && \is_array($next)) {
                $class = ltrim($next[1], '\\');
                if ($class === 'Symfony\\Component\\Process\\Process' || ($importsProcess && $class === 'Process')) {
                    $found[] = 'new Process';
                }
            }

            if ($token[0] === T_STRING && strtolower($token[1]) === 'fromshellcommandline') {
                $found[] = 'fromShellCommandline';
            }
        }

        return $found;
    }
}
