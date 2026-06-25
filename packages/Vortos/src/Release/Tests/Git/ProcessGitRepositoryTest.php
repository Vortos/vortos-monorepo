<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Git;

use PHPUnit\Framework\TestCase;
use Vortos\Release\Git\GitCommandException;
use Vortos\Release\Git\Process\ProcessGitRepository;

final class ProcessGitRepositoryTest extends TestCase
{
    private string $repoDir;
    private ProcessGitRepository $git;

    protected function setUp(): void
    {
        $this->repoDir = sys_get_temp_dir() . '/vortos-git-test-' . bin2hex(random_bytes(8));
        mkdir($this->repoDir, 0o755, true);

        $this->exec('git init');
        $this->exec('git config user.email "test@test.com"');
        $this->exec('git config user.name "Test"');

        file_put_contents($this->repoDir . '/README.md', 'init');
        $this->exec('git add .');
        $this->exec('git commit -m "feat: initial commit"');

        $this->git = new ProcessGitRepository($this->repoDir);
    }

    protected function tearDown(): void
    {
        $this->cleanup($this->repoDir);
    }

    public function test_current_sha(): void
    {
        $sha = $this->git->currentSha();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $sha);
    }

    public function test_is_clean(): void
    {
        $this->assertTrue($this->git->isClean());

        file_put_contents($this->repoDir . '/dirty.txt', 'dirty');
        $this->assertFalse($this->git->isClean());
    }

    public function test_current_branch(): void
    {
        $branch = $this->git->currentBranch();
        $this->assertContains($branch, ['main', 'master']);
    }

    public function test_tags_matching(): void
    {
        $sha = $this->git->currentSha();
        $this->git->createAnnotatedTag('v1.0.0-alpha-100', $sha, 'test tag');
        $this->git->createAnnotatedTag('v1.0.0-alpha-101', $sha, 'test tag 2');
        $this->git->createAnnotatedTag('v2.0.0', $sha, 'other tag');

        $tags = $this->git->tagsMatching('v1.0.0-alpha-');
        $this->assertCount(2, $tags);
        $this->assertContains('v1.0.0-alpha-100', $tags);
        $this->assertContains('v1.0.0-alpha-101', $tags);
    }

    public function test_tags_matching_empty(): void
    {
        $tags = $this->git->tagsMatching('nonexistent-');
        $this->assertSame([], $tags);
    }

    public function test_commits_between(): void
    {
        $sha1 = $this->git->currentSha();
        $this->git->createAnnotatedTag('v1.0.0-alpha-1', $sha1, 'first');

        file_put_contents($this->repoDir . '/file2.txt', 'content');
        $this->exec('git add .');
        $this->exec('git commit -m "fix: resolve bug"');

        file_put_contents($this->repoDir . '/file3.txt', 'content');
        $this->exec('git add .');
        $this->exec('git commit -m "feat: add feature"');

        $commits = $this->git->commitsBetween('v1.0.0-alpha-1', 'HEAD');
        $this->assertCount(2, $commits);
        $this->assertStringContainsString('feat: add feature', $commits[0]->rawMessage);
        $this->assertStringContainsString('fix: resolve bug', $commits[1]->rawMessage);
    }

    public function test_commits_between_null_from(): void
    {
        $commits = $this->git->commitsBetween(null, 'HEAD');
        $this->assertGreaterThanOrEqual(1, \count($commits));
    }

    public function test_create_and_verify_tag(): void
    {
        $sha = $this->git->currentSha();
        $this->git->createAnnotatedTag('v-test-tag', $sha, 'test message');

        $this->assertTrue($this->git->tagExists('v-test-tag'));
        $tagSha = $this->git->tagSha('v-test-tag');
        $this->assertSame($sha, $tagSha);
    }

    public function test_delete_local_tag(): void
    {
        $sha = $this->git->currentSha();
        $this->git->createAnnotatedTag('v-delete-me', $sha, 'delete');
        $this->assertTrue($this->git->tagExists('v-delete-me'));

        $this->git->deleteLocalTag('v-delete-me');
        $this->assertFalse($this->git->tagExists('v-delete-me'));
    }

    public function test_tag_exists_false(): void
    {
        $this->assertFalse($this->git->tagExists('no-such-tag'));
    }

    public function test_tag_sha_null_for_nonexistent(): void
    {
        $this->assertNull($this->git->tagSha('no-such-tag'));
    }

    public function test_tree_sha_for_path(): void
    {
        mkdir($this->repoDir . '/subdir', 0o755, true);
        file_put_contents($this->repoDir . '/subdir/file.txt', 'x');
        $this->exec('git add .');
        $this->exec('git commit -m "chore: add subdir"');

        $sha = $this->git->treeShaForPath('subdir');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $sha);
    }

    public function test_tree_sha_throws_for_invalid_path(): void
    {
        $this->expectException(GitCommandException::class);
        $this->git->treeShaForPath('nonexistent/path');
    }

    private function exec(string $command): void
    {
        $fullCommand = sprintf('cd %s && %s 2>&1', escapeshellarg($this->repoDir), $command);
        exec($fullCommand, $output, $exitCode);
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
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
