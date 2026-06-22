<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Tests\Http;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlagsAdmin\Http\Controller\InsightsController;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Request;
use Vortos\Http\Response;

final class InsightsControllerTest extends TestCase
{
    private ManagementAuthzGateInterface $authz;
    private InsightsController $controller;
    private TwigRenderer $renderer;

    protected function setUp(): void
    {
        $this->authz = $this->createMock(ManagementAuthzGateInterface::class);
        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('admin-1', ['ROLE_ADMIN']));

        $this->renderer = $this->createMock(TwigRenderer::class);

        $this->controller = new InsightsController(
            renderer: $this->renderer,
            authz: $this->authz,
            currentUser: new CurrentUserProvider($adapter),
            rateLimit: $this->createMock(FlagRateLimitService::class),
        );
    }

    public function test_index_requires_insights_read_permission(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->index(new Request());
    }

    public function test_index_renders_insights_page(): void
    {
        $this->authz->method('requirePermission');

        $this->renderer->method('render')
            ->with('insights/index.html.twig', $this->callback(function (array $ctx) {
                return $ctx['active_nav'] === 'insights'
                    && is_array($ctx['allowed_labels']);
            }))
            ->willReturn(new Response(''));

        $this->controller->index(new Request());
    }

    public function test_data_endpoint_requires_permission(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->data(new Request());
    }

    public function test_data_endpoint_returns_bounded_labels_only(): void
    {
        $this->authz->method('requirePermission');

        $response = $this->controller->data(new Request());
        $data = json_decode($response->getContent(), true);

        $this->assertSame(['flag', 'result', 'variant', 'operation'], $data['labels']);
        $this->assertArrayNotHasKey('userId', $data);
        $this->assertArrayNotHasKey('tenantId', $data);
    }

    public function test_data_endpoint_does_not_leak_identifiers(): void
    {
        $this->authz->method('requirePermission');

        $response = $this->controller->data(Request::create('', 'GET', ['flag' => 'test']));
        $body = $response->getContent();

        $this->assertStringNotContainsString('userId', $body);
        $this->assertStringNotContainsString('email', $body);
        $this->assertStringNotContainsString('tenantId', $body);
    }
}
