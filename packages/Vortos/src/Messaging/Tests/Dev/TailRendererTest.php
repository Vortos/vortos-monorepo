<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Dev;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Vortos\Messaging\Dev\TailRenderer;
use Vortos\Messaging\Hook\HandlerOutcome;

final class TailRendererTest extends TestCase
{
    private function renderer(): array
    {
        $output   = new BufferedOutput();
        $renderer = new TailRenderer($output);
        return [$output, $renderer];
    }

    public function test_flush_is_noop_when_no_lines_buffered(): void
    {
        [$output, $renderer] = $this->renderer();
        $renderer->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $renderer->flush();
        $this->assertSame('', $output->fetch());
    }

    public function test_flush_outputs_header_and_ok_line(): void
    {
        [$output, $renderer] = $this->renderer();
        $renderer->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $renderer->handlerResult('evt-1', 'MyHandler::handle', HandlerOutcome::Succeeded, 1, 14.2, null);
        $renderer->flush();

        $result = $output->fetch();
        $this->assertStringContainsString('UserRegistered', $result);
        $this->assertStringContainsString('OK', $result);
        $this->assertStringContainsString('14.2ms', $result);
    }

    public function test_single_handler_uses_leaf_char(): void
    {
        [$output, $renderer] = $this->renderer();
        $renderer->messageStart('evt-1', '10:00:00', 'OrderPlaced', 'agg-001...', 'corr-123');
        $renderer->handlerResult('evt-1', 'OrderHandler::handle', HandlerOutcome::Succeeded, 1, 5.0, null);
        $renderer->flush();

        $this->assertStringContainsString('└', $output->fetch());
    }

    public function test_multiple_handlers_use_branch_and_leaf_chars(): void
    {
        [$output, $renderer] = $this->renderer();
        $renderer->messageStart('evt-1', '10:00:00', 'OrderPlaced', 'agg-001...', 'corr-123');
        $renderer->handlerResult('evt-1', 'HandlerA::run', HandlerOutcome::Succeeded, 1, 5.0, null);
        $renderer->handlerResult('evt-1', 'HandlerB::run', HandlerOutcome::Succeeded, 1, 8.0, null);
        $renderer->flush();

        $result = $output->fetch();
        $this->assertStringContainsString('├', $result);
        $this->assertStringContainsString('└', $result);
    }

    public function test_skipped_idempotent_shows_skip_and_idempotent(): void
    {
        [$output, $renderer] = $this->renderer();
        $renderer->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $renderer->handlerResult('evt-1', 'MyHandler::handle', HandlerOutcome::SkippedIdempotent, 1, 0.0, null);
        $renderer->flush();

        $result = $output->fetch();
        $this->assertStringContainsString('SKIP', $result);
        $this->assertStringContainsString('idempotent', $result);
    }

    public function test_discarded_replay_limit_shows_skip_and_replay_limit(): void
    {
        [$output, $renderer] = $this->renderer();
        $renderer->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $renderer->handlerResult('evt-1', 'MyHandler::handle', HandlerOutcome::DiscardedReplayLimit, 1, 0.0, null);
        $renderer->flush();

        $result = $output->fetch();
        $this->assertStringContainsString('SKIP', $result);
        $this->assertStringContainsString('replay limit', $result);
    }

    public function test_dead_lettered_shows_dlq_and_error(): void
    {
        [$output, $renderer] = $this->renderer();
        $renderer->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $renderer->handlerResult('evt-1', 'MyHandler::handle', HandlerOutcome::DeadLettered, 3, 42.5, 'DB connection lost');
        $renderer->flush();

        $result = $output->fetch();
        $this->assertStringContainsString('DLQ', $result);
        $this->assertStringContainsString('DB connection lost', $result);
    }

    public function test_succeeded_after_retries_shows_ok_with_retry_count(): void
    {
        [$output, $renderer] = $this->renderer();
        $renderer->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $renderer->handlerResult('evt-1', 'MyHandler::handle', HandlerOutcome::SucceededAfterRetries, 3, 900.0, null);
        $renderer->flush();

        $result = $output->fetch();
        $this->assertStringContainsString('OK', $result);
        $this->assertStringContainsString('after 2 retries', $result);
    }

    public function test_succeeded_after_one_retry_uses_singular(): void
    {
        [$output, $renderer] = $this->renderer();
        $renderer->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $renderer->handlerResult('evt-1', 'MyHandler::handle', HandlerOutcome::SucceededAfterRetries, 2, 400.0, null);
        $renderer->flush();

        $result = $output->fetch();
        $this->assertStringContainsString('after 1 retry', $result);
    }

    public function test_handler_retry_prints_immediately_without_flush(): void
    {
        [$output, $renderer] = $this->renderer();
        $renderer->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $renderer->handlerRetry('evt-1', 'MyHandler::handle', 1, 150.0, 'SMTP timeout');

        // Nothing flushed — retry line should still appear immediately
        $result = $output->fetch();
        $this->assertStringContainsString('attempt', $result);
        $this->assertStringContainsString('SMTP timeout', $result);
    }

    public function test_handler_retry_prints_header_before_retry_line(): void
    {
        [$output, $renderer] = $this->renderer();
        $renderer->messageStart('evt-1', '10:00:00', 'OrderPlaced', 'agg-001...', 'corr-123');
        $renderer->handlerRetry('evt-1', 'OrderHandler::handle', 1, 100.0, 'timeout');

        $result = $output->fetch();
        $this->assertStringContainsString('OrderPlaced', $result);
        $headerPos = strpos($result, 'OrderPlaced');
        $retryPos  = strpos($result, 'attempt');
        $this->assertGreaterThan($headerPos, $retryPos, 'Header must appear before retry line');
    }

    public function test_handler_retry_shows_attempt_number(): void
    {
        [$output, $renderer] = $this->renderer();
        $renderer->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $renderer->handlerRetry('evt-1', 'MyHandler::handle', 2, 300.0, 'connection refused');

        $this->assertStringContainsString('2', $output->fetch());
    }

    public function test_message_start_flushes_previous_event_on_new_event_id(): void
    {
        [$output, $renderer] = $this->renderer();
        $renderer->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $renderer->handlerResult('evt-1', 'HandlerA::run', HandlerOutcome::Succeeded, 1, 5.0, null);
        $renderer->messageStart('evt-2', '10:00:01', 'OrderPlaced', 'agg-002...', 'corr-456');

        $result = $output->fetch();
        $this->assertStringContainsString('UserRegistered', $result);
        $this->assertStringContainsString('HandlerA', $result);
    }

    public function test_same_event_id_does_not_duplicate_header(): void
    {
        [$output, $renderer] = $this->renderer();
        $renderer->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $renderer->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $renderer->handlerResult('evt-1', 'HandlerA::run', HandlerOutcome::Succeeded, 1, 5.0, null);
        $renderer->flush();

        $result = $output->fetch();
        $this->assertSame(1, substr_count($result, 'UserRegistered'));
    }

    public function test_flush_clears_buffer_so_second_flush_is_noop(): void
    {
        [$output, $renderer] = $this->renderer();
        $renderer->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $renderer->handlerResult('evt-1', 'MyHandler::handle', HandlerOutcome::Succeeded, 1, 10.0, null);
        $renderer->flush();

        $output->fetch();

        $renderer->flush();
        $this->assertSame('', $output->fetch());
    }

    public function test_short_handler_id_strips_namespace(): void
    {
        [$output, $renderer] = $this->renderer();
        $renderer->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $renderer->handlerResult('evt-1', 'App\\Handler\\UserHandler::onUserRegistered', HandlerOutcome::Succeeded, 1, 5.0, null);
        $renderer->flush();

        $result = $output->fetch();
        $this->assertStringContainsString('UserHandler::onUserRegistered', $result);
        $this->assertStringNotContainsString('App\\Handler\\', $result);
    }

    public function test_error_message_is_truncated_at_80_chars(): void
    {
        [$output, $renderer] = $this->renderer();
        $longMessage = str_repeat('x', 100);
        $renderer->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $renderer->handlerResult('evt-1', 'MyHandler::handle', HandlerOutcome::DeadLettered, 3, 5.0, $longMessage);
        $renderer->flush();

        $result = $output->fetch();
        $this->assertStringContainsString(str_repeat('x', 80), $result);
        $this->assertStringNotContainsString(str_repeat('x', 81), $result);
    }
}
