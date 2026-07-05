<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover\State;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Cutover\State\EdgeState;
use Vortos\Deploy\Cutover\State\FileEdgeStateStore;
use Vortos\Deploy\Target\ActiveColor;

/**
 * GAP-D (D4): the file edge-state store persists routing intent atomically to a one-shot+edge shared
 * path, with a monotonic per-env version and no secrets.
 */
final class FileEdgeStateStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vortos-edge-state-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function test_save_then_load_round_trips(): void
    {
        $store = new FileEdgeStateStore($this->dir);
        $saved = $store->save($this->state('production', ActiveColor::Blue, 'api.example.com'));

        self::assertSame(1, $saved->version);
        self::assertNotNull($saved->updatedAt);

        $loaded = $store->load('production');
        self::assertNotNull($loaded);
        self::assertSame(ActiveColor::Blue, $loaded->activeColor);
        self::assertSame('api.example.com', $loaded->domain);
        self::assertSame('app-blue', $loaded->upstreamHost);
        self::assertSame(8080, $loaded->upstreamPort);
    }

    public function test_version_is_monotonic_per_env(): void
    {
        $store = new FileEdgeStateStore($this->dir);

        self::assertSame(1, $store->save($this->state('production', ActiveColor::Blue))->version);
        self::assertSame(2, $store->save($this->state('production', ActiveColor::Green))->version);
        self::assertSame(3, $store->save($this->state('production', ActiveColor::Blue))->version);

        self::assertSame(ActiveColor::Blue, $store->load('production')?->activeColor);
    }

    public function test_environments_are_isolated(): void
    {
        $store = new FileEdgeStateStore($this->dir);
        $store->save($this->state('production', ActiveColor::Blue));
        $store->save($this->state('staging', ActiveColor::Green));

        self::assertSame(ActiveColor::Blue, $store->load('production')?->activeColor);
        self::assertSame(ActiveColor::Green, $store->load('staging')?->activeColor);
        self::assertSame(1, $store->load('staging')?->version);
    }

    public function test_missing_env_loads_null(): void
    {
        self::assertNull((new FileEdgeStateStore($this->dir))->load('never-saved'));
    }

    public function test_persisted_json_carries_no_secret_fields(): void
    {
        $store = new FileEdgeStateStore($this->dir);
        $store->save($this->state('production', ActiveColor::Blue, 'api.example.com'));

        $raw = (string) file_get_contents($this->dir . '/state-production.json');
        $decoded = json_decode($raw, true);

        self::assertIsArray($decoded);
        self::assertSame(
            ['env', 'active_color', 'upstream_host', 'upstream_port', 'weight', 'domain', 'version', 'updated_at'],
            array_keys($decoded),
            'only routing metadata is persisted — no secret material',
        );
    }

    public function test_env_name_cannot_escape_the_base_dir(): void
    {
        $store = new FileEdgeStateStore($this->dir);
        $store->save($this->state('../../etc/evil', ActiveColor::Blue));

        // The traversal is sanitized into a flat filename inside the base dir.
        self::assertFalse(is_file('/etc/state-evil.json'));
        $files = glob($this->dir . '/state-*.json') ?: [];
        self::assertCount(1, $files);
    }

    private function state(string $env, ActiveColor $color, ?string $domain = null): EdgeState
    {
        return new EdgeState(
            env: $env,
            activeColor: $color,
            upstreamHost: 'app-' . $color->value,
            upstreamPort: 8080,
            domain: $domain,
        );
    }
}
