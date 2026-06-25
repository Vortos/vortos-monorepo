<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

use InvalidArgumentException;

final readonly class Responder
{
    public function __construct(
        public string $id,
        public string $name,
        public string $channelKey,
    ) {
        if ($id === '' || $name === '' || $channelKey === '') {
            throw new InvalidArgumentException('Responder id/name/channelKey must not be empty.');
        }
    }
}
