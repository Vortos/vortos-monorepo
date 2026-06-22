<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Project;
use Vortos\FeatureFlags\ProjectContext;

/**
 * @covers \Vortos\FeatureFlags\ProjectContext
 */
final class ProjectContextTest extends TestCase
{
    public function test_default_project_is_default(): void
    {
        $ctx = new ProjectContext();
        $this->assertSame(ProjectContext::DEFAULT_PROJECT, $ctx->projectId());
        $this->assertSame(Project::DEFAULT_SLUG, $ctx->projectId());
    }

    public function test_withProject_changes_active_project(): void
    {
        $ctx = new ProjectContext();
        $ctx->withProject('mobile');
        $this->assertSame('mobile', $ctx->projectId());
    }

    public function test_withProject_trims_whitespace(): void
    {
        $ctx = new ProjectContext();
        $ctx->withProject('  web  ');
        $this->assertSame('web', $ctx->projectId());
    }

    public function test_withProject_rejects_blank_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProjectContext())->withProject('');
    }

    public function test_withProject_rejects_whitespace_only(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProjectContext())->withProject('   ');
    }

    public function test_withProject_rejects_too_long(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProjectContext())->withProject(str_repeat('x', 192));
    }

    public function test_withProject_accepts_max_length(): void
    {
        $ctx = new ProjectContext();
        $ctx->withProject(str_repeat('x', 191));
        $this->assertSame(str_repeat('x', 191), $ctx->projectId());
    }

    public function test_runAs_restores_previous_project(): void
    {
        $ctx = new ProjectContext();
        $ctx->withProject('initial');

        $inside = null;
        $ctx->runAs('temp', function () use ($ctx, &$inside) {
            $inside = $ctx->projectId();
        });

        $this->assertSame('temp', $inside);
        $this->assertSame('initial', $ctx->projectId());
    }

    public function test_runAs_restores_on_exception(): void
    {
        $ctx = new ProjectContext();
        $ctx->withProject('stable');

        try {
            $ctx->runAs('volatile', function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {}

        $this->assertSame('stable', $ctx->projectId());
    }

    public function test_runAs_returns_callable_return_value(): void
    {
        $ctx    = new ProjectContext();
        $result = $ctx->runAs('proj', fn() => 42);
        $this->assertSame(42, $result);
    }

    public function test_reset_restores_default(): void
    {
        $ctx = new ProjectContext();
        $ctx->withProject('custom');
        $ctx->reset();
        $this->assertSame(ProjectContext::DEFAULT_PROJECT, $ctx->projectId());
    }

    public function test_implements_reset_interface(): void
    {
        $ctx = new ProjectContext();
        $this->assertInstanceOf(\Symfony\Contracts\Service\ResetInterface::class, $ctx);
    }

    public function test_constant_matches_project_slug(): void
    {
        $this->assertSame(Project::DEFAULT_SLUG, ProjectContext::DEFAULT_PROJECT);
    }
}
