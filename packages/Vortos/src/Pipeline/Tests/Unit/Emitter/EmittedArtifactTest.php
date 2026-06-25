<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Emitter;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Emitter\EmittedArtifact;

final class EmittedArtifactTest extends TestCase
{
    public function test_valid_construction(): void
    {
        $artifact = new EmittedArtifact('.github/workflows/ci.yml', 'contents', 'CI workflow');
        $this->assertSame('.github/workflows/ci.yml', $artifact->relativePath);
        $this->assertSame('contents', $artifact->contents);
        $this->assertSame('CI workflow', $artifact->description);
        $this->assertFalse($artifact->isExecutable);
    }

    public function test_executable_flag(): void
    {
        $artifact = new EmittedArtifact('script.sh', 'contents', 'Script', isExecutable: true);
        $this->assertTrue($artifact->isExecutable);
    }

    public function test_empty_path_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty');
        new EmittedArtifact('', 'contents', 'desc');
    }

    public function test_absolute_path_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('relative');
        new EmittedArtifact('/etc/passwd', 'contents', 'desc');
    }

    public function test_path_with_dotdot_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('..');
        new EmittedArtifact('../escape/file.yml', 'contents', 'desc');
    }

    public function test_path_with_embedded_dotdot_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmittedArtifact('a/../b/file.yml', 'contents', 'desc');
    }
}
