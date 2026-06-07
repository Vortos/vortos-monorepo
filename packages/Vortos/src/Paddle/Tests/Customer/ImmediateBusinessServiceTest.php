<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Customer;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Customer\Business;
use Vortos\Paddle\Customer\ImmediateBusinessService;
use Vortos\Paddle\Customer\Operation\CreateBusinessRequest;
use Vortos\Paddle\ValueObject\PaddleBusinessId;
use Vortos\Paddle\ValueObject\PaddleCustomerId;

final class ImmediateBusinessServiceTest extends TestCase
{
    private function makeSdkBusiness(string $id = 'biz_test_123'): \Paddle\SDK\Entities\Business
    {
        return \Paddle\SDK\Entities\Business::from([
            'id'             => $id,
            'name'           => 'Acme Corp',
            'customer_id'    => 'ctm_test',
            'company_number' => null,
            'tax_identifier' => 'GB123456789',
            'status'         => 'active',
            'contacts'       => [],
            'created_at'     => '2024-01-01T00:00:00.000000Z',
            'updated_at'     => '2024-01-02T00:00:00.000000Z',
            'custom_data'    => null,
            'import_meta'    => null,
        ]);
    }

    public function test_create_returns_business_id(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkBusiness('biz_abc'));

        $service = new ImmediateBusinessService($client);
        $id      = $service->create(new CreateBusinessRequest(
            customerId:    PaddleCustomerId::of('ctm_123'),
            name:          'Acme Corp',
            taxIdentifier: 'GB123456789',
        ));

        $this->assertInstanceOf(PaddleBusinessId::class, $id);
        $this->assertSame('biz_abc', $id->value);
    }

    public function test_get_returns_mapped_business(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkBusiness('biz_xyz'));

        $service  = new ImmediateBusinessService($client);
        $business = $service->get(
            PaddleCustomerId::of('ctm_test'),
            PaddleBusinessId::of('biz_xyz')
        );

        $this->assertInstanceOf(Business::class, $business);
        $this->assertSame('biz_xyz', $business->id->value);
        $this->assertSame('Acme Corp', $business->name);
        $this->assertSame('GB123456789', $business->taxIdentifier);
    }

    public function test_archive_delegates_to_sdk(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->expects($this->once())->method('call');

        $service = new ImmediateBusinessService($client);
        $service->archive(PaddleCustomerId::of('ctm_123'), PaddleBusinessId::of('biz_123'));
    }

    public function test_list_returns_array_of_businesses(): void
    {
        $collection = new \ArrayIterator([
            $this->makeSdkBusiness('biz_1'),
            $this->makeSdkBusiness('biz_2'),
        ]);

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($collection);

        $service    = new ImmediateBusinessService($client);
        $businesses = $service->list(PaddleCustomerId::of('ctm_123'));

        $this->assertCount(2, $businesses);
        $this->assertContainsOnlyInstancesOf(Business::class, $businesses);
    }
}
