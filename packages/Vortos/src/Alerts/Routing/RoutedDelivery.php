<?php

declare(strict_types=1);

namespace Vortos\Alerts\Routing;

final readonly class RoutedDelivery
{
    public function __construct(
        public string $channelKey,
        public string $notifierKey,
    ) {}
}
