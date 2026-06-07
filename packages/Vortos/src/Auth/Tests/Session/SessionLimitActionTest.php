<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\Session;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Session\SessionLimitAction;

final class SessionLimitActionTest extends TestCase
{
    public function test_has_invalidate_oldest(): void
    {
        $this->assertInstanceOf(SessionLimitAction::class, SessionLimitAction::InvalidateOldest);
    }

    public function test_has_reject_new(): void
    {
        $this->assertInstanceOf(SessionLimitAction::class, SessionLimitAction::RejectNew);
    }
}
