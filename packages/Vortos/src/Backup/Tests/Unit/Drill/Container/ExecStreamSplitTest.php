<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Drill\Container;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Drill\Container\DockerEngineContainerRuntime;

/** An exec's output comes back multiplexed; `info --output=json` on stdout must never mix with stderr. */
final class ExecStreamSplitTest extends TestCase
{
    public function testSplitsStdoutFromStderr(): void
    {
        $body = self::frame(1, '[{"name":') . self::frame(2, "P00 WARN: x\n") . self::frame(1, '"sqoura"}]');

        self::assertSame(['[{"name":"sqoura"}]', "P00 WARN: x\n"], DockerEngineContainerRuntime::splitStreams($body));
        self::assertSame(['', ''], DockerEngineContainerRuntime::splitStreams(''));
    }

    public function testRefusesUnframedOrTruncatedOutput(): void
    {
        foreach (['plain text, no frames', substr(self::frame(1, 'abc'), 0, 6)] as $bad) {
            try {
                DockerEngineContainerRuntime::splitStreams($bad);
                self::fail('accepted ' . bin2hex($bad));
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private static function frame(int $stream, string $payload): string
    {
        return \chr($stream) . "\0\0\0" . pack('N', \strlen($payload)) . $payload;
    }
}
