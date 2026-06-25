<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class AdminApiNotPublicTest extends TestCase
{
    public function test_edge_compose_stub_does_not_publish_admin_port(): void
    {
        $generatorFile = dirname(__DIR__, 2) . '/Cutover/EdgeConfigGenerator.php';
        if (!file_exists($generatorFile)) {
            $this->markTestSkipped('EdgeConfigGenerator not yet created.');
        }

        $code = (string) file_get_contents($generatorFile);

        $this->assertStringNotContainsString(
            '"2019:2019"',
            $code,
            'Edge compose must not publish :2019 to the host — admin API is internal only.',
        );

        $this->assertStringNotContainsString(
            '0.0.0.0:2019',
            $code,
            'Edge compose must not bind admin API to 0.0.0.0.',
        );
    }

    public function test_caddy_admin_client_does_not_leak_request_bodies(): void
    {
        $clientFile = dirname(__DIR__, 2) . '/Driver/Caddy/CaddyAdminClient.php';
        if (!file_exists($clientFile)) {
            $this->markTestSkipped('CaddyAdminClient not yet created.');
        }

        $code = (string) file_get_contents($clientFile);

        $this->assertStringNotContainsString(
            'var_dump',
            $code,
            'CaddyAdminClient must not dump request/response data.',
        );

        $this->assertStringNotContainsString(
            'error_log',
            $code,
            'CaddyAdminClient must not log to error_log.',
        );
    }
}
