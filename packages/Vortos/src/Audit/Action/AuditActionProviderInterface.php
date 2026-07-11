<?php

declare(strict_types=1);

namespace Vortos\Audit\Action;

/**
 * Apps and framework modules declare their audited actions by implementing this and
 * getting auto-tagged. The registry aggregates every provider into one vocabulary at
 * container build time, so the set of valid actions is known statically and can be
 * validated, documented, and listed in the admin UI.
 *
 * Example (app side):
 *   final class IdentityAuditActions implements AuditActionProviderInterface {
 *       public function actions(): array {
 *           return [
 *               new RegisteredAction('member.role.granted', 'Role granted to member', Sensitivity::High),
 *               new RegisteredAction('member.invited', 'Member invited'),
 *           ];
 *       }
 *   }
 */
interface AuditActionProviderInterface
{
    public const TAG = 'vortos.audit.action_provider';

    /**
     * @return list<RegisteredAction>
     */
    public function actions(): array;
}
