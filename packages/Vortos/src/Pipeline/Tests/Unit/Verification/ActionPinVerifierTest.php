<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Verification;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\PinnedAction;
use Vortos\Pipeline\Verification\ActionPinVerifier;
use Vortos\Pipeline\Verification\ActionRefResolverInterface;
use Vortos\Pipeline\Verification\ActionRuntimeResolverInterface;

/**
 * B7 (offline logic): the verifier fails closed on a SHA that does not resolve, and passes a SHA
 * that does — the exact defect that made every generated workflow unrunnable.
 */
final class ActionPinVerifierTest extends TestCase
{
    public function test_flags_a_nonexistent_sha(): void
    {
        $resolver = new class implements ActionRefResolverInterface {
            public function resolve(string $owner, string $repo, string $ref): ?string
            {
                // The historical bad anchore pin: does not exist.
                return $ref === 'fc46e51fd3cb168ffb36c6d1915723c47db58571' ? null : $ref;
            }
        };

        $verifier = new ActionPinVerifier($resolver);
        $results = $verifier->verify([
            new PinnedAction('anchore', 'sbom-action', 'fc46e51fd3cb168ffb36c6d1915723c47db58571', 'v0'),
        ]);

        self::assertFalse($results[0]['exists']);
        self::assertFalse($verifier->allExist($results));
        self::assertStringContainsString('does not exist', (string) $results[0]['note']);
    }

    public function test_passes_an_existing_sha(): void
    {
        $sha = 'e22c389904149dbc22b58101806040fa8d37a610';
        $resolver = new class ($sha) implements ActionRefResolverInterface {
            public function __construct(private string $sha) {}

            public function resolve(string $owner, string $repo, string $ref): ?string
            {
                // The SHA resolves; the version tag resolves to the same commit.
                return in_array($ref, [$this->sha, 'v0.24.0'], true) ? $this->sha : null;
            }
        };

        $verifier = new ActionPinVerifier($resolver);
        $results = $verifier->verify([
            new PinnedAction('anchore', 'sbom-action', $sha, 'v0.24.0'),
        ]);

        self::assertTrue($results[0]['exists']);
        self::assertTrue($results[0]['version_matches']);
        self::assertNull($results[0]['note']);
        self::assertTrue($verifier->allExist($results));
    }

    public function test_existing_sha_with_moved_version_tag_is_a_pass_with_an_advisory(): void
    {
        $pinnedSha = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $movedSha = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $resolver = new class ($pinnedSha, $movedSha) implements ActionRefResolverInterface {
            public function __construct(private string $pinned, private string $moved) {}

            public function resolve(string $owner, string $repo, string $ref): ?string
            {
                if ($ref === $this->pinned) {
                    return $this->pinned; // pinned commit still exists
                }
                if ($ref === 'v4') {
                    return $this->moved;   // moving major tag has advanced
                }

                return null;
            }
        };

        $verifier = new ActionPinVerifier($resolver);
        $results = $verifier->verify([
            new PinnedAction('actions', 'checkout', $pinnedSha, 'v4'),
        ]);

        self::assertTrue($results[0]['exists'], 'A frozen pin whose major tag moved on is still valid.');
        self::assertFalse($results[0]['version_matches']);
        self::assertStringContainsString('newer release', (string) $results[0]['note']);
        self::assertTrue($verifier->allExist($results));
    }

    public function test_removed_runtime_fails_closed(): void
    {
        $verifier = $this->verifierWithRuntime('node16');
        $results = $verifier->verify([$this->action()]);

        self::assertSame('removed', $results[0]['runtime_status']);
        self::assertSame('node16', $results[0]['runtime']);
        self::assertFalse($verifier->allRuntimesSupported($results));
    }

    public function test_deprecated_runtime_is_advisory_not_fatal(): void
    {
        $verifier = $this->verifierWithRuntime('node20');
        $results = $verifier->verify([$this->action()]);

        self::assertSame('deprecated', $results[0]['runtime_status']);
        self::assertTrue($verifier->allRuntimesSupported($results), 'node20 still runs (auto-upgraded); advisory, not fatal.');
        self::assertTrue($verifier->hasDeprecatedRuntimes($results));
    }

    public function test_current_node_runtime_passes(): void
    {
        $verifier = $this->verifierWithRuntime('node24');
        $results = $verifier->verify([$this->action()]);

        self::assertSame('current', $results[0]['runtime_status']);
        self::assertTrue($verifier->allRuntimesSupported($results));
        self::assertFalse($verifier->hasDeprecatedRuntimes($results));
    }

    public function test_docker_runtime_is_supported(): void
    {
        $verifier = $this->verifierWithRuntime('docker');
        $results = $verifier->verify([$this->action()]);

        self::assertSame('current', $results[0]['runtime_status']);
        self::assertTrue($verifier->allRuntimesSupported($results));
    }

    public function test_runtime_is_unknown_when_no_resolver_supplied(): void
    {
        $verifier = new ActionPinVerifier($this->echoResolver());
        $results = $verifier->verify([$this->action()]);

        self::assertSame('unknown', $results[0]['runtime_status']);
        self::assertNull($results[0]['runtime']);
        self::assertTrue($verifier->allRuntimesSupported($results));
    }

    public function test_runtime_not_probed_for_a_nonexistent_sha(): void
    {
        $refResolver = new class implements ActionRefResolverInterface {
            public function resolve(string $owner, string $repo, string $ref): ?string
            {
                return null; // nothing resolves
            }
        };
        $runtimeResolver = new class implements ActionRuntimeResolverInterface {
            public function runtime(string $owner, string $repo, string $ref): ?string
            {
                throw new \LogicException('runtime must not be probed for a pin that does not exist');
            }
        };

        $verifier = new ActionPinVerifier($refResolver, $runtimeResolver);
        $results = $verifier->verify([$this->action()]);

        self::assertFalse($results[0]['exists']);
        self::assertSame('unknown', $results[0]['runtime_status']);
    }

    private function action(): PinnedAction
    {
        return new PinnedAction('actions', 'checkout', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'v5');
    }

    private function echoResolver(): ActionRefResolverInterface
    {
        // Every ref resolves to itself → the pin's SHA "exists".
        return new class implements ActionRefResolverInterface {
            public function resolve(string $owner, string $repo, string $ref): ?string
            {
                return $ref;
            }
        };
    }

    private function verifierWithRuntime(string $using): ActionPinVerifier
    {
        $runtimeResolver = new class ($using) implements ActionRuntimeResolverInterface {
            public function __construct(private string $using) {}

            public function runtime(string $owner, string $repo, string $ref): ?string
            {
                return $this->using;
            }
        };

        return new ActionPinVerifier($this->echoResolver(), $runtimeResolver);
    }
}
