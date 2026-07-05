<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Runtime\FileSecret;
use Vortos\Deploy\Runtime\FileSecretMaterializer;

/**
 * G8: the materializer writes secrets owner-read-only and wipes them on teardown.
 *
 * Uses /dev/shm (a real tmpfs on Linux) so the FileSecret tmpfs validation is honoured while the test
 * still exercises real filesystem writes.
 */
final class FileSecretMaterializerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $root = is_dir('/dev/shm') ? '/dev/shm' : '/run';
        $this->base = $root . '/vortos-fs-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->base);
    }

    public function test_materialize_writes_owner_read_only_files(): void
    {
        if (!is_writable(\dirname($this->base))) {
            $this->markTestSkipped('No writable tmpfs available.');
        }

        $secret = new FileSecret('jwt', '/run/secrets/jwt.pem', $this->base . '/jwt');
        $materializer = new FileSecretMaterializer();

        $satisfied = $materializer->materialize([$secret], static fn (FileSecret $s): string => 'PLAINTEXT-' . $s->name);

        self::assertSame(['/run/secrets/jwt.pem'], $satisfied);
        self::assertFileExists($this->base . '/jwt');
        self::assertSame('PLAINTEXT-jwt', file_get_contents($this->base . '/jwt'));
        self::assertSame('0400', substr(sprintf('%o', fileperms($this->base . '/jwt')), -4));
    }

    public function test_wipe_zeroizes_and_removes_files(): void
    {
        if (!is_writable(\dirname($this->base))) {
            $this->markTestSkipped('No writable tmpfs available.');
        }

        $secret = new FileSecret('jwt', '/run/secrets/jwt.pem', $this->base . '/jwt');
        $materializer = new FileSecretMaterializer();
        $materializer->materialize([$secret], static fn (): string => 'secret-material');

        $materializer->wipe();

        self::assertFileDoesNotExist($this->base . '/jwt');
    }
}
