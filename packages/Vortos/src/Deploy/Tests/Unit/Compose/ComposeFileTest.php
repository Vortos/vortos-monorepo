<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Compose;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ComposeFile;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\Target\ActiveColor;

final class ComposeFileTest extends TestCase
{
    private static function digestPinnedImage(): ImageReference
    {
        return new ImageReference('myrepo/app', 'latest', 'sha256:' . str_repeat('ab', 32));
    }

    private static function spec(): RuntimeServiceSpec
    {
        return new RuntimeServiceSpec(
            command: ['frankenphp', 'run', '--config', '/etc/frankenphp/Caddyfile'],
            containerPort: 8080,
            envFiles: ['/opt/vortos/.env.prod'],
            workerCommand: ['/usr/bin/supervisord', '-c', '/etc/supervisord.conf'],
            environment: ['SERVER_NAME' => ':8080'],
        );
    }

    public function test_rejects_non_digest_pinned_image(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ComposeFile(
            'project',
            ActiveColor::Blue,
            new ImageReference('repo', 'latest'),
            self::spec(),
        );
    }

    public function test_to_array_structure(): void
    {
        $compose = new ComposeFile('vortos-app-blue', ActiveColor::Blue, self::digestPinnedImage(), self::spec());

        $array = $compose->toArray();

        $this->assertArrayHasKey('services', $array);
        $this->assertArrayHasKey('app-blue', $array['services']);
        $this->assertArrayHasKey('worker-blue', $array['services']);
        $this->assertArrayHasKey('networks', $array);
    }

    public function test_worker_healthcheck_overrides_inherited_http_check(): void
    {
        // GAP-G: the worker service must always emit an explicit healthcheck so it never inherits the
        // base image's HTTP HEALTHCHECK. Default supervisord worker ⇒ a supervisorctl-based check.
        $array = (new ComposeFile('p', ActiveColor::Blue, self::digestPinnedImage(), self::spec()))->toArray();

        $worker = $array['services']['worker-blue'];
        $this->assertArrayHasKey('healthcheck', $worker);
        $this->assertSame('CMD-SHELL', $worker['healthcheck']['test'][0]);
        $this->assertStringContainsString('supervisorctl', $worker['healthcheck']['test'][1]);

        // The app service is untouched — its FrankenPHP admin healthcheck is correct.
        $this->assertArrayNotHasKey('healthcheck', $array['services']['app-blue']);
    }

    public function test_custom_worker_emits_disabled_healthcheck(): void
    {
        $spec = new RuntimeServiceSpec(
            command: ['frankenphp', 'run'],
            workerCommand: ['php', 'bin/console', 'messenger:consume'],
        );
        $array = (new ComposeFile('p', ActiveColor::Green, self::digestPinnedImage(), $spec))->toArray();

        $this->assertSame(['disable' => true], $array['services']['worker-green']['healthcheck']);
    }

    public function test_file_secrets_render_read_only_volumes_on_app_and_worker(): void
    {
        $spec = new RuntimeServiceSpec(
            fileSecrets: [
                new \Vortos\Deploy\Runtime\FileSecret('jwt', '/run/secrets/jwt.pem', '/run/vortos-secrets/jwt'),
            ],
        );

        $array = (new ComposeFile('vortos-app-blue', ActiveColor::Blue, self::digestPinnedImage(), $spec))->toArray();

        $expected = ['/run/vortos-secrets/jwt:/run/secrets/jwt.pem:ro'];
        self::assertSame($expected, $array['services']['app-blue']['volumes']);
        self::assertSame($expected, $array['services']['worker-blue']['volumes']);
    }

    public function test_no_volumes_key_when_no_file_secrets(): void
    {
        $array = (new ComposeFile('p', ActiveColor::Blue, self::digestPinnedImage(), self::spec()))->toArray();

        self::assertArrayNotHasKey('volumes', $array['services']['app-blue']);
    }

    public function test_renders_real_command_never_a_stub(): void
    {
        $compose = new ComposeFile('vortos-app-blue', ActiveColor::Blue, self::digestPinnedImage(), self::spec());
        $array = $compose->toArray();

        // B16: the command is the app's real argv, never the hardcoded `php-server` stub.
        $this->assertSame(
            ['frankenphp', 'run', '--config', '/etc/frankenphp/Caddyfile'],
            $array['services']['app-blue']['command'],
        );
        $this->assertNotSame('php-server', $array['services']['app-blue']['command']);
    }

    public function test_app_color_is_internal_only_no_host_ports(): void
    {
        $compose = new ComposeFile('vortos-app-blue', ActiveColor::Blue, self::digestPinnedImage(), self::spec());
        $array = $compose->toArray();

        // Edge-router topology: colors publish NO host ports; they only `expose` the container port.
        $this->assertArrayNotHasKey('ports', $array['services']['app-blue']);
        $this->assertSame(['8080'], $array['services']['app-blue']['expose']);
    }

    public function test_networks_are_external(): void
    {
        $compose = new ComposeFile('vortos-app-blue', ActiveColor::Blue, self::digestPinnedImage(), self::spec());
        $array = $compose->toArray();

        $this->assertSame(['external' => true], $array['networks']['vortos-net']);
    }

    public function test_both_services_share_same_image(): void
    {
        $compose = new ComposeFile('vortos-app-green', ActiveColor::Green, self::digestPinnedImage(), self::spec());

        $array = $compose->toArray();
        $this->assertSame(
            $array['services']['app-green']['image'],
            $array['services']['worker-green']['image'],
        );
    }

    public function test_image_is_digest_pinned_in_output(): void
    {
        $compose = new ComposeFile('vortos-app-blue', ActiveColor::Blue, self::digestPinnedImage(), self::spec());

        $array = $compose->toArray();
        $this->assertStringContainsString('sha256:', $array['services']['app-blue']['image']);
    }

    public function test_env_file_wired_into_both_services(): void
    {
        $compose = new ComposeFile('vortos-app-blue', ActiveColor::Blue, self::digestPinnedImage(), self::spec());

        $array = $compose->toArray();
        // B16: without env_file the color booted with none of its DB/secrets config.
        $this->assertContains('/opt/vortos/.env.prod', $array['services']['app-blue']['env_file']);
        $this->assertContains('/opt/vortos/.env.prod', $array['services']['worker-blue']['env_file']);
    }

    public function test_app_environment_applied_to_app_service_only(): void
    {
        $compose = new ComposeFile('vortos-app-blue', ActiveColor::Blue, self::digestPinnedImage(), self::spec());
        $array = $compose->toArray();

        $this->assertSame(':8080', $array['services']['app-blue']['environment']['SERVER_NAME']);
        $this->assertArrayNotHasKey('environment', $array['services']['worker-blue']);
    }

    public function test_no_env_file_when_spec_has_none(): void
    {
        $spec = new RuntimeServiceSpec(
            command: ['frankenphp', 'run'],
            envFiles: [],
        );
        $compose = new ComposeFile('vortos-app-blue', ActiveColor::Blue, self::digestPinnedImage(), $spec);

        $array = $compose->toArray();
        $this->assertArrayNotHasKey('env_file', $array['services']['app-blue']);
    }
}
