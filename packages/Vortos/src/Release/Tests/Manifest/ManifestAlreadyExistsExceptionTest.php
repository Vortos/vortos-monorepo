<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Manifest;

use PHPUnit\Framework\TestCase;
use Vortos\Release\Manifest\ManifestAlreadyExistsException;

final class ManifestAlreadyExistsExceptionTest extends TestCase
{
    public function test_message_contains_build_id(): void
    {
        $e = ManifestAlreadyExistsException::forBuildId('build-42');
        $this->assertStringContainsString('build-42', $e->getMessage());
    }

    public function test_is_runtime_exception(): void
    {
        $e = ManifestAlreadyExistsException::forBuildId('x');
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }
}
