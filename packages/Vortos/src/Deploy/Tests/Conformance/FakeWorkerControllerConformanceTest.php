<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Conformance;

use Vortos\Deploy\Testing\WorkerControllerConformanceTestCase;
use Vortos\Deploy\Tests\Fixtures\FakeWorkerController;
use Vortos\Deploy\Worker\WorkerControllerInterface;

final class FakeWorkerControllerConformanceTest extends WorkerControllerConformanceTestCase
{
    protected function createController(): WorkerControllerInterface
    {
        return new FakeWorkerController();
    }

    protected function expectedKey(): string
    {
        return 'fake-worker-controller';
    }

    public function test_honestly_reports_no_remote_control(): void
    {
        $this->assertHonestlyUnsupported(
            $this->createController()->capabilities(),
            'remote_control',
        );
    }
}
