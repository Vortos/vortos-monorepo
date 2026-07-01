<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Http\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\Trigger\RecurringTrigger;
use Vortos\Scheduler\Security\Exception\CommandNotAllowlistedException;
use Vortos\Scheduler\Security\Exception\ScheduleAccessDeniedException;
use Vortos\Scheduler\Security\SchedulePolicyInterface;
use Vortos\Scheduler\Service\ScheduleServiceInterface;
use Vortos\Scheduler\Store\Exception\ScheduleNotFoundException;
use Vortos\SchedulerAdmin\Rendering\TwigRenderer;

#[AsController]
final class ScheduleEditController
{
    public function __construct(
        private readonly TwigRenderer          $renderer,
        private readonly ScheduleServiceInterface       $service,
        private readonly SchedulePolicyInterface $policy,
        private readonly CurrentUserProvider   $currentUser,
    ) {}

    #[Route('/admin/scheduler/{id}/edit', name: 'vortos.admin.scheduler.edit', methods: ['GET'])]
    public function edit(Request $request, string $id): Response
    {
        $user     = $this->currentUser->get();
        $tenantId = $request->query->get('tenant') ?: null;

        try {
            $schedule = $this->service->loadSchedule(ScheduleId::fromString($id), $tenantId);
        } catch (ScheduleNotFoundException) {
            throw new NotFoundException("Schedule '{$id}' not found.");
        }

        if ($schedule->source === ScheduleSource::Static) {
            throw new ForbiddenException('Static schedules cannot be edited in the admin UI.');
        }

        if (!$this->policy->canUpdate($user, $schedule)) {
            throw new ForbiddenException('You do not have permission to edit this schedule.');
        }

        return $this->renderer->render('scheduler/edit.html.twig', [
            'schedule'   => $schedule,
            'errors'     => [],
            'tenant_id'  => $tenantId,
            'active_nav' => 'schedules',
            'prefix'     => '/admin/scheduler',
        ]);
    }

    #[Route('/admin/scheduler/{id}/edit', name: 'vortos.admin.scheduler.edit.post', methods: ['POST'])]
    public function update(Request $request, string $id): Response
    {
        $user     = $this->currentUser->get();
        $tenantId = $request->request->get('tenant') ?: null;

        try {
            $existing = $this->service->loadSchedule(ScheduleId::fromString($id), $tenantId);
        } catch (ScheduleNotFoundException) {
            throw new NotFoundException("Schedule '{$id}' not found.");
        }

        if ($existing->source === ScheduleSource::Static) {
            throw new ForbiddenException('Static schedules cannot be edited in the admin UI.');
        }

        $cron      = trim((string) $request->request->get('cron', ''));
        $command   = trim((string) $request->request->get('command', ''));
        $args      = $request->request->all('args');
        $sensitive = (bool) $request->request->get('sensitive', false);
        $timezone  = trim((string) $request->request->get('timezone', 'UTC'));
        $misfire   = match ((string) $request->request->get('misfire', 'skip_missed')) {
            'fire_once_now'    => MisfirePolicy::fireOnceNow(),
            'fire_each_missed' => MisfirePolicy::fireEachMissed(),
            default            => MisfirePolicy::skipMissed(),
        };
        $overlap   = OverlapPolicy::tryFrom((string) $request->request->get('overlap', 'skip')) ?? OverlapPolicy::Skip;
        $reason    = trim((string) $request->request->get('reason', ''));

        $errors = $this->validate($cron, $command, $timezone);

        if ($errors !== []) {
            return $this->renderer->render('scheduler/edit.html.twig', [
                'schedule'   => $existing,
                'errors'     => $errors,
                'tenant_id'  => $tenantId,
                'active_nav' => 'schedules',
                'prefix'     => '/admin/scheduler',
            ], 422);
        }

        $updated = new Schedule(
            id:        $existing->id,
            name:      $existing->name,
            source:    $existing->source,
            trigger:   new RecurringTrigger($cron, new \DateTimeZone($timezone ?: 'UTC')),
            command:   new CommandSpec($command, array_values(array_filter($args, 'is_string'))),
            misfire:   $misfire,
            overlap:   $overlap,
            timezone:  new \DateTimeZone($timezone),
            jitter:    $existing->jitter,
            status:    $existing->status,
            tenantId:  $existing->tenantId,
            sensitive: $sensitive,
            metadata:  $existing->metadata,
            version:   $existing->version,
        );

        try {
            $this->service->update($updated, $user, $reason ?: null);
        } catch (ScheduleAccessDeniedException $e) {
            throw new ForbiddenException($e->getMessage());
        } catch (CommandNotAllowlistedException $e) {
            return $this->renderer->render('scheduler/edit.html.twig', [
                'schedule'   => $existing,
                'errors'     => ['command' => $e->getMessage()],
                'tenant_id'  => $tenantId,
                'active_nav' => 'schedules',
                'prefix'     => '/admin/scheduler',
            ], 422);
        }

        return new Response('', 302, ['Location' => '/admin/scheduler/' . $id]);
    }

    /** @return array<string, string> */
    private function validate(string $cron, string $command, string $timezone): array
    {
        $errors = [];

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
