<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Checkout;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Checkout\PortalSession;
use Vortos\Paddle\Checkout\PortalSessionRequest;
use Vortos\Paddle\Checkout\PortalSessionService;
use Vortos\Paddle\ValueObject\PaddleCustomerId;
use Vortos\Paddle\ValueObject\PaddleSubscriptionId;

final class PortalSessionServiceTest extends TestCase
{
    private function makeSdkPortalSession(
        string $id = 'cpls_test',
        string $overviewUrl = 'https://customer.paddle.com/portal/overview',
        array $subscriptions = [],
    ): \Paddle\SDK\Entities\CustomerPortalSession {
        return \Paddle\SDK\Entities\CustomerPortalSession::from([
            'id'          => $id,
            'customer_id' => 'ctm_test',
            'urls'        => [
                'general'       => ['overview' => $overviewUrl],
                'subscriptions' => $subscriptions,
            ],
            'created_at'  => '2024-01-01T00:00:00.000000Z',
        ]);
    }

    public function test_create_portal_session_returns_session(): void
    {
        $sdkSession = $this->makeSdkPortalSession(
            id:          'cpls_abc',
            overviewUrl: 'https://customer.paddle.com/portal/overview',
        );

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($sdkSession);

        $service = new PortalSessionService($client);
        $session = $service->createPortalSession(new PortalSessionRequest(
            customerId: PaddleCustomerId::of('ctm_test'),
            returnUrl:  'https://myapp.com/account',
        ));

        $this->assertInstanceOf(PortalSession::class, $session);
        $this->assertSame('https://customer.paddle.com/portal/overview', $session->url);
        $this->assertSame('ctm_test', $session->customerId->value);
    }

    public function test_portal_session_for_specific_subscription(): void
    {
        $sdkSession = $this->makeSdkPortalSession(
            id:          'cpls_sub',
            overviewUrl: 'https://customer.paddle.com/portal/overview',
            subscriptions: [
                [
                    'id'                                => 'sub_abc',
                    'cancel_subscription'               => 'https://customer.paddle.com/cancel/sub_abc',
                    'update_subscription_payment_method' => 'https://customer.paddle.com/payment/sub_abc',
                ],
            ],
        );

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($sdkSession);

        $service = new PortalSessionService($client);
        $session = $service->createPortalSession(new PortalSessionRequest(
            customerId:     PaddleCustomerId::of('ctm_test'),
            returnUrl:      'https://myapp.com/account',
            subscriptionId: PaddleSubscriptionId::of('sub_abc'),
        ));

        $this->assertInstanceOf(PortalSession::class, $session);
        $this->assertNotEmpty($session->url);
    }

    public function test_portal_session_not_created_twice_for_same_request(): void
    {
        $sdkSession = $this->makeSdkPortalSession();

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->expects($this->exactly(2))->method('call')->willReturn($sdkSession);

        $service  = new PortalSessionService($client);
        $request  = new PortalSessionRequest(
            customerId: PaddleCustomerId::of('ctm_test'),
            returnUrl:  'https://myapp.com/account',
        );

        $session1 = $service->createPortalSession($request);
        $session2 = $service->createPortalSession($request);

        $this->assertSame($session1->url, $session2->url);
    }
}
