<?php

declare(strict_types=1);

namespace Vortos\OpsKit\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Vortos\OpsKit\Architecture\ProviderNameMatcher;

/**
 * The agnosticism guarantee as a first-class PHPStan rule, ready to wire into a
 * `phpstan.neon` whenever the repo grows one.
 *
 * It shares {@see ProviderNameMatcher} with the PHPUnit-side {@see \Vortos\OpsKit\Architecture\AgnosticismScanner}
 * so a leak is detected identically in CI and in the test suite. A name is exempt
 * when its enclosing namespace contains a `Driver` segment (where drivers legitimately
 * name their provider). The composition root is excluded by `paths`/`excludePaths`
 * configuration in `phpstan.neon`, not here.
 *
 * Wired in `phpstan.neon.dist`, scoped to the agnostic concern cores via
 * `includePathFragments` (agnosticism is a per-concern property, so it must never run
 * over a legitimate provider-integration package like `Vortos\Paddle`).
 *
 * Registered for the base {@see Node} (reliably dispatched) and filtered internally,
 * so it catches both class *references* (`use`/`new`/type-hints, `Node\Name`) and
 * class/interface/enum *declaration names* (`Node\Stmt\ClassLike`) — matching the
 * PHPUnit-side {@see \Vortos\OpsKit\Architecture\AgnosticismScanner}, with which it
 * shares {@see ProviderNameMatcher} so the two never drift.
 *
 * @implements Rule<Node>
 */
final class AgnosticismRule implements Rule
{
    private readonly ProviderNameMatcher $matcher;

    /** @var list<string> */
    private readonly array $exemptNamespaceSegments;

    /** @var list<string> */
    private readonly array $includePathFragments;

    /**
     * @param list<string>|null $providers              null → curated default list
     * @param list<string>      $exemptNamespaceSegments namespace segments where providers are allowed
     * @param list<string>      $includePathFragments    if non-empty, only analyse files whose path
     *                                                   contains one of these fragments. Agnosticism is
     *                                                   a per-concern property: scope the rule to the
     *                                                   agnostic concern cores (deploy/health/…), so it
     *                                                   never false-flags a legitimate provider-integration
     *                                                   package (e.g. Vortos\Paddle).
     */
    public function __construct(
        ?array $providers = null,
        array $exemptNamespaceSegments = ['Driver'],
        array $includePathFragments = [],
    ) {
        $this->matcher = $providers === null
            ? ProviderNameMatcher::default()
            : new ProviderNameMatcher($providers);
        $this->exemptNamespaceSegments = $exemptNamespaceSegments;
        $this->includePathFragments = $includePathFragments;
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @param Node $node
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->appliesTo($scope->getFile())) {
            return [];
        }

        $symbol = $this->symbolOf($node);
        if ($symbol === null) {
            return [];
        }

        $provider = $this->leak($symbol, $scope->getNamespace());
        if ($provider === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                "Provider name '%s' leaked in symbol '%s'. Move provider-specific code into a Driver\\ namespace or the composition root.",
                $provider,
                $symbol,
            ))
                ->identifier('vortos.agnosticism')
                ->build(),
        ];
    }

    /**
     * The class symbol carried by a node, if any: a declaration name, a use-import,
     * or a class reference. PHPStan delivers class references wrapped in their
     * expression node (not as a bare Node\Name), so the common carriers are unwrapped
     * here.
     */
    private function symbolOf(Node $node): ?string
    {
        if ($node instanceof Node\Stmt\ClassLike && $node->name !== null) {
            return $node->name->toString();          // class/interface/enum/trait declaration
        }

        if ($node instanceof Node\UseItem) {
            return $node->name->toString();           // use Foo\CaddyClient;
        }

        if ($node instanceof Node\Name) {
            return $node->toString();                 // namespace decl, type hints (when delivered)
        }

        $classRef = match (true) {
            $node instanceof Node\Expr\New_,
            $node instanceof Node\Expr\StaticCall,
            $node instanceof Node\Expr\StaticPropertyFetch,
            $node instanceof Node\Expr\ClassConstFetch,
            $node instanceof Node\Expr\Instanceof_ => $node->class,
            default => null,
        };

        return $classRef instanceof Node\Name ? $classRef->toString() : null;
    }

    /**
     * The pure decision shared by processNode: returns the leaked provider token for
     * $symbol when its enclosing namespace is not exempt, else null. Kept separate so
     * the rule's logic is testable without a (fragile) Scope test double.
     */
    public function leak(string $symbol, ?string $namespace): ?string
    {
        if ($this->isExemptNamespace($namespace ?? '')) {
            return null;
        }

        return $this->matcher->match($symbol);
    }

    /** Whether this file is within the rule's configured scope (all files when unscoped). */
    public function appliesTo(string $file): bool
    {
        if ($this->includePathFragments === []) {
            return true;
        }

        foreach ($this->includePathFragments as $fragment) {
            if ($fragment !== '' && str_contains($file, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function isExemptNamespace(string $namespace): bool
    {
        $segments = explode('\\', $namespace);
        foreach ($this->exemptNamespaceSegments as $exempt) {
            if (in_array($exempt, $segments, true)) {
                return true;
            }
        }

        return false;
    }
}
