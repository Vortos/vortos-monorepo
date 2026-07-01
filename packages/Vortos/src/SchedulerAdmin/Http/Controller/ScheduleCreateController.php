<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Http\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\RecurringTrigger;
use Vortos\Scheduler\Security\Exception\CommandNotAllowlistedException;
use Vortos\Scheduler\Security\Exception\ScheduleAccessDeniedException;
use Vortos\Scheduler\Service\ScheduleServiceInterface;
use Vortos\Scheduler\Store\Exception\ScheduleNameConflictException;
use Vortos\SchedulerAdmin\Rendering\TwigRenderer;

#[AsController]
final class ScheduleCreateController
{
    public function __construct(
        private readonly TwigRenderer        $renderer,
        private readonly ScheduleServiceInterface     $service,
        private readonly CurrentUserProvider $currentUser,
    ) {}

    #[Route('/admin/scheduler/create', name: 'vortos.admin.scheduler.create', methods: ['GET'])]
    public function new(Request $request): Response
    {
        return $this->renderer->render('scheduler/create.html.twig', [
            'errors'      => [],
            'values'      => [],
            'active_nav'  => 'schedules',
            'prefix'      => '/admin/scheduler',
        ]);
    }

    #[Route('/admin/scheduler/create', name: 'vortos.admin.scheduler.create.post', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $user = $this->currentUser->get();

        $name      = trim((string) $request->request->get('name', ''));
        $cron      = trim((string) $request->request->get('cron', ''));
        $command   = trim((string) $request->request->get('command', ''));
        $args      = $request->request->all('args');
        $tenantId  = $request->request->get('tenant') ?: null;
        $sensitive = (bool) $request->request->get('sensitive', false);
        $timezone  = trim((string) $request->request->get('timezone', 'UTC'));
        $misfire   = match ((string) $request->request->get('misfire', 'skip_missed')) {
            'fire_once_now'    => MisfirePolicy::fireOnceNow(),
            'fire_each_missed' => MisfirePolicy::fireEachMissed(),
            default            => MisfirePolicy::skipMissed(),
        };
        $overlap   = OverlapPolicy::tryFrom((string) $request->request->get('overlap', 'skip')) ?? OverlapPolicy::Skip;

        $errors = $this->validate($name, $cron, $command, $timezone);

        if ($errors !== []) {
            return $this->renderer->render('scheduler/create.html.twig', [
                'errors'     => $errors,
                'values'     => $request->request->all(),
                'active_nav' => 'schedules',
                'prefix'     => '/admin/scheduler',
            ], 422);
        }

        $schedule = new Schedule(
            id:        ScheduleId::generate(),
            name:      $name,
            source:    ScheduleSource::Dynamic,
            trigger:   new RecurringTrigger($cron, new \DateTimeZone($timezone ?: 'UTC')),
            command:   new CommandSpec($command, array_values(array_filter($args, 'is_string'))),
            misfire:   $misfire,
            overlap:   $overlap,
            timezone:  new \DateTimeZone($timezone),
            jitter:    null,
            status:    ScheduleStatus::Active,
            tenantId:  $tenantId,
            sensitive: $sensitive,
        );

        try {
            $this->service->create($schedule, $user);
        } catch (ScheduleAccessDeniedException $e) {
            return $this->renderer->render('scheduler/create.html.twig', [
                'errors'     => ['permission' => $e->getMessage()],
                'values'     => $request->request->all(),
                'active_nav' => 'schedules',
                'prefix'     => '/admin/scheduler',
            ], 403);
        } catch (CommandNotAllowlistedException $e) {
            return $this->renderer->render('scheduler/create.html.twig', [
                'errors'     => ['command' => $e->getMessage()],
                'values'     => $request->request->all(),
                'active_nav' => 'schedules',
                'prefix'     => '/admin/scheduler',
            ], 422);
        } catch (ScheduleNameConflictException) {
            return $this->renderer->render('scheduler/create.html.twig', [
                'errors'     => ['name' => "A schedule named '{$name}' already exists."],
                'values'     => $request->request->all(),
                'active_nav' => 'schedules',
                'prefix'     => '/admin/scheduler',
            ], 409);
        }

        return new Response('', 302, ['Location' => '/admin/scheduler/' . $schedule->id->toString()]);
    }

    /** @return array<string, string> */
    private function validate(string $name, string $cron, string $command, string $timezone): array
    {
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name)) {
            $errors['name'] = 'Name must match /^[a-z0-9][a-z0-9_-]*$/';
        }

        if ($cron === '') {
            $errors['cron'] = 'Cron expression is required.';
        }

        if ($command === '') {
            $errors['command'] = 'Command class is required.';
        }

        if ($timezone !== '' && !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = "Unknown timezone '{$timezone}'.";
        }

        return $errors;
    }
}
