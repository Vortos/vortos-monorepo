<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Oci;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Driver\Oci\OciRegistry;
use Vortos\Deploy\Execution\CommandResult;
use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Oci\NullImageSigner;
use Vortos\Deploy\Registry\ImageReference;

/**
 * B17 (daemon-first digest resolution) + B18 (pull short-circuit when the digest is already local).
 *
 * These exercise the deploy-in-image posture: the host daemon already holds the digest-pinned image
 * but the one-shot container has no crane/skopeo/buildx and no registry credentials.
 */
final class OciOpsDaemonFirstTest extends TestCase
{
    private const DIGEST = 'sha256:' . '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const REPO = 'ghcr.io/acme/app';

    public function test_digest_for_resolves_from_local_daemon_without_registry_tools(): void
    {
        $runner = new ProgrammableRunner();
        $runner->on(
            static fn (array $argv): bool => $argv[0] === 'docker' && ($argv[1] ?? '') === 'image' && ($argv[2] ?? '') === 'inspect',
            new CommandResult(0, sprintf('["%s@%s"]', self::REPO, self::DIGEST), '', 0.01),
        );

        $registry = new OciRegistry($runner, new NullImageSigner());
        $digest = $registry->digestFor(new ImageReference(self::REPO, 'v1'));

        self::assertSame(self::DIGEST, $digest);
        self::assertSame(
            ['docker', 'image', 'inspect', '--format', '{{json .RepoDigests}}', self::REPO . ':v1'],
            $runner->calls[0],
            'digest must resolve on the first daemon lookup',
        );
        self::assertCount(1, $runner->calls, 'no crane/skopeo/buildx fallback when the image is local');
    }

    public function test_digest_for_picks_the_matching_repository_not_the_first_entry(): void
    {
        $runner = new ProgrammableRunner();
        $otherDigest = 'sha256:' . str_repeat('b', 64);
        $runner->on(
            static fn (array $argv): bool => ($argv[1] ?? '') === 'image',
            new CommandResult(
                0,
                sprintf('["docker.io/other/app@%s","%s@%s"]', $otherDigest, self::REPO, self::DIGEST),
                '',
                0.01,
            ),
        );

        $registry = new OciRegistry($runner, new NullImageSigner());

        self::assertSame(self::DIGEST, $registry->digestFor(new ImageReference(self::REPO, 'v1')));
    }

    public function test_digest_for_falls_through_to_registry_tools_when_not_local(): void
    {
        $runner = new ProgrammableRunner();
        // Local inspect: image absent -> non-zero exit.
        $runner->on(
            static fn (array $argv): bool => ($argv[1] ?? '') === 'image',
            new CommandResult(1, '', 'No such image', 0.01),
        );
        // crane digest succeeds.
        $runner->on(
            static fn (array $argv): bool => $argv[0] === 'crane',
            new CommandResult(0, self::DIGEST, '', 0.01),
        );

        $registry = new OciRegistry($runner, new NullImageSigner());

        self::assertSame(self::DIGEST, $registry->digestFor(new ImageReference(self::REPO, 'v1')));
        self::assertSame('docker', $runner->calls[0][0]);
        self::assertSame('crane', $runner->calls[1][0]);
    }

    public function test_digest_for_treats_null_repo_digests_as_not_local(): void
    {
        $runner = new ProgrammableRunner();
        // Locally-built image with no pushed repo digest -> `.RepoDigests` renders as `null`.
        $runner->on(
            static fn (array $argv): bool => ($argv[1] ?? '') === 'image',
            new CommandResult(0, 'null', '', 0.01),
        );
        $runner->on(
            static fn (array $argv): bool => $argv[0] === 'crane',
            new CommandResult(0, self::DIGEST, '', 0.01),
        );

        $registry = new OciRegistry($runner, new NullImageSigner());

        self::assertSame(self::DIGEST, $registry->digestFor(new ImageReference(self::REPO, 'v1')));
    }

    public function test_pull_short_circuits_when_digest_is_already_local(): void
    {
        $runner = new ProgrammableRunner();
        $runner->on(
            static fn (array $argv): bool => ($argv[1] ?? '') === 'image',
            new CommandResult(0, sprintf('["%s@%s"]', self::REPO, self::DIGEST), '', 0.01),
        );

        $registry = new OciRegistry($runner, new NullImageSigner());
        $registry->pull(new ImageReference(self::REPO, null, self::DIGEST));

        self::assertCount(1, $runner->calls, 'only the local inspect runs');
        foreach ($runner->calls as $call) {
            self::assertNotSame('pull', $call[1] ?? null, 'no docker pull when the image is local');
            self::assertNotSame('login', $call[1] ?? null, 'no docker login when the image is local');
        }
    }

    public function test_pull_authenticates_and_pulls_when_not_local(): void
    {
        $runner = new ProgrammableRunner();
        $runner->on(
            static fn (array $argv): bool => ($argv[1] ?? '') === 'image',
            new CommandResult(1, '', 'No such image', 0.01),
        );

        $registry = new OciRegistry($runner, new NullImageSigner());
        $registry->pull(new ImageReference(self::REPO, null, self::DIGEST));

        $pullCalls = array_filter($runner->calls, static fn (array $c): bool => ($c[1] ?? null) === 'pull');
        self::assertCount(1, $pullCalls, 'docker pull runs when the image is absent locally');
    }

    public function test_pull_rejects_non_digest_pinned_reference_before_any_daemon_lookup(): void
    {
        $runner = new ProgrammableRunner();
        $registry = new OciRegistry($runner, new NullImageSigner());

        $this->expectException(\InvalidArgumentException::class);
        try {
            $registry->pull(new ImageReference(self::REPO, 'v1'));
        } finally {
            self::assertSame([], $runner->calls, 'no daemon lookup for an unpinned ref');
        }
    }

    // ── B23: Docker Hub "familiar" repository normalization ──────────────────────────────────────
    // Docker Hub records RepoDigests under the familiar name (no "docker.io/" / "index.docker.io/"
    // prefix, official images without "library/"). A fully-qualified $image->repository must still
    // match so the auth-less one-shot short-circuits instead of falling through to a failing pull.

    public function test_pull_short_circuits_when_hub_records_familiar_name(): void
    {
        $runner = new ProgrammableRunner();
        // Deploy passes docker.io/sqoura/sqoura-backend; the daemon lists the familiar name.
        $runner->on(
            static fn (array $argv): bool => ($argv[1] ?? '') === 'image',
            new CommandResult(0, sprintf('["sqoura/sqoura-backend@%s"]', self::DIGEST), '', 0.01),
        );

        $registry = new OciRegistry($runner, new NullImageSigner());
        $registry->pull(new ImageReference('docker.io/sqoura/sqoura-backend', null, self::DIGEST));

        self::assertCount(1, $runner->calls, 'only the local inspect runs — familiar name recognised');
        foreach ($runner->calls as $call) {
            self::assertNotSame('pull', $call[1] ?? null, 'no docker pull for a normalized-equal local image');
            self::assertNotSame('login', $call[1] ?? null, 'no docker login for a normalized-equal local image');
        }
    }

    public function test_pull_short_circuits_for_official_library_image(): void
    {
        $runner = new ProgrammableRunner();
        $runner->on(
            static fn (array $argv): bool => ($argv[1] ?? '') === 'image',
            new CommandResult(0, sprintf('["redis@%s"]', self::DIGEST), '', 0.01),
        );

        $registry = new OciRegistry($runner, new NullImageSigner());
        $registry->pull(new ImageReference('index.docker.io/library/redis', null, self::DIGEST));

        self::assertCount(1, $runner->calls, 'index.docker.io/library/redis normalizes to redis');
    }

    public function test_pull_pulls_when_digest_differs_despite_normalized_repo_match(): void
    {
        $runner = new ProgrammableRunner();
        $otherDigest = 'sha256:' . str_repeat('c', 64);
        // Same (normalized) repo present locally, but a *different* digest — must NOT short-circuit.
        $runner->on(
            static fn (array $argv): bool => ($argv[1] ?? '') === 'image',
            new CommandResult(0, sprintf('["sqoura/sqoura-backend@%s"]', $otherDigest), '', 0.01),
        );

        $registry = new OciRegistry($runner, new NullImageSigner());
        $registry->pull(new ImageReference('docker.io/sqoura/sqoura-backend', null, self::DIGEST));

        $pullCalls = array_filter($runner->calls, static fn (array $c): bool => ($c[1] ?? null) === 'pull');
        self::assertCount(1, $pullCalls, 'a normalized repo match with a different digest still pulls');
    }

    public function test_digest_for_resolves_from_familiar_repo_digest(): void
    {
        $runner = new ProgrammableRunner();
        $runner->on(
            static fn (array $argv): bool => ($argv[1] ?? '') === 'image',
            new CommandResult(0, sprintf('["sqoura/sqoura-backend@%s"]', self::DIGEST), '', 0.01),
        );

        $registry = new OciRegistry($runner, new NullImageSigner());
        $digest = $registry->digestFor(new ImageReference('docker.io/sqoura/sqoura-backend', 'v1'));

        self::assertSame(self::DIGEST, $digest, 'digestFor normalizes both sides of the repo comparison');
    }

    public function test_non_hub_registry_is_not_over_normalized(): void
    {
        $runner = new ProgrammableRunner();
        // A ghcr image whose familiar-looking suffix ("acme/app") must NOT match a bare "acme/app"
        // entry — only Docker Hub references normalize.
        $runner->on(
            static fn (array $argv): bool => ($argv[1] ?? '') === 'image',
            new CommandResult(0, sprintf('["acme/app@%s"]', self::DIGEST), '', 0.01),
        );
        $runner->on(
            static fn (array $argv): bool => $argv[0] === 'crane',
            new CommandResult(0, self::DIGEST, '', 0.01),
        );

        $registry = new OciRegistry($runner, new NullImageSigner());
        // ghcr.io/acme/app does not equal acme/app after normalization, so not local -> falls through.
        $registry->pull(new ImageReference('ghcr.io/acme/app', null, self::DIGEST));

        $pullCalls = array_filter($runner->calls, static fn (array $c): bool => ($c[1] ?? null) === 'pull');
        self::assertCount(1, $pullCalls, 'a non-Hub reference must not match a normalized Hub-style entry');
    }
}

/**
 * A {@see CommandRunnerInterface} that returns the result of the first registered matcher for each
 * argv, falling back to a zero-exit empty result. Records every call for assertions.
 */
final class ProgrammableRunner implements CommandRunnerInterface
{
    /** @var list<list<string>> */
    public array $calls = [];

    /** @var list<array{0: callable(list<string>): bool, 1: CommandResult}> */
    private array $matchers = [];

    /** @param callable(list<string>): bool $matcher */
    public function on(callable $matcher, CommandResult $result): void
    {
        $this->matchers[] = [$matcher, $result];
    }

    public function run(array $argv, ?string $stdin = null, ?float $timeout = null, array $redactTokens = []): CommandResult
    {
        $this->calls[] = $argv;

        foreach ($this->matchers as [$matcher, $result]) {
            if ($matcher($argv)) {
                return $result;
            }
        }

        return new CommandResult(0, '', '', 0.01);
    }
}
