<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Runtime\FileSecret;

/**
 * G8: file-shaped secret spec — tmpfs-only host paths, absolute container paths, RO compose volume.
 */
final class FileSecretTest extends TestCase
{
    public function test_valid_file_secret_exposes_compose_volume_and_dir(): void
    {
        $secret = new FileSecret('jwt_private_key', '/run/secrets/jwt.pem', '/run/vortos-secrets/jwt_private_key');

        self::assertSame('/run/vortos-secrets/jwt_private_key:/run/secrets/jwt.pem:ro', $secret->composeVolume());
        self::assertSame('/run/vortos-secrets', $secret->hostDirectory());
        self::assertSame('0400', $secret->toArray()['mode']);
    }

    public function test_host_path_must_be_on_tmpfs(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tmpfs/');

        new FileSecret('k', '/run/secrets/k', '/opt/vortos/k'); // /opt is not tmpfs
    }

    public function test_dev_shm_host_path_is_allowed(): void
    {
        $secret = new FileSecret('k', '/run/secrets/k', '/dev/shm/vortos/k');

        self::assertSame('/dev/shm/vortos/k:/run/secrets/k:ro', $secret->composeVolume());
    }

    public function test_container_path_must_be_absolute(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FileSecret('k', 'relative/path', '/run/vortos-secrets/k');
    }

    public function test_name_must_not_contain_whitespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FileSecret('bad name', '/run/secrets/k', '/run/vortos-secrets/k');
    }
}
