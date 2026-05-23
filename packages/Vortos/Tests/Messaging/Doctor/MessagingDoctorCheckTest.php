<?php

declare(strict_types=1);

namespace Vortos\Tests\Messaging\Doctor;

use PHPUnit\Framework\TestCase;
use Vortos\Foundation\Doctor\DoctorStatus;
use Vortos\Messaging\Doctor\MessagingDoctorCheck;

final class MessagingDoctorCheckTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('VORTOS_REPLAY_SECRET');
    }

    protected function tearDown(): void
    {
        putenv('VORTOS_REPLAY_SECRET');
    }

    public function test_name(): void
    {
        $this->assertSame('messaging.replay-secret', (new MessagingDoctorCheck())->name());
    }

    public function test_warning_when_secret_not_set(): void
    {
        putenv('VORTOS_REPLAY_SECRET=');

        $result = (new MessagingDoctorCheck())->run();

        $this->assertSame(DoctorStatus::Warning, $result->status);
        $this->assertStringContainsString('VORTOS_REPLAY_SECRET', $result->summary);
    }

    public function test_warning_when_secret_too_short(): void
    {
        putenv('VORTOS_REPLAY_SECRET=short');

        $result = (new MessagingDoctorCheck())->run();

        $this->assertSame(DoctorStatus::Warning, $result->status);
        $this->assertStringContainsString('shorter than 32', $result->summary);
    }

    public function test_ok_when_secret_is_strong(): void
    {
        putenv('VORTOS_REPLAY_SECRET=' . str_repeat('a', 64));

        $result = (new MessagingDoctorCheck())->run();

        $this->assertSame(DoctorStatus::Ok, $result->status);
    }

    public function test_fix_hint_is_provided_when_secret_not_set(): void
    {
        putenv('VORTOS_REPLAY_SECRET=');

        $result = (new MessagingDoctorCheck())->run();

        $this->assertNotNull($result->fix);
        $this->assertStringContainsString('VORTOS_REPLAY_SECRET', $result->fix);
    }
}
