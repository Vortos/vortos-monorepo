<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

use DateTimeImmutable;

final class QuietHoursPolicy
{
    /** @var array<string, list<QuietHours>> */
    private array $byResponder = [];

    /** @param list<QuietHours> $windows */
    public function __construct(array $windows = [])
    {
        foreach ($windows as $window) {
            $this->byResponder[$window->responderId][] = $window;
        }
    }

    public function isQuietFor(Responder $responder, DateTimeImmutable $now): bool
    {
        foreach ($this->byResponder[$responder->id] ?? [] as $window) {
            if ($window->isQuietAt($now)) {
                return true;
            }
        }

        return false;
    }
}
