<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Tests\Http;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Scheduler\Security\Exception\CommandNotAllowlistedException;
use Vortos\Scheduler\Security\Exception\ScheduleAccessDeniedException;
use Vortos\Scheduler\Service\ScheduleServiceInterface;
use Vortos\Scheduler\Store\Exception\ScheduleNameConflictException;
use Vortos\SchedulerAdmin\Http\Controller\ScheduleCreateController;
use Vortos\SchedulerAdmin\Rendering\TwigRenderer;

final class ScheduleCreateControllerTest extends TestCase
{
    private TwigRenderer&MockObject  $renderer;
    private ScheduleServiceInterface&MockObject $service;
    private CurrentUserProvider       $currentUser;
    private ScheduleCreateController  $controller;

    protected function setUp(): void
    {
        $this->renderer    = $this->createMock(TwigRenderer::class);
        $this->service     = $this->createMock(ScheduleServiceInterface::class);

        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('user-1', ['ROLE_SCHEDULER_ADMIN']));
        $this->currentUser = new CurrentUserProvider($adapter);

        $this->controller = new ScheduleCreateController($this->renderer, $this->service, $this->currentUser);
    }

    public function test_get_renders_blank_form(): void
    {
        $this->renderer->expects($this->once())
            ->method('render')
            ->with('scheduler/create.html.twig', $this->anything())
            ->willReturn(new Response('<form>'));

        $response = $this->controller->new(Request::create('/admin/scheduler/create', 'GET'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_post_empty_name_returns_422(): void
    {
        $this->renderer->method('render')
            ->willReturn(new Response('<form>', 422));

        $request  = Request::create('/admin/scheduler/create', 'POST', [
            'name'    => '',
            'cron'    => '0 * * * *',
            'command' => 'App\\Command\\BackupCommand',
        ]);

        $response = $this->controller->store($request);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_post_invalid_name_pattern_returns_422(): void
    {
        $this->renderer->method('render')
            ->willReturn(new Response('<form>', 422));

        $request  = Request::create('/admin/scheduler/create', 'POST', [
            'name'    => 'INVALID UPPERCASE',
            'cron'    => '0 * * * *',
            'command' => 'App\\Command\\BackupCommand',
        ]);

        $response = $this->controller->store($request);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_post_success_redirects_to_detail(): void
    {
        $this->service->method('create')->willReturnArgument(0);

        $request = Request::create('/admin/scheduler/create', 'POST', [
            'name'     => 'backup-daily',
            'cron'     => '0 2 * * *',
            'command'  => 'App\\Command\\BackupCommand',
            'timezone' => 'UTC',
        ]);

        $response = $this->controller->store($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringStartsWith('/admin/scheduler/', (string) $response->headers->get('Location'));
    }

    public function test_access_denied_returns_403(): void
    {
        $this->service->method('create')
            ->willThrowException(new ScheduleAccessDeniedException('scheduler.create', 'user-1'));

        $this->renderer->method('render')
            ->willReturn(new Response('<form>', 403));

        $request = Request::create('/admin/scheduler/create', 'POST', [
            'name'    => 'backup-daily',
            'cron'    => '0 2 * * *',
            'command' => 'App\\Command\\BackupCommand',
        ]);

        $response = $this->controller->store($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_command_not_allowlisted_returns_422(): void
    {
        $this->service->method('create')
            ->willThrowException(new CommandNotAllowlistedException('BadCommand'));

        $this->renderer->method('render')
            ->willReturn(new Response('<form>', 422));

        $request = Request::create('/admin/scheduler/create', 'POST', [
            'name'    => 'backup-daily',
            'cron'    => '0 2 * * *',
            'command' => 'App\\Command\\BadCommand',
        ]);

        $response = $this->controller->store($request);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_name_conflict_returns_409(): void
    {
        $this->service->method('create')
            ->willThrowException(new ScheduleNameConflictException('backup-daily', null));

        $this->renderer->method('render')
            ->willReturn(new Response('<form>', 409));

        $request = Request::create('/admin/scheduler/create', 'POST', [
            'name'    => 'backup-daily',
            'cron'    => '0 2 * * *',
            'command' => 'App\\Command\\BackupCommand',
        ]);

        $response = $this->controller->store($request);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function test_invalid_timezone_returns_422(): void
    {
        $this->renderer->method('render')
            ->willReturn(new Response('<form>', 422));

        $request = Request::create('/admin/scheduler/create', 'POST', [
            'name'     => 'backup-daily',
            'cron'     => '0 2 * * *',
            'command'  => 'App\\Command\\BackupCommand',
            'timezone' => 'Mars/Olympus',
        ]);

        $response = $this->controller->store($request);

        $this->assertSame(422, $response->getStatusCode());
    }
}
