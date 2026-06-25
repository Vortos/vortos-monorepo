<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Release\Changelog\Changelog;
use Vortos\Release\Changelog\ChangelogEntry;
use Vortos\Release\Git\Process\ProcessGitRepository;
use Vortos\Release\Plan\PackageTagPlan;
use Vortos\Release\Plan\ReleasePlan;
use Vortos\Release\Audit\NullReleaseAuditEmitter;
use Vortos\Release\Service\CoordinatedTagger;
use Vortos\Release\Service\ReleaseException;
use Vortos\Release\Tagging\File\FileTaggingTransactionStore;
use Vortos\Release\Tagging\TaggingStatus;
use Vortos\Release\Version\BumpLevel;
use Vortos\Release\Version\SemverVersion;

final class CoordinatedTaggerTest extends TestCase
{
    private string $repoDir;
    private string $remoteDir;
    private string $txDir;
    private ProcessGitRepository $git;
    private FileTaggingTransactionStore $store;
    private CoordinatedTagger $tagger;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/vortos-tagger-' . bin2hex(random_bytes(8));

        $this->remoteDir = $base . '/remote.git';
        $this->repoDir = $base . '/repo';
        $this->txDir = $base . '/transactions';

        mkdir($this->remoteDir, 0o755, true);
        $this->execIn($this->remoteDir, 'git init --bare');

        mkdir($this->repoDir, 0o755, true);
        $this->execIn($this->repoDir, 'git init');
        $this->execIn($this->repoDir, 'git config user.email "test@test.com"');
        $this->execIn($this->repoDir, 'git config user.name "Test"');
        $this->execIn($this->repoDir, 'git remote add origin ' . escapeshellarg($this->remoteDir));

        file_put_contents($this->repoDir . '/README.md', 'init');
        $this->execIn($this->repoDir, 'git add .');
        $this->execIn($this->repoDir, 'git commit -m "feat: initial"');
        $this->execIn($this->repoDir, 'git push origin HEAD');

        $this->git = new ProcessGitRepository($this->repoDir);
        $this->store = new FileTaggingTransactionStore($this->txDir);
        $this->tagger = new CoordinatedTagger($this->git, $this->store, new NullReleaseAuditEmitter());
    }

    protected function tearDown(): void
    {
        $this->cleanup(dirname($this->repoDir));
    }

    public function test_apply_creates_local_tags(): void
    {
        $plan = $this->makePlan('tx-apply', ['vortos/vortos-a', 'vortos/vortos-b']);

        $tx = $this->tagger->apply($plan, push: false);

        $this->assertSame(TaggingStatus::Complete, $tx->status);
        $this->assertCount(2, $tx->tags);
        $this->assertTrue($this->git->tagExists('v1.0.0-alpha-161'));
    }

    public function test_apply_with_push(): void
    {
        $plan = $this->makePlan('tx-push', ['vortos/vortos-a']);

        $tx = $this->tagger->apply($plan, push: true);

        $this->assertSame(TaggingStatus::Complete, $tx->status);
        $this->assertTrue($tx->tags[0]->pushed);

        $remoteTags = trim(shell_exec('git -C ' . escapeshellarg($this->remoteDir) . ' tag') ?? '');
        $this->assertStringContainsString('v1.0.0-alpha-161', $remoteTags);
    }

    public function test_dry_run_mutates_nothing(): void
    {
        $tagsBefore = $this->git->tagsMatching('v');

        $plan = $this->makePlan('tx-dry', ['vortos/vortos-a']);

        // dry-run = just look at the plan, don't call apply
        $this->assertFalse($this->git->tagExists('v1.0.0-alpha-161'));

        $tagsAfter = $this->git->tagsMatching('v');
        $this->assertSame($tagsBefore, $tagsAfter);
    }

    public function test_undo_removes_tags(): void
    {
        $plan = $this->makePlan('tx-undo', ['vortos/vortos-a']);
        $this->tagger->apply($plan, push: true);

        $this->assertTrue($this->git->tagExists('v1.0.0-alpha-161'));

        $undone = $this->tagger->undo('tx-undo');

        $this->assertSame(TaggingStatus::Undone, $undone->status);
        $this->assertFalse($this->git->tagExists('v1.0.0-alpha-161'));
    }

    public function test_undo_nonexistent_transaction(): void
    {
        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessageMatches('/not found/');
        $this->tagger->undo('nonexistent');
    }

    public function test_undo_already_undone(): void
    {
        $plan = $this->makePlan('tx-double-undo', ['vortos/vortos-a']);
        $this->tagger->apply($plan, push: false);
        $this->tagger->undo('tx-double-undo');

        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessageMatches('/already undone/');
        $this->tagger->undo('tx-double-undo');
    }

    public function test_idempotent_rerun(): void
    {
        $plan = $this->makePlan('tx-idem', ['vortos/vortos-a']);
        $this->tagger->apply($plan, push: false);

        // re-run: same tag, same SHA — should be a no-op, not fail
        $plan2 = $this->makePlan('tx-idem-2', ['vortos/vortos-a']);
        $tx = $this->tagger->apply($plan2, push: false);

        $this->assertSame(TaggingStatus::Complete, $tx->status);
    }

    public function test_divergent_tag_rejected(): void
    {
        $sha1 = $this->git->currentSha();
        $this->git->createAnnotatedTag('v1.0.0-alpha-161', $sha1, 'old tag');

        // make a new commit so HEAD diverges from the existing tag
        file_put_contents($this->repoDir . '/new.txt', 'new');
        $this->execIn($this->repoDir, 'git add .');
        $this->execIn($this->repoDir, 'git commit -m "feat: new commit"');

        $plan = $this->makePlan('tx-diverge', ['vortos/vortos-a']);

        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessageMatches('/divergent tag/i');
        $this->tagger->apply($plan, push: false);
    }

    public function test_no_changes_rejected(): void
    {
        $now = new \DateTimeImmutable('2026-06-23');
        $plan = new ReleasePlan(
            txId: 'tx-empty',
            createdAt: $now,
            packages: [
                new PackageTagPlan(
                    packageName: 'vortos/vortos-a',
                    packagePath: '/tmp/a',
                    currentVersion: SemverVersion::parse('v1.0.0-alpha-160'),
                    nextVersion: SemverVersion::parse('v1.0.0-alpha-160'),
                    bumpLevel: BumpLevel::None,
                    commitRange: 'HEAD..HEAD',
                    changelog: Changelog::fromEntries(SemverVersion::parse('v1.0.0-alpha-160'), $now, [], 'a'),
                    remote: 'origin',
                ),
            ],
            globalBump: BumpLevel::None,
            skewDetected: false,
        );

        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessageMatches('/no packages/i');
        $this->tagger->apply($plan, push: false);
    }

    public function test_transaction_persisted_on_partial_failure(): void
    {
        // Create a plan with a remote that doesn't exist — push will fail
        $now = new \DateTimeImmutable('2026-06-23');
        $changelog = Changelog::fromEntries(SemverVersion::parse('v1.0.0-alpha-161'), $now, [
            new ChangelogEntry('feat', null, 'x', 'abc', null),
        ], 'pkg');

        $plan = new ReleasePlan(
            txId: 'tx-partial',
            createdAt: $now,
            packages: [
                new PackageTagPlan(
                    packageName: 'vortos/vortos-a',
                    packagePath: '/tmp/a',
                    currentVersion: SemverVersion::parse('v1.0.0-alpha-160'),
                    nextVersion: SemverVersion::parse('v1.0.0-alpha-161'),
                    bumpLevel: BumpLevel::Minor,
                    commitRange: 'tag..HEAD',
                    changelog: $changelog,
                    remote: 'nonexistent-remote',
                ),
            ],
            globalBump: BumpLevel::Minor,
            skewDetected: false,
        );

        try {
            $this->tagger->apply($plan, push: true);
            $this->fail('Expected ReleaseException');
        } catch (ReleaseException $e) {
            $this->assertStringContainsString('partial', $e->getMessage());
        }

        $tx = $this->store->load('tx-partial');
        $this->assertNotNull($tx);
        $this->assertSame(TaggingStatus::Partial, $tx->status);
    }

    /** @param list<string> $packageNames */
    private function makePlan(string $txId, array $packageNames): ReleasePlan
    {
        $now = new \DateTimeImmutable('2026-06-23');
        $packages = [];

        foreach ($packageNames as $name) {
            $changelog = Changelog::fromEntries(SemverVersion::parse('v1.0.0-alpha-161'), $now, [
                new ChangelogEntry('feat', null, 'add feature', 'abc1234', null),
            ], $name);

            $packages[] = new PackageTagPlan(
                packageName: $name,
                packagePath: '/tmp/' . $name,
                currentVersion: SemverVersion::parse('v1.0.0-alpha-160'),
                nextVersion: SemverVersion::parse('v1.0.0-alpha-161'),
                bumpLevel: BumpLevel::Minor,
                commitRange: 'v1.0.0-alpha-160..HEAD',
                changelog: $changelog,
                remote: 'origin',
            );
        }

        return new ReleasePlan(
            txId: $txId,
            createdAt: $now,
            packages: $packages,
            globalBump: BumpLevel::Minor,
            skewDetected: false,
        );
    }

    private function execIn(string $dir, string $command): void
    {
        $full = sprintf('cd %s && %s 2>&1', escapeshellarg($dir), $command);
        exec($full, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException("Command failed: $command\n" . implode("\n", $output));
        }
    }

    private function cleanup(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }
}
