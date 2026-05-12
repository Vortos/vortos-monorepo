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

    public function test_redacts_normalized_dashed_and_dotted_keys_with_exact_lookup(): void
    {
        $processor = new RedactionProcessor();
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            context: ['api-key' => 'abc', 'client.secret' => 'def', 'trace_id' => 'safe'],
        );

        $result = $processor($record);

        $this->assertSame('[REDACTED]', $result->context['api-key']);
        $this->assertSame('[REDACTED]', $result->context['client.secret']);
        $this->assertSame('safe', $result->context['trace_id']);
    }

    public function test_custom_wildcard_keys_use_fuzzy_fallback(): void
    {
        $processor = new RedactionProcessor(['*_credential']);
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            context: ['database_credential' => 'secret', 'database_name' => 'main'],
        );

        $result = $processor($record);

        $this->assertSame('[REDACTED]', $result->context['database_credential']);
        $this->assertSame('main', $result->context['database_name']);
    }

    public function test_message_crlf_is_escaped_without_regex_scanning_message_content(): void
    {
        $processor = new RedactionProcessor();
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: "User failed\n[INFO] forged",
        );

        $result = $processor($record);

        $this->assertSame('User failed\\n[INFO] forged', $result->message);
    }
}
