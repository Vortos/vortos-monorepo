<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Release\Audit\NullReleaseAuditEmitter;
use Vortos\Release\Console\ReleaseTagCommand;
use Vortos\Release\Git\GitRemoteResolver;
use Vortos\Release\Git\GitRepositoryInterface;
use Vortos\Release\Git\RawCommit;
use Vortos\Release\Service\ChangelogGenerator;
use Vortos\Release\Service\CoordinatedTagger;
use Vortos\Release\Service\PackageDiscovery;
use Vortos\Release\Service\ReleasePlanner;
use Vortos\Release\Service\VersionSkewGuard;
use Vortos\Release\Tagging\File\FileTaggingTransactionStore;
use Vortos\Release\Version\AlphaCounterStrategy;
use Vortos\Release\Version\BumpCalculator;
use Vortos\Release\Version\ConventionalCommitParser;

final class ReleaseTagCommandTest extends TestCase
{
    private string $tempDir;
    private string $pkgDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/vortos-cmd-test-' . bin2hex(random_bytes(8));
        $this->pkgDir = $this->tempDir . '/packages';
        mkdir($this->tempDir . '/transactions', 0o755, true);
        mkdir($this->pkgDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->cleanup($this->tempDir);
    }

    public function test_dry_run_default(): void
    {
        $tester = $this->createTester(isClean: true, hasCommits: true);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Dry-run complete', $output);
        $this->assertStringContainsString('--apply', $output);
    }

    public function test_dirty_tree_rejected(): void
    {
        $tester = $this->createTester(isClean: false, hasCommits: true);
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('dirty', $tester->getDisplay());
    }

    public function test_dirty_tree_allowed_with_flag(): void
    {
        $tester = $this->createTester(isClean: false, hasCommits: true);
        $tester->execute(['--allow-dirty' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Dry-run complete', $tester->getDisplay());
    }

    public function test_json_output(): void
    {
        $tester = $this->createTester(isClean: true, hasCommits: true);
        $tester->execute(['--json' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('tx_id', $decoded);
        $this->assertArrayHasKey('packages', $decoded);
    }

    public function test_no_packages_found(): void
    {
        $emptyPkgDir = $this->tempDir . '/empty-packages';
        mkdir($emptyPkgDir, 0o755, true);

        $git = $this->createGitMock(isClean: true, hasCommits: true);
        $discovery = new PackageDiscovery($emptyPkgDir);
        $command = $this->buildCommand($git, $discovery);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('No vortos', $tester->getDisplay());
    }

    public function test_invalid_bump_option(): void
    {
        $tester = $this->createTester(isClean: true, hasCommits: true);
        $tester->execute(['--bump' => 'invalid']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid --bump', $tester->getDisplay());
    }

    public function test_no_releasable_changes(): void
    {
        $tester = $this->createTester(isClean: true, hasCommits: false);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('No releasable changes', $tester->getDisplay());
    }

    public function test_package_filter(): void
    {
        $tester = $this->createTester(isClean: true, hasCommits: true);
        $tester->execute(['--package' => ['vortos/vortos-test-pkg']]);

        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_package_filter_no_match(): void
    {
        $tester = $this->createTester(isClean: true, hasCommits: true);
        $tester->execute(['--package' => ['nonexistent/pkg']]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('No matching packages', $tester->getDisplay());
    }

    private function createTester(bool $isClean, bool $hasCommits): CommandTester
    {
        $git = $this->createGitMock($isClean, $hasCommits);
        $this->seedPackageDir();
        $discovery = new PackageDiscovery($this->pkgDir);

        return new CommandTester($this->buildCommand($git, $discovery));
    }

    private function seedPackageDir(): void
    {
        $dir = $this->pkgDir . '/TestPkg';
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
            file_put_contents($dir . '/composer.json', json_encode([
                'name' => 'vortos/vortos-test-pkg',
                'extra' => ['vortos' => ['order' => 130]],
            ]));
        }
    }

    private function createGitMock(bool $isClean, bool $hasCommits): GitRepositoryInterface
    {
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->method('isClean')->willReturn($isClean);
        $git->method('currentSha')->willReturn(str_repeat('a', 40));
        $git->method('currentBranch')->willReturn('main');
        $git->method('tagsMatching')->willReturn(['v1.0.0-alpha-160']);

        $commits = $hasCommits ? [
            new RawCommit('abc1234', 'feat: add feature', new \DateTimeImmutable('2026-06-23')),
        ] : [];

        $git->method('commitsBetween')->willReturn($commits);
        $git->method('tagExists')->willReturn(false);

        return $git;
    }

    private function buildCommand(GitRepositoryInterface $git, PackageDiscovery $discovery): ReleaseTagCommand
    {
        $parser = new ConventionalCommitParser();
        $strategy = new AlphaCounterStrategy();
        $changelogGen = new ChangelogGenerator($parser);
        $planner = new ReleasePlanner(new BumpCalculator(), $changelogGen, $strategy);
        $store = new FileTaggingTransactionStore($this->tempDir . '/transactions');
        $tagger = new CoordinatedTagger($git, $store, new NullReleaseAuditEmitter());
        $skewGuard = new VersionSkewGuard($git, $this->pkgDir);
        $remoteResolver = new GitRemoteResolver();

        return new ReleaseTagCommand(
            $git,
            $discovery,
            $strategy,
            $planner,
            $tagger,
            $skewGuard,
            $remoteResolver,
            $parser,
        );
    }

    private function cleanup(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? $this->cleanup($file) : unlink($file);
        }

        rmdir($dir);
    }
}
