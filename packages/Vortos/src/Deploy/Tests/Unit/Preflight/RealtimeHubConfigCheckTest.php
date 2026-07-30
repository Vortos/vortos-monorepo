<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Preflight\Check\RealtimeHubConfigCheck;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\Strategy\DeployStrategy;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

/**
 * The hub's two halves ship in different artefacts — the Caddyfile is baked into the image, the secret
 * is delivered in an env file — and each way of disagreeing fails differently. Both directions must be
 * caught here, because only one of them is loud.
 *
 * @see RealtimeHubConfigCheck
 */
final class RealtimeHubConfigCheckTest extends TestCase
{
    private const CADDYFILE_PATH = '/etc/frankenphp/Caddyfile';
    private const SECRET = 'a-secret-of-at-least-thirty-two-characters';

    private const WITH_HUB = <<<'CADDY'
        :8080 {
            mercure {
                transport local
                publisher_jwt {$VORTOS_MERCURE_JWT_SECRET}
            }
            php_server
        }
        CADDY;

    private const WITHOUT_HUB = <<<'CADDY'
        :8080 {
            php_server
        }
        CADDY;

    /** @param array<string, string> $env */
    private static function check(?string $caddyfile, array $env): RealtimeHubConfigCheck
    {
        return new RealtimeHubConfigCheck(
            readFile: static fn (string $path): ?string => $path === self::CADDYFILE_PATH ? $caddyfile : null,
            env: static fn (string $name): string => $env[$name] ?? '',
        );
    }

    /** @param array<string, string> $env */
    private function finding(?string $caddyfile, array $env): \Vortos\Deploy\Preflight\PreflightFinding
    {
        return self::check($caddyfile, $env)->check($this->context());
    }

    /**
     * The loud direction: Caddy cannot parse a hub with no keys, so the color never boots. Better as a
     * failed preflight than as an aborted cutover with an operator reading Caddy logs.
     */
    public function test_hub_without_a_secret_fails(): void
    {
        $finding = $this->finding(self::WITH_HUB, []);

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('signing secret', $finding->summary);
    }

    /**
     * The silent direction, and the reason this check exists. vortos-sse picks the Mercure transport on
     * the strength of the secret alone, so the app hands browsers a subscription to a hub that isn't
     * there. Publishing is fail-safe and swallows the failure, the API keeps returning 200s, and live
     * updates just stop.
     */
    public function test_secret_without_a_hub_fails(): void
    {
        $finding = $this->finding(self::WITHOUT_HUB, [
            'VORTOS_MERCURE_JWT_SECRET' => self::SECRET,
        ]);

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('no hub', $finding->summary);
    }

    public function test_fully_configured_hub_passes(): void
    {
        $finding = $this->finding(self::WITH_HUB, [
            'VORTOS_MERCURE_JWT_SECRET' => self::SECRET,
            'VORTOS_MERCURE_CORS_ORIGINS' => 'https://app.example.com',
            'VORTOS_MERCURE_PUBLIC_URL' => 'https://api.example.com/.well-known/mercure',
        ]);

        self::assertSame(PreflightStatus::Pass, $finding->status);
    }

    /** Neither half configured is a legitimate deployment — the in-process fallback — not a failure. */
    public function test_no_hub_and_no_secret_passes_as_the_degraded_path(): void
    {
        $finding = $this->finding(self::WITHOUT_HUB, []);

        self::assertSame(PreflightStatus::Pass, $finding->status);
        self::assertStringContainsString('in-process', $finding->summary);
    }

    /**
     * A hub with a secret but no public URL mints subscriptions against an empty address — the same
     * silent death as a missing hub, so it must not be allowed to pass as "configured".
     */
    public function test_hub_missing_the_public_url_fails(): void
    {
        $finding = $this->finding(self::WITH_HUB, [
            'VORTOS_MERCURE_JWT_SECRET' => self::SECRET,
            'VORTOS_MERCURE_CORS_ORIGINS' => 'https://app.example.com',
        ]);

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('VORTOS_MERCURE_PUBLIC_URL', $finding->detail);
    }

    public function test_hub_missing_cors_origins_fails(): void
    {
        $finding = $this->finding(self::WITH_HUB, [
            'VORTOS_MERCURE_JWT_SECRET' => self::SECRET,
            'VORTOS_MERCURE_PUBLIC_URL' => 'https://api.example.com/.well-known/mercure',
        ]);

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('VORTOS_MERCURE_CORS_ORIGINS', $finding->detail);
    }

    /**
     * Run away from the target the baked config isn't there to read. Its presence is asserted by the
     * image build, so this must skip rather than false-fail a correct deployment.
     */
    public function test_unreadable_caddyfile_is_skipped(): void
    {
        $finding = $this->finding(null, ['VORTOS_MERCURE_JWT_SECRET' => self::SECRET]);

        self::assertSame(PreflightStatus::Skip, $finding->status);
    }

    /** A commented-out hub is not a hub. */
    public function test_commented_hub_directive_is_not_treated_as_a_hub(): void
    {
        $finding = $this->finding("    # mercure {\n    php_server\n", []);

        self::assertSame(PreflightStatus::Pass, $finding->status);
        self::assertStringContainsString('in-process', $finding->summary);
    }

    private function context(): PreflightContext
    {
        $definition = new DeploymentDefinition(
            host: 'ssh-compose',
            registry: 'dockerhub',
            ci: 'github',
            secrets: 'age',
            monitoring: 'grafana',
            notifiers: [],
            credential: 'ssh-key',
            strategy: DeployStrategy::BlueGreen,
            arch: Arch::Arm64,
            autoRollback: true,
            definitionHash: 'test-hash',
            runtimeService: new RuntimeServiceSpec(
                command: ['frankenphp', 'run', '--config', self::CADDYFILE_PATH, '--adapter', 'caddyfile'],
            ),
        );

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

        return new PreflightContext($definition, $manifest, $state, new EnvironmentName('production'));
    }
}
