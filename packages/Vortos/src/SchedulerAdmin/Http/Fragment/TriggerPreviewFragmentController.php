<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Http\Fragment;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\SchedulerAdmin\AdminConfig;
use Vortos\SchedulerAdmin\Rendering\TwigRenderer;

/**
 * HTMX fragment: compute the next N scheduled fire times for a cron expression.
 * Rendered live as the user types in create/edit forms.
 */
#[AsController]
final class TriggerPreviewFragmentController
{
    public function __construct(
        private readonly TwigRenderer $renderer,
        private readonly AdminConfig  $config,
    ) {}

    #[Route('/admin/scheduler/preview/trigger', name: 'vortos.admin.scheduler.fragment.trigger_preview', methods: ['GET'])]
    public function preview(Request $request): Response
    {
        $cron     = trim((string) $request->query->get('cron', ''));
        $timezone = trim((string) $request->query->get('timezone', 'UTC'));
        $count    = min($this->config->previewMaxCount, max(1, (int) $request->query->get('count', '5')));

        $fireTimes = [];
        $error     = null;

        if ($cron !== '') {
            try {
                $tz   = new \DateTimeZone($timezone ?: 'UTC');
                $now  = new \DateTimeImmutable('now', $tz);
                $next = $now;

                for ($i = 0; $i < $count; $i++) {
                    $next        = $this->nextCronTime($cron, $next, $tz);
                    $fireTimes[] = $next;
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return $this->renderer->renderFragment('scheduler/_trigger_preview.html.twig', [
            'cron'       => $cron,
            'timezone'   => $timezone,
            'fire_times' => $fireTimes,
            'error'      => $error,
        ]);
    }

    private function nextCronTime(string $cron, \DateTimeImmutable $after, \DateTimeZone $tz): \DateTimeImmutable
    {
        // Use dragonmantank/cron-expression if available (installed with vortos-scheduler).
        if (class_exists(\Cron\CronExpression::class)) {
            $expr = new \Cron\CronExpression($cron);
            $next = $expr->getNextRunDate($after->format('Y-m-d H:i:s'), 0, false, $tz->getName());

            return \DateTimeImmutable::createFromMutable($next);
        }

        // Minimal fallback: add 1 hour steps (only for when cron library is absent).
        return $after->modify('+1 hour');
    }
}
