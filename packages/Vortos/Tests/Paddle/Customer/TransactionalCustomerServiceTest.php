<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Customer;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Customer\Contract\ImmediateCustomerServiceInterface;
use Vortos\Paddle\Customer\Customer;
use Vortos\Paddle\Customer\Operation\CreateCustomerRequest;
use Vortos\Paddle\Customer\Operation\UpdateCustomerRequest;
use Vortos\Paddle\Customer\TransactionalCustomerService;
use Vortos\Paddle\Outbox\PaddleOutboxWriterInterface;
use Vortos\Paddle\ValueObject\CustomerStatus;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class TransactionalCustomerServiceTest extends TestCase
{
    public function test_create_queues_outbox_entry(): void
    {
        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $outbox->expects($this->once())
            ->method('queue')
            ->with('customer.create', $this->arrayHasKey('email'));

        $reader  = $this->createMock(ImmediateCustomerServiceInterface::class);
        $service = new TransactionalCustomerService($outbox, $reader);

        $id = $service->create(new CreateCustomerRequest('john@example.com'));

        $this->assertInstanceOf(PaddleCustomerId::class, $id);
    }

    public function test_update_queues_outbox_entry(): void
    {
        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $outbox->expects($this->once())
            ->method('queue')
            ->with('customer.update', $this->arrayHasKey('id'));

        $reader  = $this->createMock(ImmediateCustomerServiceInterface::class);
        $service = new TransactionalCustomerService($outbox, $reader);

        $service->update(PaddleCustomerId::of('ctm_123'), new UpdateCustomerRequest(name: 'Jane'));
    }

    public function test_archive_queues_outbox_entry(): void
    {
        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $outbox->expects($this->once())
            ->method('queue')
            ->with('customer.archive', $this->arrayHasKey('id'));

        $reader  = $this->createMock(ImmediateCustomerServiceInterface::class);
        $service = new TransactionalCustomerService($outbox, $reader);

        $service->archive(PaddleCustomerId::of('ctm_123'));
    }

    public function test_get_delegates_to_reader(): void
    {
        $fakeCustomer = new Customer(
            PaddleCustomerId::of('ctm_123'),
            'john@example.com',
            'John',
            CustomerStatus::Active,
            'en',
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-01-01'),
        );

        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $reader = $this->createMock(ImmediateCustomerServiceInterface::class);
        $reader->expects($this->once())->method('get')->willReturn($fakeCustomer);

        $service = new TransactionalCustomerService($outbox, $reader);
        $service->get(PaddleCustomerId::of('ctm_123'));
    }

    public function test_create_does_not_call_api(): void
    {
        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $outbox->method('queue');

        $reader = $this->createMock(ImmediateCustomerServiceInterface::class);
        $reader->expects($this->never())->method('create');

        $service = new TransactionalCustomerService($outbox, $reader);
        $service->create(new CreateCustomerRequest('test@example.com'));
    }
}
