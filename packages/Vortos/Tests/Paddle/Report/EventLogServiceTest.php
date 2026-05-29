<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Report;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Report\EventLogPage;
use Vortos\Paddle\Report\EventLogService;
use Vortos\Paddle\Report\Operation\EventLogFilters;

final class EventLogServiceTest extends TestCase
{
    private function makeSdkEvent(string $eventId, string $eventType): \Paddle\SDK\Entities\Event
    {
        return \Paddle\SDK\Entities\Event::from([
            'event_id'        => $eventId,
            'event_type'      => $eventType,
            'occurred_at'     => '2024-01-01T00:00:00.000000Z',
            'notification_id' => null,
            'data'            => [
                'id'                  => 'sub_test',
                'transaction_id'      => null,
                'status'              => 'active',
                'customer_id'         => 'ctm_test',
                'address_id'          => 'add_test',
                'business_id'         => null,
                'currency_code'       => 'USD',
                'created_at'          => '2024-01-01T00:00:00.000000Z',
                'updated_at'          => '2024-01-02T00:00:00.000000Z',
                'started_at'          => null,
                'first_billed_at'     => null,
                'next_billed_at'      => null,
                'paused_at'           => null,
                'canceled_at'         => null,
                'discount'            => null,
                'collection_mode'     => 'automatic',
                'billing_details'     => null,
                'current_billing_period' => null,
                'billing_cycle'       => ['interval' => 'month', 'frequency' => 1],
                'scheduled_change'    => null,
                'items'               => [],
                'custom_data'         => null,
                'import_meta'         => null,
            ],
        ]);
    }

    public function test_list_events_returns_page(): void
    {
        $collection = new \ArrayIterator([
            $this->makeSdkEvent('evt_1', 'subscription.activated'),
            $this->makeSdkEvent('evt_2', 'subscription.canceled'),
        ]);

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($collection);

        $service = new EventLogService($client);
        $page    = $service->listEvents(new EventLogFilters());

        $this->assertInstanceOf(EventLogPage::class, $page);
        $this->assertCount(2, $page->entries);
        $this->assertSame('evt_1', $page->entries[0]->eventId);
        $this->assertSame('subscription.activated', $page->entries[0]->eventType);
    }

    public function test_list_events_preserves_cursor(): void
    {
        $collection = new \ArrayIterator([]);

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($collection);

        $service = new EventLogService($client);
        $page    = $service->listEvents(new EventLogFilters(after: 'cursor_xyz'));

        $this->assertSame('cursor_xyz', $page->nextCursor);
    }

    public function test_empty_event_log_page(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn(new \ArrayIterator([]));

        $service = new EventLogService($client);
        $page    = $service->listEvents(new EventLogFilters());

        $this->assertCount(0, $page->entries);
        $this->assertFalse($page->hasMore);
    }
}
