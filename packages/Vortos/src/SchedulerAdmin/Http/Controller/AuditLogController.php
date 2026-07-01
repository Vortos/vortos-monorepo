<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Http\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Scheduler\Audit\SchedulerAuditRepositoryInterface;
use Vortos\SchedulerAdmin\Rendering\TwigRenderer;

#[AsController]
final class AuditLogController
{
    public function __construct(
        private readonly TwigRenderer                      $renderer,
        private readonly SchedulerAuditRepositoryInterface $auditRepo,
    ) {}

    #[Route('/admin/scheduler/audit', name: 'vortos.admin.scheduler.audit', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenantId = $request->query->get('tenant') ?: null;
        $from     = $this->parseDate($request->query->get('from'));
        $to       = $this->parseDate($request->query->get('to'));
        $limit    = min(500, max(10, (int) $request->query->get('limit', '100')));

        $entries = $this->auditRepo->findByTenant($tenantId, $from, $to, $limit);

        if ($request->headers->get('HX-Request') === 'true') {
            return $this->renderer->renderFragment('audit/_entry_table.html.twig', [
                'entries'   => $entries,
                'tenant_id' => $tenantId,
                'prefix'    => '/admin/scheduler',
            ]);
        }

        return $this->renderer->render('audit/index.html.twig', [
            'entries'    => $entries,
            'tenant_id'  => $tenantId,
            'from'       => $from?->format('Y-m-d'),
            'to'         => $to?->format('Y-m-d'),
            'limit'      => $limit,
            'active_nav' => 'audit',
            'prefix'     => '/admin/scheduler',
        ]);
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
