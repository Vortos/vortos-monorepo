<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Compose;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ComposeFile;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Target\ActiveColor;

final class ComposeFileTest extends TestCase
{
    private static function digestPinnedImage(): ImageReference
    {
        return new ImageReference('myrepo/app', 'latest', 'sha256:' . str_repeat('ab', 32));
    }

    public function test_rejects_non_digest_pinned_image(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ComposeFile(
            'project',
            ActiveColor::Blue,
            new ImageReference('repo', 'latest'),
            'serve',
            'worker',
            8081,
        );
    }

    public function test_to_array_structure(): void
    {
        $compose = new ComposeFile(
            'vortos-app-blue',
            ActiveColor::Blue,
            self::digestPinnedImage(),
            'php-server',
            'php bin/console messenger:consume',
            8081,
        );

        $array = $compose->toArray();

        $this->assertArrayHasKey('services', $array);
        $this->assertArrayHasKey('app-blue', $array['services']);
        $this->assertArrayHasKey('worker-blue', $array['services']);
        $this->assertArrayHasKey('networks', $array);
    }

    public function test_both_services_share_same_image(): void
    {
        $compose = new ComposeFile(
            'vortos-app-green',
            ActiveColor::Green,
            self::digestPinnedImage(),
            'serve',
            'work',
            8082,
        );

        $array = $compose->toArray();
        $this->assertSame(
            $array['services']['app-green']['image'],
            $array['services']['worker-green']['image'],
        );
    }

    public function test_image_is_digest_pinned_in_output(): void
    {
        $compose = new ComposeFile(
            'vortos-app-blue',
            ActiveColor::Blue,
            self::digestPinnedImage(),
            'serve',
            'work',
            8081,
        );

        $array = $compose->toArray();
        $this->assertStringContainsString('sha256:', $array['services']['app-blue']['image']);
    }

    public function test_env_file_by_reference(): void
    {
        $compose = new ComposeFile(
            'vortos-app-blue',
            ActiveColor::Blue,
            self::digestPinnedImage(),
            'serve',
            'work',
            8081,
            envFile: '/run/secrets/app.env',
        );

        $array = $compose->toArray();
        $this->assertContains('/run/secrets/app.env', $array['services']['app-blue']['env_file']);
        $this->assertContains('/run/secrets/app.env', $array['services']['worker-blue']['env_file']);
    }

    public function test_no_env_file_when_null(): void
    {
        $compose = new ComposeFile(
            'vortos-app-blue',
            ActiveColor::Blue,
            self::digestPinnedImage(),
            'serve',
            'work',
            8081,
        );

        $array = $compose->toArray();
        $this->assertArrayNotHasKey('env_file', $array['services']['app-blue']);
    }
}
