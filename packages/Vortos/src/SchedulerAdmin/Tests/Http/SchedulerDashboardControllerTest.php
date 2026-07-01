<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Tests\Http;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Vortos\Http\Request;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;
use Vortos\Scheduler\Store\ScheduleStoreInterface;
use Vortos\SchedulerAdmin\Http\Controller\SchedulerDashboardController;
use Vortos\SchedulerAdmin\Rendering\TwigRenderer;

final class SchedulerDashboardControllerTest extends TestCase
{
    private ScheduleStoreInterface&MockObject    $scheduleStore;
    private StaticScheduleRegistry               $staticRegistry;
    private ScheduleRunStoreInterface&MockObject $runStore;
    private TwigRenderer&MockObject             $renderer;
    private SchedulerDashboardController         $controller;

    protected function setUp(): void
    {
        $this->scheduleStore  = $this->createMock(ScheduleStoreInterface::class);
        $this->staticRegistry = new StaticScheduleRegistry([]);
        $this->runStore       = $this->createMock(ScheduleRunStoreInterface::class);
        $this->renderer       = $this->createMock(TwigRenderer::class);

        $this->controller = new SchedulerDashboardController(
            renderer:       $this->renderer,
            scheduleStore:  $this->scheduleStore,
            staticRegistry: $this->staticRegistry,
            runStore:       $this->runStore,
        );
    }

    public function test_full_page_render_on_normal_request(): void
    {
        $this->scheduleStore->method('findAll')->willReturn(new \ArrayIterator([]));

        $this->renderer->expects($this->once())
            ->method('render')
            ->with('scheduler/dashboard.html.twig', $this->anything())
            ->willReturn(new \Vortos\Http\Response('<html>', 200));

        $request  = Request::create('/admin/scheduler', 'GET');
        $response = $this->controller->index($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_htmx_request_renders_fragment(): void
    {
        $this->scheduleStore->method('findAll')->willReturn(new \ArrayIterator([]));

        $this->renderer->expects($this->once())
            ->method('renderFragment')
            ->with('scheduler/_schedule_table.html.twig', $this->anything())
            ->willReturn(new \Vortos\Http\Response('<table>', 200));

        $request = Request::create('/admin/scheduler', 'GET');
        $request->headers->set('HX-Request', 'true');

        $response = $this->controller->index($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_search_filter_applied(): void
    {
        $this->scheduleStore->method('findAll')->willReturn(new \ArrayIterator([]));

        $capturedCtx = null;
        $this->renderer->method('render')
            ->willReturnCallback(function (string $tpl, array $ctx) use (&$capturedCtx) {
                $capturedCtx = $ctx;

                return new \Vortos\Http\Response('<html>', 200);
            });

        $request = Request::create('/admin/scheduler?q=backup', 'GET');
        $this->controller->index($request);

        $this->assertSame('backup', $capturedCtx['search'] ?? null);
    }

    public function test_pagination_defaults_to_page_1(): void
    {
        $this->scheduleStore->method('findAll')->willReturn(new \ArrayIterator([]));

        $capturedCtx = null;
        $this->renderer->method('render')
            ->willReturnCallback(function (string $tpl, array $ctx) use (&$capturedCtx) {
                $capturedCtx = $ctx;

                return new \Vortos\Http\Response('<html>', 200);
            });

        $request = Request::create('/admin/scheduler', 'GET');
        $this->controller->index($request);

        $this->assertSame(1, $capturedCtx['page'] ?? null);
    }
}
