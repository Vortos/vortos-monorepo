<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Lifecycle\IacWorkspace;

final class IacWorkspaceTest extends TestCase
{
    public function test_valid_creation(): void
    {
        $ws = new IacWorkspace('dev', '/tmp/infra', 'dev');
        $this->assertSame('dev', $ws->environment);
        $this->assertSame('/tmp/infra', $ws->workingDir);
        $this->assertSame('dev', $ws->stateKey);
    }

    public function test_for_environment_factory(): void
    {
        $ws = IacWorkspace::forEnvironment('staging', '/tmp/infra');
        $this->assertSame('staging', $ws->environment);
        $this->assertSame('/tmp/infra', $ws->workingDir);
        $this->assertSame('staging', $ws->stateKey);
    }

    public function test_environment_with_hyphens_and_digits(): void
    {
        $ws = new IacWorkspace('us-east-1', '/tmp/infra', 'key');
        $this->assertSame('us-east-1', $ws->environment);
    }

    public function test_invalid_env_name_uppercase_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacWorkspace('Dev', '/tmp/infra', 'dev');
    }

    public function test_invalid_env_name_starts_with_digit_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacWorkspace('1dev', '/tmp/infra', 'dev');
    }

    public function test_invalid_env_name_starts_with_hyphen_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacWorkspace('-dev', '/tmp/infra', 'dev');
    }

    public function test_invalid_env_name_empty_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacWorkspace('', '/tmp/infra', 'dev');
    }

    public function test_invalid_env_name_special_chars_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacWorkspace('dev_test', '/tmp/infra', 'dev');
    }

    public function test_empty_working_dir_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacWorkspace('dev', '', 'dev');
    }

    public function test_empty_state_key_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacWorkspace('dev', '/tmp/infra', '');
    }
}
