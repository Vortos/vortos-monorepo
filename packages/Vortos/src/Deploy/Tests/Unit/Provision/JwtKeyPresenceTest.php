<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Provision;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Provision\JwtKeyPresence;

final class JwtKeyPresenceTest extends TestCase
{
    /** @param array<string, string> $env */
    private function presence(array $env): JwtKeyPresence
    {
        return new JwtKeyPresence(static fn (string $name): ?string => $env[$name] ?? null);
    }

    public function test_env_content_keys_count_as_present(): void
    {
        // B14: the immutable-image posture supplies base64-PEM env keys and no file paths at all.
        $presence = $this->presence([
            'JWT_PRIVATE_KEY' => base64_encode('-----BEGIN PRIVATE KEY-----'),
            'JWT_PUBLIC_KEY' => base64_encode('-----BEGIN PUBLIC KEY-----'),
        ]);

        self::assertTrue($presence->present());
        self::assertTrue($presence->envContentPresent());
        self::assertFalse($presence->filePathPresent());
    }

    public function test_file_paths_count_as_present(): void
    {
        $priv = tempnam(sys_get_temp_dir(), 'jwtpriv');
        $pub = tempnam(sys_get_temp_dir(), 'jwtpub');
        self::assertIsString($priv);
        self::assertIsString($pub);

        try {
            $presence = $this->presence([
                'JWT_PRIVATE_KEY_PATH' => $priv,
                'JWT_PUBLIC_KEY_PATH' => $pub,
            ]);

            self::assertTrue($presence->present());
            self::assertTrue($presence->filePathPresent());
            self::assertFalse($presence->envContentPresent());
        } finally {
            @unlink($priv);
            @unlink($pub);
        }
    }

    public function test_absent_when_neither_mode_is_configured(): void
    {
        self::assertFalse($this->presence([])->present());
    }

    public function test_absent_when_only_one_env_content_key_set(): void
    {
        self::assertFalse($this->presence(['JWT_PRIVATE_KEY' => 'x'])->present());
    }

    public function test_absent_when_path_var_points_at_missing_file(): void
    {
        $presence = $this->presence([
            'JWT_PRIVATE_KEY_PATH' => '/no/such/priv.pem',
            'JWT_PUBLIC_KEY_PATH' => '/no/such/pub.pem',
        ]);

        self::assertFalse($presence->present());
    }

    public function test_key_output_dir_is_dirname_of_private_path(): void
    {
        $presence = $this->presence(['JWT_PRIVATE_KEY_PATH' => '/var/www/html/secrets/jwt/priv.pem']);

        self::assertSame('/var/www/html/secrets/jwt', $presence->keyOutputDir());
    }
}
