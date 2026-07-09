<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Driver\Caddy;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Cutover\Edge\EdgeBaseConfig;
use Vortos\Deploy\Cutover\Edge\EdgeConfigFormat;
use Vortos\Deploy\Driver\Caddy\CaddyfileAdapter;
use Vortos\Deploy\Execution\CommandResult;
use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Exception\EdgeBaseConfigException;

final class CaddyfileAdapterTest extends TestCase
{
    private function runner(int $exit, string $stdout, string $stderr = ''): CommandRunnerInterface
    {
        return new class($exit, $stdout, $stderr) implements CommandRunnerInterface {
            /** @var list<string> */
            public array $lastArgv = [];
            public ?string $lastStdin = null;

            public function __construct(
                private readonly int $exit,
                private readonly string $stdout,
                private readonly string $stderr,
            ) {}

            public function run(array $argv, ?string $stdin = null, ?float $timeout = null, array $redactTokens = []): CommandResult
            {
                $this->lastArgv = $argv;
                $this->lastStdin = $stdin;

                return new CommandResult($this->exit, $this->stdout, $this->stderr, 0.01);
            }
        };
    }

    public function testAdaptsCaddyfileViaContainer(): void
    {
        $json = json_encode(['apps' => ['http' => ['servers' => []]]], \JSON_THROW_ON_ERROR);
        $runner = $this->runner(0, $json);
        $adapter = new CaddyfileAdapter('caddy:2-alpine', null, $runner);

        $base = new EdgeBaseConfig('/x/Caddyfile', "example.com {\n}\n", EdgeConfigFormat::Caddyfile);
        $result = $adapter->adapt($base);

        self::assertSame(['apps' => ['http' => ['servers' => []]]], $result);
        self::assertContains('caddy', $runner->lastArgv);
        self::assertContains('adapt', $runner->lastArgv);
        self::assertContains('none', $runner->lastArgv, 'container must run with --network none');
        self::assertSame("example.com {\n}\n", $runner->lastStdin);

        // Regression: caddy adapt only reads the piped STDIN when told to via `--config -`. Without it
        // caddy fails "input file required", which broke the whole adapt path against a real binary.
        self::assertContains('--config', $runner->lastArgv, 'adapt must pass --config so caddy reads input');
        $i = array_search('--config', $runner->lastArgv, true);
        self::assertSame('-', $runner->lastArgv[$i + 1] ?? null, '`--config` must be followed by `-` (read STDIN)');
    }

    public function testJsonBaseSkipsAdapt(): void
    {
        // A fake runner that would fail if invoked — proves JSON base never shells out.
        $runner = $this->runner(1, '', 'should not run');
        $adapter = new CaddyfileAdapter('caddy:2-alpine', null, $runner);

        $base = new EdgeBaseConfig('/x/caddy.json', '{"apps":{"http":{"servers":{}}}}', EdgeConfigFormat::Json);
        $result = $adapter->adapt($base);

        self::assertSame(['apps' => ['http' => ['servers' => []]]], $result);
        self::assertSame([], $runner->lastArgv);
    }

    public function testAdaptFailureIsSecretFree(): void
    {
        // Caddy echoes source context (which can hold secrets) to stderr; the exception must not.
        $stderr = "Error: adapting config using caddyfile: /etc/caddy/Caddyfile:4: basicauth admin JDJhSUPERSECRET";
        $adapter = new CaddyfileAdapter('caddy:2-alpine', null, $this->runner(1, '', $stderr));

        $base = new EdgeBaseConfig('/x/Caddyfile', "example.com {\n}\n", EdgeConfigFormat::Caddyfile);

        try {
            $adapter->adapt($base);
            self::fail('expected EdgeBaseConfigException');
        } catch (EdgeBaseConfigException $e) {
            self::assertStringNotContainsString('SUPERSECRET', $e->getMessage());
            self::assertStringNotContainsString('basicauth', $e->getMessage());
            self::assertStringContainsString('line 4', $e->getMessage());
        }
    }

    public function testRejectsOversizeAdaptedOutput(): void
    {
        $huge = json_encode(['x' => str_repeat('a', 100)], \JSON_THROW_ON_ERROR);
        $adapter = new CaddyfileAdapter('caddy:2-alpine', null, $this->runner(0, $huge), maxOutputBytes: 8);

        $base = new EdgeBaseConfig('/x/Caddyfile', "example.com {\n}\n", EdgeConfigFormat::Caddyfile);

        $this->expectException(EdgeBaseConfigException::class);
        $adapter->adapt($base);
    }

    public function testRejectsInvalidJsonOutput(): void
    {
        $adapter = new CaddyfileAdapter('caddy:2-alpine', null, $this->runner(0, 'not json{'));
        $base = new EdgeBaseConfig('/x/Caddyfile', "example.com {\n}\n", EdgeConfigFormat::Caddyfile);

        $this->expectException(EdgeBaseConfigException::class);
        $adapter->adapt($base);
    }
}
