<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover\Edge;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Cutover\Edge\AppProxyIdentifier;
use Vortos\Deploy\Cutover\Edge\ConfigInvariantValidator;
use Vortos\Deploy\Cutover\Edge\EdgeConfigMerger;
use Vortos\Deploy\Cutover\Edge\MergeOutcome;
use Vortos\Deploy\Exception\EdgeBaseConfigException;
use Vortos\Deploy\Target\ActiveColor;

final class ConfigInvariantValidatorTest extends TestCase
{
    private EdgeConfigMerger $merger;
    private ConfigInvariantValidator $validator;

    protected function setUp(): void
    {
        $this->merger = new EdgeConfigMerger(new AppProxyIdentifier());
        $this->validator = new ConfigInvariantValidator('localhost:2019');
    }

    private function route(): DesiredRoute
    {
        return new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Green,
            upstream: new ColorEndpoint('app-green', 8080),
            domain: 'example.com',
        );
    }

    /** @return array<string,mixed> */
    private function base(array $extra = []): array
    {
        $base = [
            'apps' => ['http' => ['servers' => ['srv0' => [
                'listen' => [':443'],
                'routes' => [[
                    'match' => [['host' => ['example.com']]],
                    'handle' => [['handler' => 'reverse_proxy', 'upstreams' => [['dial' => 'app-blue:8080']]]],
                ]],
            ]]]],
        ];

        return array_replace_recursive($base, $extra);
    }

    public function testForcePinsAdminBlock(): void
    {
        $merged = $this->merger->merge($this->base(), $this->route());
        $final = $this->validator->validate($this->base(), $merged, 'example.com');

        self::assertSame(['listen' => 'localhost:2019', 'enforce_origin' => false], $final['admin']);
    }

    public function testFailsClosedOnOperatorAdminOverride(): void
    {
        // Operator tried to expose the admin API to the world — must fail closed.
        $base = $this->base(['admin' => ['listen' => '0.0.0.0:2019']]);
        $merged = $this->merger->merge($base, $this->route());

        $this->expectException(EdgeBaseConfigException::class);
        $this->expectExceptionMessageMatches('/admin/');
        $this->validator->validate($base, $merged, 'example.com');
    }

    public function testFailsClosedOnPrivilegedListener(): void
    {
        $base = $this->base();
        $base['apps']['http']['servers']['srv0']['listen'] = [':443', ':22'];
        $merged = $this->merger->merge($base, $this->route());

        $this->expectException(EdgeBaseConfigException::class);
        $this->expectExceptionMessageMatches('/port 22/');
        $this->validator->validate($base, $merged, 'example.com');
    }

    public function testAllowsNonPrivilegedExtraListener(): void
    {
        $base = $this->base();
        $base['apps']['http']['servers']['srv0']['listen'] = [':443', ':8080'];
        $merged = $this->merger->merge($base, $this->route());

        $final = $this->validator->validate($base, $merged, 'example.com');
        self::assertSame([':443', ':8080'], $final['apps']['http']['servers']['srv0']['listen']);
    }

    public function testFailsClosedWhenTlsDropped(): void
    {
        // A merger that produced no TLS coverage and automatic HTTPS disabled must be rejected. We
        // hand-craft a MergeOutcome that drops TLS to exercise the firewall independently of the merger.
        $config = $this->base();
        $config['apps']['http']['servers']['srv0']['automatic_https'] = ['disable' => true];
        $config['apps']['http']['servers']['srv0']['routes'][0]['handle'][0]['upstreams'] = [['dial' => 'app-green:8080']];
        // Strip the host matcher so the domain is not even a host matcher → truly no TLS path.
        $config['apps']['http']['servers']['srv0']['routes'][0]['match'] = [];

        $location = (new AppProxyIdentifier())->identify($config, '')->location;
        $merged = new MergeOutcome($config, \Vortos\Deploy\Cutover\Edge\MergeAction::Patched, $location);

        $this->expectException(EdgeBaseConfigException::class);
        $this->expectExceptionMessageMatches('/tls\.automation/');
        $this->validator->validate($config, $merged, 'example.com');
    }

    public function testAcceptsAutomaticHttpsWithHostMatcher(): void
    {
        // No explicit tls policy, but automatic HTTPS on + domain is a host matcher → Caddy manages cert.
        $base = $this->base();
        $merged = $this->merger->merge($base, $this->route());
        // Remove the policy the merger added to simulate reliance on automatic HTTPS.
        $strippedConfig = $merged->config;
        unset($strippedConfig['apps']['tls']);
        $stripped = new MergeOutcome($strippedConfig, $merged->action, $merged->location);

        $final = $this->validator->validate($base, $stripped, 'example.com');
        self::assertArrayHasKey('admin', $final);
    }

    public function testFailsClosedOnUnexpectedMutation(): void
    {
        // A MergeOutcome that also flipped an unrelated operator field must be caught.
        $base = $this->base();
        $tampered = $base;
        $tampered['apps']['http']['servers']['srv0']['routes'][0]['handle'][0]['upstreams'] = [['dial' => 'app-green:8080']];
        $tampered['apps']['http']['servers']['srv0']['routes'][0]['handle'][] = ['handler' => 'static_response', 'body' => 'pwned'];

        $location = (new AppProxyIdentifier())->identify($base, 'example.com')->location;
        $merged = new MergeOutcome($tampered, \Vortos\Deploy\Cutover\Edge\MergeAction::Patched, $location);

        $this->expectException(EdgeBaseConfigException::class);
        $this->expectExceptionMessageMatches('/changed more than the app upstream/');
        $this->validator->validate($base, $merged, 'example.com');
    }

    public function testHappyPathReturnsPinnedConfig(): void
    {
        $base = $this->base();
        $merged = $this->merger->merge($base, $this->route());
        $final = $this->validator->validate($base, $merged, 'example.com');

        self::assertSame([['dial' => 'app-green:8080']], $final['apps']['http']['servers']['srv0']['routes'][0]['handle'][0]['upstreams']);
        self::assertSame('localhost:2019', $final['admin']['listen']);
    }
}
