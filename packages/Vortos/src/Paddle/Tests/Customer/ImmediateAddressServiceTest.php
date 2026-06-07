<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Customer;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Customer\Address;
use Vortos\Paddle\Customer\ImmediateAddressService;
use Vortos\Paddle\Customer\Operation\CreateAddressRequest;
use Vortos\Paddle\ValueObject\PaddleAddressId;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class ImmediateAddressServiceTest extends TestCase
{
    private function makeSdkAddress(string $id = 'add_test_123'): \Paddle\SDK\Entities\Address
    {
        return \Paddle\SDK\Entities\Address::from([
            'id'          => $id,
            'customer_id' => 'ctm_test',
            'description' => 'Home',
            'first_line'  => '123 Main St',
            'second_line' => null,
            'city'        => 'Portland',
            'postal_code' => '97201',
            'region'      => 'OR',
            'country_code' => 'US',
            'custom_data' => null,
            'status'      => 'active',
            'created_at'  => '2024-01-01T00:00:00.000000Z',
            'updated_at'  => '2024-01-02T00:00:00.000000Z',
            'import_meta' => null,
        ]);
    }

    public function test_create_returns_address_id(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkAddress('add_abc'));

        $service = new ImmediateAddressService($client);
        $id      = $service->create(new CreateAddressRequest(
            customerId:  PaddleCustomerId::of('ctm_123'),
            countryCode: 'US',
            firstLine:   '123 Main St',
        ));

        $this->assertInstanceOf(PaddleAddressId::class, $id);
        $this->assertSame('add_abc', $id->value);
    }

    public function test_get_returns_mapped_address(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkAddress('add_xyz'));

        $service = new ImmediateAddressService($client);
        $address = $service->get(
            PaddleCustomerId::of('ctm_test'),
            PaddleAddressId::of('add_xyz')
        );

        $this->assertInstanceOf(Address::class, $address);
        $this->assertSame('add_xyz', $address->id->value);
        $this->assertSame('US', $address->countryCode);
    }

    public function test_archive_delegates_to_sdk(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->expects($this->once())->method('call');

        $service = new ImmediateAddressService($client);
        $service->archive(PaddleCustomerId::of('ctm_123'), PaddleAddressId::of('add_123'));
    }

    public function test_list_returns_array_of_addresses(): void
    {
        $collection = new \ArrayIterator([
            $this->makeSdkAddress('add_1'),
            $this->makeSdkAddress('add_2'),
        ]);

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($collection);

        $service   = new ImmediateAddressService($client);
        $addresses = $service->list(PaddleCustomerId::of('ctm_123'));

        $this->assertCount(2, $addresses);
        $this->assertContainsOnlyInstancesOf(Address::class, $addresses);
    }
}
