<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\PHPStan;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Vortos\OpsKit\PHPStan\ProcessPrimitiveRule;

final class ProcessPrimitiveRuleTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function bannedCode(): iterable
    {
        yield 'proc_open' => ['proc_open(["ls"], [], $p);', 'proc_open()'];
        yield 'fully qualified exec' => ['\\exec("ls");', 'exec()'];
        yield 'shell_exec' => ['shell_exec("ls");', 'shell_exec()'];
        yield 'passthru' => ['passthru("ls");', 'passthru()'];
        yield 'system' => ['system("ls");', 'system()'];
        yield 'popen' => ['popen("ls", "r");', 'popen()'];
        yield 'proc_close' => ['proc_close($h);', 'proc_close()'];
        yield 'backticks' => ['$x = `ls`;', 'Backtick shell execution'];
        yield 'symfony process' => ['new \\Symfony\\Component\\Process\\Process(["ls"]);', 'new Symfony\\Component\\Process\\Process'];
        yield 'from shell commandline' => ['\\Symfony\\Component\\Process\\Process::fromShellCommandline("ls");', 'Symfony\\Component\\Process\\Process::fromShellCommandline()'];
    }

    /** @dataProvider bannedCode */
    public function testBannedPrimitivesAreReported(string $code, string $expected): void
    {
        self::assertSame([$expected], $this->violations($code));
    }

    public function testMethodsNamedLikePrimitivesAreFine(): void
    {
        self::assertSame([], $this->violations('$pdo->exec("SELECT 1"); $redis->exec(); Foo::system(); $launcher->run($spec);'));
    }

    public function testOnlyTheLauncherPathIsAllowed(): void
    {
        $rule = new ProcessPrimitiveRule();

        self::assertSame(['/Foundation/Process/'], (new \ReflectionProperty($rule, 'allowedPathFragments'))->getValue($rule));
    }

    /** @return list<string> */
    private function violations(string $code): array
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse('<?php ' . $code) ?? [];
        $rule = new ProcessPrimitiveRule();
        $resolve = static fn (Node\Name $name): string => $name->toString();

        $found = [];
        foreach ((new NodeFinder())->find($ast, static fn (Node $n): bool => true) as $node) {
            $violation = $rule->violation($node, $resolve);
            if ($violation !== null) {
                $found[] = $violation;
            }
        }

        return $found;
    }
}
