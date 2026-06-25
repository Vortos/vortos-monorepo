<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Conformance;

use Vortos\Alerts\Notifier\Driver\Null\NullNotifier;
use Vortos\Alerts\Notifier\NotifierInterface;
use Vortos\Alerts\Testing\NotifierConformanceTestCase;

final class NullNotifierConformanceTest extends NotifierConformanceTestCase
{
    protected function createNotifier(): NotifierInterface
    {
        return new NullNotifier();
    }

    protected function expectedKey(): string
    {
        return 'null';
    }
}
