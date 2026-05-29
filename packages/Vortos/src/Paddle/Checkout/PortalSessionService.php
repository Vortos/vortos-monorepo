<?php

declare(strict_types=1);

namespace Vortos\Paddle\Checkout;

use Paddle\SDK\Resources\CustomerPortalSessions\Operations\CreateCustomerPortalSession;
use Vortos\Paddle\Api\PaddleApiClientInterface;

final class PortalSessionService
{
    public function __construct(private readonly PaddleApiClientInterface $client) {}

    public function createPortalSession(PortalSessionRequest $request): PortalSession
    {
        $undef = new \Paddle\SDK\Undefined();

        $sdkSession = $this->client->call(
            fn() => $this->client->sdk()->customerPortalSessions->create(
                $request->customerId->value,
                new CreateCustomerPortalSession(
                    subscriptionIds: $request->subscriptionId !== null
                        ? [$request->subscriptionId->value]
                        : $undef,
                )
            )
        );

        return PortalSession::fromSdk($sdkSession);
    }
}
