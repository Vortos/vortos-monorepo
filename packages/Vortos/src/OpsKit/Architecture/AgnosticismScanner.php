<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Architecture;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;

/**
 * The agnosticism guarantee (roadmap §13 #1), implemented over a parsed AST — not a
 * substring grep.
 *
 * It walks PHP files and flags any provider name (see {@see ProviderNameMatcher})
 * appearing in a namespace segment, a class/interface/enum/trait name, or any class
 * reference (use-statement, `new`, type-hint, static call, …) that lives OUTSIDE an
 * allowed location:
 *
 *   - a namespace containing a `Driver` segment (the only place drivers may name
 *     their provider), or
 *   - a path fragment the caller marks exempt (the composition root, e.g. `/config/`).
 *
 * Because it reads the AST, a provider name in a comment, a doc-block, or a variable
 * (`$github`) is never visited — eliminating the classic grep false positives. The
 * same {@see ProviderNameMatcher} backs the PHPStan rule, so enforcement is identical
 * in CI and in the test suite.
 */
final class AgnosticismScanner
{
    private readonly Parser $parser;

    /**
     * @param list<string> $exemptNamespaceSegments namespace segments that make a file exempt
     * @param list<string> $exemptPathFragments     path fragments that make a file exempt
     */
    public function __construct(
        private readonly ProviderNameMatcher $matcher,
        private readonly array $exemptNamespaceSegments = ['Driver'],
        private readonly array $exemptPathFragments = [],
    ) {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * Scan a file or a directory tree.
     *
     * @return list<ProviderNameOccurrence>
     */
    public function scan(string $path): array
    {
        $occurrences = [];
        foreach ($this->phpFiles($path) as $file) {
            foreach ($this->exemptPathFragments as $fragment) {
                if ($fragment !== '' && str_contains($file, $fragment)) {
                    continue 2;
                }
            }
            foreach ($this->scanFile($file) as $occurrence) {
                $occurrences[] = $occurrence;
            }
        }

        return $occurrences;
    }

    /**
     * @return list<ProviderNameOccurrence>
     */
    public function scanFile(string $file): array
    {
        $code = (string) file_get_contents($file);

        try {
            $ast = $this->parser->parse($code) ?? [];
        } catch (\PhpParser\Error $e) {
            throw new RuntimeException("Cannot parse {$file}: {$e->getMessage()}", 0, $e);
        }

        $visitor = new class($file, $this->matcher, $this->exemptNamespaceSegments) extends NodeVisitorAbstract {
            /** @var list<ProviderNameOccurrence> */
            public array $occurrences = [];

            /** @param list<string> $exemptSegments */
            public function __construct(
                private readonly string $file,
                private readonly ProviderNameMatcher $matcher,
                private readonly array $exemptSegments,
            ) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $ns = $node->name?->toString() ?? '';
                    if ($this->isExempt($ns)) {
                        // Drivers may name their provider freely — skip the whole subtree.
                        return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                    }

                    // A provider name in the namespace declaration itself is still a leak,
                    // but the declaration's name is traversed as a child Node\Name below —
                    // recording it here would double-count.
                    return null;
                }

                if ($node instanceof Node\Name) {
                    $this->record($node->toString(), $node->getStartLine());
                } elseif ($node instanceof Node\Stmt\ClassLike && $node->name !== null) {
                    // ClassLike covers class/interface/trait/enum declarations.
                    $this->record($node->name->toString(), $node->name->getStartLine());
                }

                return null;
            }

            private function isExempt(string $namespace): bool
            {
                $segments = explode('\\', $namespace);
                foreach ($this->exemptSegments as $exempt) {
                    if (in_array($exempt, $segments, true)) {
                        return true;
                    }
                }

                return false;
            }

            private function record(string $symbol, int $line): void
            {
                $provider = $this->matcher->match($symbol);
                if ($provider !== null) {
                    $this->occurrences[] = new ProviderNameOccurrence($this->file, $line, $provider, $symbol);
                }
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->occurrences;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        if (!is_dir($path)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
