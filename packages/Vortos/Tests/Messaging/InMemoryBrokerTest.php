<?php
declare(strict_types=1);

namespace Vortos\Tests\Messaging;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Messaging\Driver\InMemory\Runtime\InMemoryBroker;
use Vortos\Messaging\ValueObject\ReceivedMessage;

final class InMemoryBrokerTest extends TestCase
{
    private InMemoryBroker $broker;

    protected function setUp(): void
    {
        $this->broker = new InMemoryBroker();
    }

    protected function tearDown(): void
    {
        $this->broker->reset();
    }

    private function makeMessage(string $transport, string $payload = 'payload'): ReceivedMessage
    {
        return new ReceivedMessage(
            id: 'msg-' . uniqid(),
            payload: $payload,
            headers: [],
            transportName: $transport,
            receivedAt: new DateTimeImmutable()
        );
    }

    public function test_enqueue_and_dequeue(): void
    {
        $msg = $this->makeMessage('test-transport', 'hello');
        $this->broker->enqueue('test-transport', $msg);
        $dequeued = $this->broker->dequeue('test-transport');
        $this->assertSame($msg, $dequeued);
    }

    public function test_dequeue_returns_null_when_empty(): void
    {
        $this->assertNull($this->broker->dequeue('empty-transport'));
    }

    public function test_dequeue_removes_message(): void
    {
        $msg = $this->makeMessage('transport');
        $this->broker->enqueue('transport', $msg);
        $this->broker->dequeue('transport');
        $this->assertNull($this->broker->dequeue('transport'));
    }

    public function test_count(): void
    {
        $this->broker->enqueue('transport', $this->makeMessage('transport'));
        $this->broker->enqueue('transport', $this->makeMessage('transport'));
        $this->assertSame(2, $this->broker->count('transport'));
    }

    public function test_different_transports_are_isolated(): void
    {
        $this->broker->enqueue('transport-a', $this->makeMessage('transport-a', 'a'));
        $this->broker->enqueue('transport-b', $this->makeMessage('transport-b', 'b'));

        $this->assertSame(1, $this->broker->count('transport-a'));
        $this->assertSame(1, $this->broker->count('transport-b'));
    }

    public function test_all_returns_all_messages(): void
    {
        $msg1 = $this->makeMessage('transport');
        $msg2 = $this->makeMessage('transport');
        $this->broker->enqueue('transport', $msg1);
        $this->broker->enqueue('transport', $msg2);
        $this->assertCount(2, $this->broker->all('transport'));
    }

    public function test_has_message(): void
    {
        $this->assertFalse($this->broker->hasMessage('transport'));
        $this->broker->enqueue('transport', $this->makeMessage('transport'));
        $this->assertTrue($this->broker->hasMessage('transport'));
    }

    public function test_reset_clears_all(): void
    {
        $this->broker->enqueue('transport', $this->makeMessage('transport'));
        $this->broker->reset();
        $this->assertFalse($this->broker->hasMessage('transport'));
    }
}
