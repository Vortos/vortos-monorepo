<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Deploy\Console\EdgeHydrateConfigCommand;
use Vortos\Deploy\Cutover\EdgeConfigGenerator;
use Vortos\Deploy\Cutover\State\EdgeState;
use Vortos\Deploy\Cutover\State\EdgeStateStoreInterface;
use Vortos\Deploy\Target\ActiveColor;

/**
 * GAP-D (D5): the edge boot init step renders the Caddy config from the durable store, falls back to
 * a bootstrap config on first boot, and fails closed when there is neither.
 */
final class EdgeHydrateConfigCommandTest extends TestCase
{
    private string $out;

    protected function setUp(): void
    {
        $this->out = sys_get_temp_dir() . '/vortos-edge-hydrate-' . bin2hex(random_bytes(6)) . '/caddy.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->out);
        @rmdir(\dirname($this->out));
    }

    public function test_renders_config_from_stored_state(): void
    {
        $state = new EdgeState('production', ActiveColor::Green, 'app-green', 8080, domain: 'api.example.com');
        $tester = $this->tester(new FakeStore($state));

        $exit = $tester->execute(['--env' => 'production', '--out' => $this->out, '--admin-listen' => 'edge:2019']);

        self::assertSame(0, $exit);
        $config = json_decode((string) file_get_contents($this->out), true);
        self::assertSame('app-green:8080', $config['apps']['http']['servers']['app']['routes'][0]['handle'][0]['upstreams'][0]['dial']);
        self::assertSame(['api.example.com'], $config['apps']['tls']['automation']['policies'][0]['subjects']);
    }

    public function test_falls_back_to_bootstrap_when_no_state(): void
    {
        $bootstrap = sys_get_temp_dir() . '/vortos-bootstrap-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($bootstrap, '{"bootstrap":true}');

        $tester = $this->tester(new FakeStore(null));
        $exit = $tester->execute([
            '--env' => 'production',
            '--out' => $this->out,
            '--fallback' => $bootstrap,
        ]);

        self::assertSame(0, $exit);
        self::assertSame('{"bootstrap":true}', file_get_contents($this->out));
        @unlink($bootstrap);
    }

    public function test_fails_closed_when_no_state_and_no_fallback(): void
    {
        $tester = $this->tester(new FakeStore(null));
        $exit = $tester->execute(['--env' => 'production', '--out' => $this->out]);

        self::assertSame(1, $exit);
        self::assertFileDoesNotExist($this->out);
    }

    private function tester(EdgeStateStoreInterface $store): CommandTester
    {
        return new CommandTester(new EdgeHydrateConfigCommand($store, new EdgeConfigGenerator()));
    }
}

final class FakeStore implements EdgeStateStoreInterface
{
    public function __construct(private readonly ?EdgeState $state) {}

    public function load(string $env): ?EdgeState
    {
        return $this->state;
    }

    public function save(EdgeState $state): EdgeState
    {
        return $state;
    }
}
