<?php

declare(strict_types=1);

namespace Vortos\AuditAdmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Audit\Enum\Outcome;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Enum\Sensitivity;
use Vortos\Audit\Event\AuditActor;
use Vortos\Audit\Event\AuditEvent;
use Vortos\Audit\Event\AuditTarget;
use Vortos\Audit\Storage\StoredAuditEvent;
use Vortos\AuditAdmin\Http\Serializer\AuditRecordPresenter;

final class AuditRecordPresenterTest extends TestCase
{
    public function test_serialises_camel_case_shape_with_sequence(): void
    {
        $event = AuditEvent::create(
            Scope::Tenant,
            'org-1',
            AuditActor::user('u-1', 'Ada', ['ROLE_ADMIN']),
            'member.invited',
            new AuditTarget('member', 'm-9', 'Grace'),
            Sensitivity::High,
            Outcome::Allowed,
        );
        $stored = new StoredAuditEvent($event, 'tenant:org-1', 7, 'prev', 'hash', 'sig');

        $out = AuditRecordPresenter::toArray($stored);

        self::assertSame('org-1', $out['tenantId']);
        self::assertSame('member.invited', $out['action']);
        self::assertSame('high', $out['sensitivity']);
        self::assertSame(7, $out['sequence']);
        self::assertSame(['type' => 'member', 'id' => 'm-9', 'label' => 'Grace'], $out['target']);
        self::assertSame('u-1', $out['actor']['id']);
        self::assertNull($out['actor']['onBehalfOf']);
    }

    public function test_emits_impersonation_chain_recursively_as_on_behalf_of(): void
    {
        $operator = AuditActor::user('op-1', 'Support Op', ['ROLE_SUPPORT']);
        $actor    = AuditActor::user('u-1', 'Ada')->withOnBehalfOf($operator);
        $event    = AuditEvent::create(Scope::Platform, null, $actor, 'account.exported');
        $stored   = new StoredAuditEvent($event, 'platform', 1, 'p', 'h', 's');

        $out = AuditRecordPresenter::toArray($stored);

        self::assertSame('u-1', $out['actor']['id']);
        self::assertNotNull($out['actor']['onBehalfOf']);
        self::assertSame('op-1', $out['actor']['onBehalfOf']['id']);
        self::assertNull($out['actor']['onBehalfOf']['onBehalfOf']);
    }
}
