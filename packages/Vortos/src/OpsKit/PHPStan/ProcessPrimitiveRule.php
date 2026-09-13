<?php

declare(strict_types=1);

namespace Vortos\OpsKit\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Only `Vortos\Foundation\Process\ProcessLauncher` may start a process (RC-2).
 *
 * Credentials reached process argv — `mongodump --uri=` carrying the password, readable by any local
 * user through /proc/<pid>/cmdline — because ~20 call sites each spawned processes their own way and
 * nothing could check them. The launcher now owns argv/env/stdin/secret-file delivery and output
 * redaction; this rule makes bypassing it a build failure, in the framework and in any app that wires
 * the rule.
 *
 * Refused: proc_open, proc_close, proc_terminate, proc_get_status, proc_nice, exec, shell_exec, system,
 * passthru, popen, pcntl_exec, backtick shell execution, `new Symfony\Component\Process\Process`,
 * `new PhpProcess`, and `Process::fromShellCommandline`.
 *
 * @implements Rule<Node>
 */
final class ProcessPrimitiveRule implements Rule
{
    public const BANNED_FUNCTIONS = [
        'proc_open', 'proc_close', 'proc_terminate', 'proc_get_status', 'proc_nice',
        'exec', 'shell_exec', 'system', 'passthru', 'popen', 'pcntl_exec',
    ];

    public const BANNED_CLASSES = [
        'Symfony\\Component\\Process\\Process',
        'Symfony\\Component\\Process\\PhpProcess',
    ];

    /** @param list<string> $allowedPathFragments files whose path contains one of these may use primitives */
    public function __construct(
        private readonly array $allowedPathFragments = ['/Foundation/Process/'],
    ) {}

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $file = str_replace('\\', '/', $scope->getFile());
        foreach ($this->allowedPathFragments as $fragment) {
            if (str_contains($file, $fragment)) {
                return [];
            }
        }

        $violation = $this->violation($node, $scope->resolveName(...));
        if ($violation === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s bypasses the process launcher. Use Vortos\\Foundation\\Process\\ProcessLauncherInterface: secrets by env/stdin/SensitiveFile, never argv.',
                $violation,
            ))->identifier('vortos.processPrimitive')->build(),
        ];
    }

    /**
     * The pure decision, testable without an analyser.
     *
     * @param \Closure(Node\Name): string $resolveName
     */
    public function violation(Node $node, \Closure $resolveName): ?string
    {
        if ($node instanceof Node\Expr\ShellExec) {
            return 'Backtick shell execution';
        }

        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $name = strtolower(ltrim($node->name->toString(), '\\'));
            if (\in_array($name, self::BANNED_FUNCTIONS, true)) {
                return $name . '()';
            }
        }

        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            $class = ltrim($resolveName($node->class), '\\');
            if (\in_array($class, self::BANNED_CLASSES, true)) {
                return 'new ' . $class;
            }
        }

        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
            $class = ltrim($resolveName($node->class), '\\');
            if (\in_array($class, self::BANNED_CLASSES, true) && strtolower($node->name->toString()) === 'fromshellcommandline') {
                return $class . '::fromShellCommandline()';
            }
        }

        return null;
    }
}
