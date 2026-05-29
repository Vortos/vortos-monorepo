<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Customer;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Customer\Customer;
use Vortos\Paddle\Customer\ImmediateCustomerService;
use Vortos\Paddle\Customer\Operation\CreateCustomerRequest;
use Vortos\Paddle\Customer\Operation\UpdateCustomerRequest;
use Vortos\Paddle\ValueObject\CustomerStatus;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class ImmediateCustomerServiceTest extends TestCase
{
    private function makeSdkCustomer(string $id = 'ctm_test_123', string $status = 'active'): \Paddle\SDK\Entities\Customer
    {
        return \Paddle\SDK\Entities\Customer::from([
            'id'                => $id,
            'name'              => 'John Doe',
            'email'             => 'john@example.com',
            'marketing_consent' => false,
            'status'            => $status,
            'custom_data'       => null,
            'locale'            => 'en',
            'created_at'        => '2024-01-01T00:00:00.000000Z',
            'updated_at'        => '2024-01-02T00:00:00.000000Z',
            'import_meta'       => null,
        ]);
    }

    public function test_create_returns_customer_id(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkCustomer('ctm_abc'));

        $service = new ImmediateCustomerService($client);
        $id      = $service->create(new CreateCustomerRequest('john@example.com', 'John Doe'));

        $this->assertInstanceOf(PaddleCustomerId::class, $id);
        $this->assertSame('ctm_abc', $id->value);
    }

    public function test_get_returns_mapped_customer(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkCustomer('ctm_xyz'));

        $service  = new ImmediateCustomerService($client);
        $customer = $service->get(PaddleCustomerId::of('ctm_xyz'));

        $this->assertInstanceOf(Customer::class, $customer);
        $this->assertSame('ctm_xyz', $customer->id->value);
        $this->assertSame('john@example.com', $customer->email);
        $this->assertSame(CustomerStatus::Active, $customer->status);
    }

    public function test_update_delegates_to_sdk(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->expects($this->once())->method('call');

        $service = new ImmediateCustomerService($client);
        $service->update(PaddleCustomerId::of('ctm_123'), new UpdateCustomerRequest(name: 'Jane'));
    }

    public function test_archive_delegates_to_sdk(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->expects($this->once())->method('call');

        $service = new ImmediateCustomerService($client);
        $service->archive(PaddleCustomerId::of('ctm_123'));
    }

    public function test_list_returns_array_of_customers(): void
    {
        $collection = new \ArrayIterator([
            $this->makeSdkCustomer('ctm_1'),
            $this->makeSdkCustomer('ctm_2'),
        ]);

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($collection);

        $service   = new ImmediateCustomerService($client);
        $customers = $service->list();

        $this->assertCount(2, $customers);
        $this->assertContainsOnlyInstancesOf(Customer::class, $customers);
    }

    public function test_archived_customer_has_archived_status(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkCustomer('ctm_arch', 'archived'));

        $service  = new ImmediateCustomerService($client);
        $customer = $service->get(PaddleCustomerId::of('ctm_arch'));

        $this->assertSame(CustomerStatus::Archived, $customer->status);
    }
}
