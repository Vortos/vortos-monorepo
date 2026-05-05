<?php

declare(strict_types=1);

namespace Tests\Authorization;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Authorization\Context\AuthorizationContext;
use Vortos\Authorization\Contract\PermissionResolverInterface;
use Vortos\Authorization\Contract\PolicyInterface;
use Vortos\Authorization\Engine\PolicyEngine;
use Vortos\Authorization\Engine\PolicyRegistry;
use Vortos\Authorization\Permission\PermissionRegistry;
use Vortos\Authorization\Permission\ResolvedPermissions;
use Vortos\Authorization\Storage\NullAuthorizationVersionStore;
use Vortos\Authorization\Storage\NullEmergencyDenyList;
use Vortos\Authorization\Tracing\AuthorizationTracer;
use Vortos\Authorization\Voter\RoleVoter;

final class RecordingTraceSpan
{
    /** @var array<string, mixed> */
    public array $attributes = [];
    public ?string $status = null;
    public bool $ended = false;

    public function addAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function recordException(\Throwable $e): void
    {
        $this->attributes['exception'] = $e::class;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function end(): void
    {
        $this->ended = true;
    }
}

final class RecordingTracer
{
    /** @var list<array{name: string, attributes: array<string, mixed>, span: RecordingTraceSpan}> */
    public array $spans = [];

    /**
     * @param array<string, mixed> $attributes
     */
    public function startSpan(string $name, array $attributes = []): RecordingTraceSpan
    {
        $span = new RecordingTraceSpan();
        $this->spans[] = ['name' => $name, 'attributes' => $attributes, 'span' => $span];

        return $span;
    }
}

final class TracePolicy implements PolicyInterface
{
    public function can(AuthorizationContext $auth, string $action, string $scope, mixed $resource = null): bool
    {
        return true;
    }
}

final class TraceResolver implements PermissionResolverInterface
{
    public function resolve(UserIdentityInterface $identity): ResolvedPermissions
    {
        return new ResolvedPermissions($identity->id(), $identity->roles(), $identity->roles(), ['articles.list.any']);
    }

    public function has(UserIdentityInterface $identity, string $permission): bool
    {
        return true;
    }
}

final class AuthorizationTracingTest extends TestCase
{
    public function test_decision_tracing_records_reason_and_status_when_enabled(): void
    {
        $recording = new RecordingTracer();
        $engine = new PolicyEngine(
            new PolicyRegistry(new ServiceLocator(['articles' => fn() => new TracePolicy()])),
            new PermissionRegistry([
                'articles.list.any' => [
                    'permission' => 'articles.list.any',
                    'resource' => 'articles',
                    'action' => 'list',
                    'scope' => 'any',
                    'label' => 'List any',
                    'description' => null,
                    'dangerous' => false,
                    'bypassable' => false,
                    'group' => 'Articles',
                    'catalogClass' => self::class,
                ],
            ]),
            new TraceResolver(),
            new NullEmergencyDenyList(),
            new NullAuthorizationVersionStore(),
            new RoleVoter(),
            tracer: new AuthorizationTracer($recording, traceDecisions: true),
        );

        $decision = $engine->decide(new UserIdentity('user-1', ['ROLE_USER']), 'articles.list.any');

        $this->assertTrue($decision->allowed());
        $this->assertCount(1, $recording->spans);
        $this->assertSame('authorization.decision', $recording->spans[0]['name']);
        $this->assertSame('articles.list.any', $recording->spans[0]['attributes']['authorization.permission']);
        $this->assertSame('ok', $recording->spans[0]['span']->status);
        $this->assertTrue($recording->spans[0]['span']->ended);
        $this->assertSame('allowed', $recording->spans[0]['span']->attributes['authorization.reason']);
    }

    public function test_decision_tracing_is_off_by_default(): void
    {
        $recording = new RecordingTracer();
        $engine = new PolicyEngine(
            new PolicyRegistry(new ServiceLocator(['articles' => fn() => new TracePolicy()])),
            new PermissionRegistry([
                'articles.list.any' => [
                    'permission' => 'articles.list.any',
                    'resource' => 'articles',
                    'action' => 'list',
                    'scope' => 'any',
                    'label' => 'List any',
                    'description' => null,
                    'dangerous' => false,
                    'bypassable' => false,
                    'group' => 'Articles',
                    'catalogClass' => self::class,
                ],
            ]),
            new TraceResolver(),
            new NullEmergencyDenyList(),
            new NullAuthorizationVersionStore(),
            new RoleVoter(),
            tracer: new AuthorizationTracer($recording),
        );

        $engine->decide(new UserIdentity('user-1', ['ROLE_USER']), 'articles.list.any');

        $this->assertSame([], $recording->spans);
    }
}
