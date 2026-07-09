<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight\Check;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Cutover\Edge\AppProxyIdentifier;
use Vortos\Deploy\Cutover\Edge\ConfigInvariantValidator;
use Vortos\Deploy\Cutover\Edge\EdgeBaseConfigResolver;
use Vortos\Deploy\Cutover\Edge\EdgeConfigAssembler;
use Vortos\Deploy\Cutover\Edge\EdgeConfigMerger;
use Vortos\Deploy\Cutover\EdgeConfigGenerator;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Driver\Caddy\CaddyfileAdapter;
use Vortos\Deploy\Execution\CommandResult;
use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Preflight\Check\EdgeBaseConfigCheck;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

final class EdgeBaseConfigCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vortos-edge-pf-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/Caddyfile');
        @rmdir($this->dir);
    }

    private function runner(int $exit, string $stdout, string $stderr = ''): CommandRunnerInterface
    {
        return new class($exit, $stdout, $stderr) implements CommandRunnerInterface {
            public function __construct(
                private readonly int $exit,
                private readonly string $stdout,
                private readonly string $stderr,
            ) {}

            public function run(array $argv, ?string $stdin = null, ?float $timeout = null, array $redactTokens = []): CommandResult
            {
                return new CommandResult($this->exit, $this->stdout, $this->stderr, 0.01);
            }
        };
    }

    private function assembler(?string $basePath, CommandRunnerInterface $runner): EdgeConfigAssembler
    {
        return new EdgeConfigAssembler(
            new EdgeBaseConfigResolver($this->dir),
            new CaddyfileAdapter('caddy:2-alpine', null, $runner),
            new EdgeConfigMerger(new AppProxyIdentifier()),
            new ConfigInvariantValidator('localhost:2019'),
            new EdgeConfigGenerator(),
            'localhost:2019',
            $basePath,
        );
    }

    private function context(): PreflightContext
    {
        $manifest = new BuildManifest(
            buildId: 'build-1',
            gitSha: str_repeat('a', 40),
            imageRepository: 'ghcr.io/acme/app',
            imageDigest: 'sha256:' . str_repeat('ab', 32),
            targetArch: Arch::Arm64,
            environment: 'production',
            schemaFingerprint: SchemaFingerprint::empty(),
            createdAt: new \DateTimeImmutable(),
        );

        $state = new CurrentDeployState(
            activeColor: ActiveColor::Blue,
            currentDigest: 'sha256:' . str_repeat('ab', 32),
            appliedFingerprint: SchemaFingerprint::empty(),
        );

        return new PreflightContext(
            DeploymentDefinition::build(),
            $manifest,
            $state,
            new EnvironmentName('production'),
        );
    }

    /** @return array<string,mixed> */
    private function adaptedSingleApp(): array
    {
        return ['apps' => ['http' => ['servers' => ['srv0' => [
            'listen' => [':443'],
            'routes' => [[
                'match' => [['host' => ['example.com']]],
                'handle' => [['handler' => 'reverse_proxy', 'upstreams' => [['dial' => 'app-blue:8080']]]],
            ]],
        ]]]]];
    }

    public function testSkipsWhenNoBaseConfig(): void
    {
        $check = new EdgeBaseConfigCheck($this->assembler(null, $this->runner(0, '{}')), 'example.com');
        $finding = $check->check($this->context());

        self::assertSame(PreflightStatus::Skip, $finding->status);
    }

    public function testPassesOnCleanBaseConfig(): void
    {
        file_put_contents($this->dir . '/Caddyfile', "example.com {\n  reverse_proxy app-blue:8080\n}\n");
        $runner = $this->runner(0, json_encode($this->adaptedSingleApp(), \JSON_THROW_ON_ERROR));

        $check = new EdgeBaseConfigCheck($this->assembler($this->dir . '/Caddyfile', $runner), 'example.com');
        $finding = $check->check($this->context());

        self::assertSame(PreflightStatus::Pass, $finding->status, $finding->detail);
    }

    public function testFailsClosedOnBrokenCaddyfile(): void
    {
        file_put_contents($this->dir . '/Caddyfile', "example.com {\n  reverse_proxy\n");
        $runner = $this->runner(1, '', 'Error: /etc/caddy/Caddyfile:2: unexpected token');

        $check = new EdgeBaseConfigCheck($this->assembler($this->dir . '/Caddyfile', $runner), 'example.com');
        $finding = $check->check($this->context());

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringNotContainsString('unexpected token', $finding->detail . $finding->summary);
    }

    public function testFailsClosedOnAmbiguousAppProxies(): void
    {
        file_put_contents($this->dir . '/Caddyfile', "example.com {\n}\n");
        $adapted = $this->adaptedSingleApp();
        $adapted['apps']['http']['servers']['srv0']['routes'][0]['handle'][] =
            ['handler' => 'reverse_proxy', 'upstreams' => [['dial' => 'app-green:8080']]];
        $runner = $this->runner(0, json_encode($adapted, \JSON_THROW_ON_ERROR));

        $check = new EdgeBaseConfigCheck($this->assembler($this->dir . '/Caddyfile', $runner), 'example.com');
        $finding = $check->check($this->context());

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('exactly one', $finding->detail);
    }

    public function testFailsClosedOnAdminOverride(): void
    {
        file_put_contents($this->dir . '/Caddyfile', "example.com {\n}\n");
        $adapted = $this->adaptedSingleApp();
        $adapted['admin'] = ['listen' => '0.0.0.0:2019'];
        $runner = $this->runner(0, json_encode($adapted, \JSON_THROW_ON_ERROR));

        $check = new EdgeBaseConfigCheck($this->assembler($this->dir . '/Caddyfile', $runner), 'example.com');
        $finding = $check->check($this->context());

        self::assertSame(PreflightStatus::Fail, $finding->status);
    }
}
