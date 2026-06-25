<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Driver\Terraform;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Driver\Terraform\ProcessOutcome;

final class ProcessOutcomeTest extends TestCase
{
    public function test_exit_zero_is_success(): void
    {
        $this->assertTrue((new ProcessOutcome(0, '', '', 100))->isSuccess());
    }

    public function test_exit_nonzero_is_failure(): void
    {
        $this->assertFalse((new ProcessOutcome(1, '', '', 100))->isSuccess());
        $this->assertFalse((new ProcessOutcome(2, '', '', 100))->isSuccess());
    }
}
