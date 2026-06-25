<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Driver\File;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Crypto\SecretEnvelope;
use Vortos\Secrets\Driver\File\FileSecretStore;
use Vortos\Secrets\Exception\SecretNotFoundException;

final class FileSecretStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/vortos-secrets-store-test-' . bin2hex(random_bytes(8)) . '.enc.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_exists_is_false_before_save(): void
    {
        $store = new FileSecretStore($this->path);

        self::assertFalse($store->exists());
    }

    public function test_load_before_save_fails_closed(): void
    {
        $store = new FileSecretStore($this->path);

        $this->expectException(SecretNotFoundException::class);
        $store->load();
    }

    public function test_save_then_load_round_trips(): void
    {
        $store = new FileSecretStore($this->path);
        $envelope = $this->envelope();

        $store->save($envelope);

        self::assertTrue($store->exists());
        self::assertSame($envelope->toArray(), $store->load()->toArray());
    }

    public function test_on_disk_file_contains_no_raw_secret_bytes(): void
    {
        $store = new FileSecretStore($this->path);
        $marker = 'super-secret-marker-value';
        $envelope = new SecretEnvelope(
            1,
            $marker,
            str_repeat("\x00", 24),
            'aad',
            ['default' => 'wrapped'],
            new DateTimeImmutable(),
        );

        $store->save($envelope);

        $onDisk = file_get_contents($this->path);
        self::assertIsString($onDisk);
        self::assertStringNotContainsString($marker, $onDisk);
    }

    public function test_save_creates_missing_directories(): void
    {
        $nestedPath = sys_get_temp_dir() . '/vortos-secrets-nested-' . bin2hex(random_bytes(8)) . '/sub/dir/secrets.enc.json';
        $store = new FileSecretStore($nestedPath);

        $store->save($this->envelope());

        self::assertTrue($store->exists());

        unlink($nestedPath);
        rmdir(dirname($nestedPath));
        rmdir(dirname($nestedPath, 2));
        rmdir(dirname($nestedPath, 3));
    }

    public function test_save_overwrites_previous_envelope_atomically(): void
    {
        $store = new FileSecretStore($this->path);
        $store->save($this->envelope());

        $second = new SecretEnvelope(
            1,
            'second-ciphertext',
            str_repeat("\x01", 24),
            'aad',
            ['default' => 'wrapped-second'],
            new DateTimeImmutable(),
        );
        $store->save($second);

        self::assertSame($second->toArray(), $store->load()->toArray());
    }

    private function envelope(): SecretEnvelope
    {
        return new SecretEnvelope(
            1,
            'ciphertext',
            str_repeat("\x00", 24),
            'aad',
            ['default' => 'wrapped'],
            new DateTimeImmutable('2026-06-23T00:00:00+00:00'),
        );
    }
}
