<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Release\Git\GitRepositoryInterface;
use Vortos\Release\Service\VersionResolver;
use Vortos\Release\Version\AlphaCounterStrategy;

final class VersionResolverTest extends TestCase
{
    public function test_resolves_highest_version(): void
    {
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->method('tagsMatching')
            ->with('v1.0.0-alpha-')
            ->willReturn(['v1.0.0-alpha-100', 'v1.0.0-alpha-160', 'v1.0.0-alpha-105']);

        $resolver = new VersionResolver($git, new AlphaCounterStrategy());
        $version = $resolver->currentVersion();

        $this->assertSame('alpha-160', $version->prerelease);
    }

    public function test_returns_zero_when_no_tags(): void
    {
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->method('tagsMatching')->willReturn([]);

        $resolver = new VersionResolver($git, new AlphaCounterStrategy());
        $version = $resolver->currentVersion();

        $this->assertSame(0, $version->major);
        $this->assertSame(0, $version->minor);
        $this->assertSame(0, $version->patch);
    }

    public function test_latest_tag(): void
    {
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->method('tagsMatching')
            ->willReturn(['v1.0.0-alpha-100', 'v1.0.0-alpha-160', 'v1.0.0-alpha-50']);

        $resolver = new VersionResolver($git, new AlphaCounterStrategy());
        $tag = $resolver->latestTag();

        $this->assertSame('v1.0.0-alpha-160', $tag);
    }

    public function test_latest_tag_null_when_empty(): void
    {
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->method('tagsMatching')->willReturn([]);

        $resolver = new VersionResolver($git, new AlphaCounterStrategy());
        $this->assertNull($resolver->latestTag());
    }
}
