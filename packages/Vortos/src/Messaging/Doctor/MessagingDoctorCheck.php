<?php

declare(strict_types=1);

namespace Vortos\Messaging\Doctor;

use Vortos\Foundation\Doctor\Attribute\AsDoctor;
use Vortos\Foundation\Doctor\Contract\DoctorCheckInterface;
use Vortos\Foundation\Doctor\DoctorResult;

/**
 * Checks that VORTOS_REPLAY_SECRET is set and has sufficient length.
 *
 * A missing or weak secret disables HMAC verification on targeted replay headers,
 * meaning any producer can forge x-vortos-target-handler to bypass normal routing.
 */
#[AsDoctor]
final class MessagingDoctorCheck implements DoctorCheckInterface
{
    public function name(): string
    {
        return 'messaging.replay-secret';
    }

    public function run(): DoctorResult
    {
        $secret = getenv('VORTOS_REPLAY_SECRET');

        if ($secret === false || $secret === '') {
            return DoctorResult::warning(
                $this->name(),
                'VORTOS_REPLAY_SECRET is not set — targeted replay HMAC signing disabled',
                'Add VORTOS_REPLAY_SECRET=<value> to .env',
            );
        }

        if (strlen($secret) < 32) {
            return DoctorResult::warning(
                $this->name(),
                'VORTOS_REPLAY_SECRET is shorter than 32 characters — use a stronger value',
                'Replace VORTOS_REPLAY_SECRET with a value at least 32 characters long',
            );
        }

        return DoctorResult::ok($this->name(), 'VORTOS_REPLAY_SECRET is configured');
    }
}
