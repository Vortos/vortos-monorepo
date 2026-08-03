<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Customer;

use Paddle\SDK\Exceptions\ApiError;
use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Api\PaddleSdkExceptionMapper;
use Vortos\Paddle\Customer\Customer;
use Vortos\Paddle\Customer\ImmediateCustomerService;
use Vortos\Paddle\Customer\Operation\CreateCustomerRequest;
use Vortos\Paddle\Customer\Operation\UpdateCustomerRequest;
use Vortos\Paddle\Exception\PaddleConflictException;
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

    public function test_find_or_create_returns_the_new_customer_when_none_exists(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkCustomer('ctm_new'));

        $service = new ImmediateCustomerService($client);
        $id      = $service->findOrCreate(new CreateCustomerRequest('john@example.com', 'John Doe'));

        $this->assertSame('ctm_new', $id->value);
    }

    public function test_find_or_create_returns_the_existing_customer_on_conflict(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willThrowException(new PaddleConflictException(
            'customer email conflicts with customer of id ctm_existing_9f',
            'customer_already_exists',
            'conflict',
            null,
            'ctm_existing_9f',
        ));

        $service = new ImmediateCustomerService($client);
        $id      = $service->findOrCreate(new CreateCustomerRequest('john@example.com', 'John Doe'));

        $this->assertSame('ctm_existing_9f', $id->value);
    }

    /**
     * The whole path, from the bytes Paddle returns to the id the caller bills.
     *
     * The other conflict tests hand this service an exception built by hand, which is
     * how a mapper that never produced one went unnoticed until a live restore-access
     * checkout 500ed. This one starts where reality does: the verbatim 409 body from
     * `POST /customers`, mapped by the real mapper.
     */
    public function test_find_or_create_recovers_the_customer_from_a_real_paddle_409(): void
    {
        $apiError = ApiError::fromErrorData([
            'type'              => 'request_error',
            'code'              => 'customer_already_exists',
            'detail'            => 'customer email conflicts with customer of id ctm_01jnrtysqtbd9a54f13518fap1',
            'documentation_url' => 'https://developer.paddle.com/v1/errors/customers/customer_already_exists',
        ], null);

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willThrowException((new PaddleSdkExceptionMapper())->map($apiError));

        $service = new ImmediateCustomerService($client);
        $id      = $service->findOrCreate(new CreateCustomerRequest('john@example.com', 'John Doe'));

        $this->assertSame('ctm_01jnrtysqtbd9a54f13518fap1', $id->value);
    }

    /**
     * The id is recovered from free text, and the caller bills whoever it names. A
     * conflict about some other entity is not "this customer already exists" and must
     * not be quietly turned into it.
     */
    public function test_find_or_create_rethrows_a_conflict_naming_a_non_customer_entity(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willThrowException(new PaddleConflictException(
            'price pri_01h8xce4x86p is archived',
            'conflict',
            'request_error',
            null,
            'pri_01h8xce4x86p',
        ));

        $service = new ImmediateCustomerService($client);

        $this->expectException(PaddleConflictException::class);
        $service->findOrCreate(new CreateCustomerRequest('john@example.com', 'John Doe'));
    }

    public function test_find_or_create_rethrows_a_conflict_that_names_no_entity(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willThrowException(new PaddleConflictException(
            'something else conflicted',
            'conflict',
            'conflict',
        ));

        $service = new ImmediateCustomerService($client);

        $this->expectException(PaddleConflictException::class);
        $service->findOrCreate(new CreateCustomerRequest('john@example.com', 'John Doe'));
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
