<?php

declare(strict_types=1);

namespace Vortos\Tests\Logger;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Vortos\Logger\Processor\RedactionProcessor;

final class RedactionProcessorTest extends TestCase
{
    public function test_redacts_sensitive_context_keys_recursively(): void
    {
        $processor = new RedactionProcessor();
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            context: ['user' => ['email' => 'a@example.com'], 'password' => 'secret', 'safe' => 'ok'],
        );

        $result = $processor($record);

        $this->assertSame('[REDACTED]', $result->context['user']['email']);
        $this->assertSame('[REDACTED]', $result->context['password']);
        $this->assertSame('ok', $result->context['safe']);
    }
}
