<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Export;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Iac\Exception\PathViolationException;
use Vortos\Iac\Export\PathPolicy;

final class PathPolicyTest extends TestCase
{
    public function test_accepts_normal_relative_paths(): void
    {
        PathPolicy::validate('infra/kafka_topics.tf.json');
        PathPolicy::validate('terraform/env/prod-topics.tf.json');
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenPaths(): iterable
    {
        yield 'empty' => [''];
        yield 'wrong extension' => ['infra/topics.tf'];
        yield 'plain json' => ['infra/topics.json'];
        yield 'absolute' => ['/etc/topics.tf.json'];
        yield 'windows drive' => ['C:/x/topics.tf.json'];
        yield 'backslashes' => ['infra\\topics.tf.json'];
        yield 'parent traversal' => ['../outside/topics.tf.json'];
        yield 'embedded traversal' => ['infra/../../outside/topics.tf.json'];
        yield 'dot segment' => ['./infra/topics.tf.json'];
        yield 'hidden segment' => ['.git/topics.tf.json'];
        yield 'empty segment' => ['infra//topics.tf.json'];
    }

    #[DataProvider('forbiddenPaths')]
    public function test_rejects_forbidden_paths(string $path): void
    {
        $this->expectException(PathViolationException::class);
        PathPolicy::validate($path);
    }

    public function test_resolve_inside_rejects_symlink_escape(): void
    {
        $project = sys_get_temp_dir() . '/vortos-iac-pp-' . uniqid();
        $outside = sys_get_temp_dir() . '/vortos-iac-outside-' . uniqid();
        mkdir($project, 0755, true);
        mkdir($outside, 0755, true);
        symlink($outside, $project . '/infra');

        try {
            $this->expectException(PathViolationException::class);
            $this->expectExceptionMessage('escapes the project directory');
            PathPolicy::resolveInside($project, 'infra/topics.tf.json');
        } finally {
            unlink($project . '/infra');
            rmdir($project);
            rmdir($outside);
        }
    }

    public function test_resolve_inside_creates_directories_and_returns_target(): void
    {
        $project = sys_get_temp_dir() . '/vortos-iac-pp-' . uniqid();
        mkdir($project, 0755, true);

        try {
            $target = PathPolicy::resolveInside($project, 'infra/topics.tf.json');

            $this->assertDirectoryExists($project . '/infra');
            $this->assertStringEndsWith('/infra/topics.tf.json', $target);
        } finally {
            @rmdir($project . '/infra');
            @rmdir($project);
        }
    }
}
