<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Tests\Http;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Security\Exception\ScheduleAccessDeniedException;
use Vortos\Scheduler\Security\SchedulePolicyInterface;
use Vortos\Scheduler\Service\ScheduleServiceInterface;
use Vortos\Scheduler\Store\Exception\ScheduleNotFoundException;
use Vortos\SchedulerAdmin\Http\Fragment\ScheduleFragmentController;
use Vortos\SchedulerAdmin\Rendering\TwigRenderer;

final class ScheduleFragmentControllerTest extends TestCase
{
    private TwigRenderer&MockObject             $renderer;
    private ScheduleServiceInterface&MockObject $service;
    private SchedulePolicyInterface&MockObject  $policy;
    private ScheduleFragmentController          $controller;

    protected function setUp(): void
    {
        $this->renderer = $this->createMock(TwigRenderer::class);
        $this->service  = $this->createMock(ScheduleServiceInterface::class);
        $this->policy   = $this->createMock(SchedulePolicyInterface::class);

        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('user-1', ['ROLE_SCHEDULER_ADMIN']));

        $this->controller = new ScheduleFragmentController(
            renderer:    $this->renderer,
            service:     $this->service,
            policy:      $this->policy,
            currentUser: new CurrentUserProvider($adapter),
        );
    }

    public function test_pause_returns_status_badge_fragment(): void
    {
        $id       = ScheduleId::generate();
        $schedule = ScheduleTestHelper::buildSchedule($id, status: ScheduleStatus::Paused);
        $this->service->method('pause')->willReturn($schedule);
        $this->renderer->expects($this->once())
            ->method('renderFragment')
            ->with('scheduler/_status_badge.html.twig', $this->anything())
            ->willReturn(new Response('<span>paused</span>'));

        $request = Request::create('/admin/scheduler/' . $id . '/pause', 'POST');
        $this->controller->pause($request, $id->toString());
    }

    public function test_pause_invalid_uuid_throws_404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller->pause(Request::create('/admin/scheduler/bad/pause', 'POST'), 'bad');
    }

    public function test_pause_not_found_throws_404(): void
    {
        $id = ScheduleId::generate();
        $this->service->method('pause')
            ->willThrowException(new ScheduleNotFoundException($id->toString(), null));

        $this->expectException(NotFoundException::class);
        $this->controller->pause(Request::create('/admin/scheduler/' . $id . '/pause', 'POST'), $id->toString());
    }

    public function test_pause_access_denied_throws_403(): void
    {
        $id = ScheduleId::generate();
        $this->service->method('pause')
            ->willThrowException(new ScheduleAccessDeniedException('scheduler.pause', 'user-1'));

        $this->expectException(ForbiddenException::class);
        $this->controller->pause(Request::create('/admin/scheduler/' . $id . '/pause', 'POST'), $id->toString());
    }

    public function test_resume_returns_status_badge_fragment(): void
    {
        $id       = ScheduleId::generate();
        $schedule = ScheduleTestHelper::buildSchedule($id, status: ScheduleStatus::Active);
        $this->service->method('resume')->willReturn($schedule);
        $this->renderer->expects($this->once())
            ->method('renderFragment')
            ->with('scheduler/_status_badge.html.twig', $this->anything())
            ->willReturn(new Response('<span>active</span>'));

        $request = Request::create('/admin/scheduler/' . $id . '/resume', 'POST');
        $this->controller->resume($request, $id->toString());
    }

    public function test_run_now_returns_run_result_fragment(): void
    {
        $id     = ScheduleId::generate();
        $result = FireDispatchResult::Dispatched;
        $this->service->method('runNow')->willReturn($result);
        $this->renderer->expects($this->once())
            ->method('renderFragment')
            ->with('scheduler/_run_result.html.twig', $this->anything())
            ->willReturn(new Response('<div>dispatched</div>'));

        $request = Request::create('/admin/scheduler/' . $id . '/run-now', 'POST', ['reason' => 'manual test']);
        $this->controller->runNow($request, $id->toString());
    }

    public function test_run_now_invalid_uuid_throws_404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller->runNow(Request::create('/admin/scheduler/not-valid/run-now', 'POST'), 'not-valid');
    }

    public function test_run_now_access_denied_throws_403(): void
    {
        $id = ScheduleId::generate();
        $this->service->method('runNow')
            ->willThrowException(new ScheduleAccessDeniedException('scheduler.run_now', 'user-1'));

        $this->expectException(ForbiddenException::class);
        $this->controller->runNow(Request::create('/admin/scheduler/' . $id . '/run-now', 'POST'), $id->toString());
    }
}
