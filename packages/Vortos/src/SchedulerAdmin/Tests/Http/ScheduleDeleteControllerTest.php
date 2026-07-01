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
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Security\Exception\ScheduleAccessDeniedException;
use Vortos\Scheduler\Service\ScheduleServiceInterface;
use Vortos\Scheduler\Store\Exception\ScheduleNotFoundException;
use Vortos\SchedulerAdmin\Http\Controller\ScheduleDeleteController;
use Vortos\SchedulerAdmin\Rendering\TwigRenderer;

final class ScheduleDeleteControllerTest extends TestCase
{
    private TwigRenderer&MockObject             $renderer;
    private ScheduleServiceInterface&MockObject $service;
    private ScheduleDeleteController            $controller;

    protected function setUp(): void
    {
        $this->renderer = $this->createMock(TwigRenderer::class);
        $this->service  = $this->createMock(ScheduleServiceInterface::class);

        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('user-1', ['ROLE_SCHEDULER_ADMIN']));

        $this->controller = new ScheduleDeleteController(
            renderer:    $this->renderer,
            service:     $this->service,
            currentUser: new CurrentUserProvider($adapter),
        );
    }

    public function test_confirm_page_renders(): void
    {
        $id       = ScheduleId::generate();
        $schedule = ScheduleTestHelper::buildSchedule($id, 'backup-daily');
        $this->service->method('loadSchedule')->willReturn($schedule);
        $this->renderer->method('render')->willReturn(new Response('<html>'));

        $request  = Request::create('/admin/scheduler/' . $id . '/delete', 'GET');
        $response = $this->controller->confirm($request, $id->toString());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_confirm_invalid_uuid_throws_404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller->confirm(Request::create('/admin/scheduler/not-a-uuid/delete', 'GET'), 'not-a-uuid');
    }

    public function test_confirm_not_found_throws_404(): void
    {
        $id = ScheduleId::generate();
        $this->service->method('loadSchedule')
            ->willThrowException(new ScheduleNotFoundException($id->toString(), null));

        $this->expectException(NotFoundException::class);
        $this->controller->confirm(Request::create('/admin/scheduler/' . $id . '/delete', 'GET'), $id->toString());
    }

    public function test_destroy_success_redirects(): void
    {
        $id      = ScheduleId::generate();
        $request = Request::create('/admin/scheduler/' . $id . '/delete', 'POST', [
            'reason' => 'Cleaning up old schedule',
        ]);

        $response = $this->controller->destroy($request, $id->toString());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/admin/scheduler', (string) $response->headers->get('Location'));
    }

    public function test_destroy_invalid_uuid_throws_404(): void
    {
        $request = Request::create('/admin/scheduler/bad-id/delete', 'POST', ['reason' => 'cleanup']);
        $this->expectException(NotFoundException::class);
        $this->controller->destroy($request, 'bad-id');
    }

    public function test_destroy_not_found_throws_404(): void
    {
        $id = ScheduleId::generate();
        $this->service->method('delete')
            ->willThrowException(new ScheduleNotFoundException($id->toString(), null));

        $request = Request::create('/admin/scheduler/' . $id . '/delete', 'POST', ['reason' => 'cleanup']);
        $this->expectException(NotFoundException::class);
        $this->controller->destroy($request, $id->toString());
    }

    public function test_destroy_access_denied_throws_403(): void
    {
        $id = ScheduleId::generate();
        $this->service->method('delete')
            ->willThrowException(new ScheduleAccessDeniedException('scheduler.delete', 'user-1'));

        $request = Request::create('/admin/scheduler/' . $id . '/delete', 'POST', ['reason' => 'cleanup']);
        $this->expectException(ForbiddenException::class);
        $this->controller->destroy($request, $id->toString());
    }

    public function test_destroy_static_schedule_throws_403(): void
    {
        $id = ScheduleId::generate();
        $this->service->method('delete')
            ->willThrowException(new \DomainException('Static schedule cannot be deleted.'));

        $request = Request::create('/admin/scheduler/' . $id . '/delete', 'POST', ['reason' => 'cleanup']);
        $this->expectException(ForbiddenException::class);
        $this->controller->destroy($request, $id->toString());
    }

    public function test_htmx_destroy_returns_fragment(): void
    {
        $id = ScheduleId::generate();
        $this->renderer->method('renderFragment')
            ->willReturn(new Response('<div>deleted</div>'));

        $request = Request::create('/admin/scheduler/' . $id . '/delete', 'POST', ['reason' => 'cleanup']);
        $request->headers->set('HX-Request', 'true');

        $response = $this->controller->destroy($request, $id->toString());

        $this->assertSame(200, $response->getStatusCode());
    }
}
