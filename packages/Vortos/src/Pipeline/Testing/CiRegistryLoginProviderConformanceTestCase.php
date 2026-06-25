<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Testing;

use Vortos\OpsKit\Testing\ConformanceTestCase;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Model\ActionStep;
use Vortos\Pipeline\Model\Permissions;
use Vortos\Pipeline\Registry\CiRegistryLoginProviderInterface;
use Vortos\Pipeline\Registry\RegistryLoginContext;

abstract class CiRegistryLoginProviderConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createProvider(): CiRegistryLoginProviderInterface;

    abstract protected function createDefinition(): PipelineDefinition;

    protected function createDriver(): CiRegistryLoginProviderInterface
    {
        return $this->createProvider();
    }

    final public function test_login_step_returns_action_step(): void
    {
        $provider = $this->createProvider();
        $context = new RegistryLoginContext($this->createDefinition());

        $step = $provider->loginStep($context);

        $this->assertInstanceOf(ActionStep::class, $step);
    }

    final public function test_login_step_has_non_empty_name(): void
    {
        $provider = $this->createProvider();
        $context = new RegistryLoginContext($this->createDefinition());

        $step = $provider->loginStep($context);

        $this->assertNotSame('', $step->name);
    }

    final public function test_login_step_uses_docker_login_action(): void
    {
        $provider = $this->createProvider();
        $context = new RegistryLoginContext($this->createDefinition());

        $step = $provider->loginStep($context);

        $this->assertSame('docker', $step->action->owner);
        $this->assertSame('login-action', $step->action->repo);
    }

    final public function test_login_step_action_is_sha_pinned(): void
    {
        $provider = $this->createProvider();
        $context = new RegistryLoginContext($this->createDefinition());

        $step = $provider->loginStep($context);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{40}$/',
            $step->action->sha,
            sprintf('Action SHA "%s" must be a 40-char hex SHA', $step->action->sha),
        );
    }

    final public function test_login_step_specifies_registry(): void
    {
        $provider = $this->createProvider();
        $context = new RegistryLoginContext($this->createDefinition());

        $step = $provider->loginStep($context);

        $this->assertArrayHasKey('registry', $step->with, 'login step must declare a registry');
        $this->assertNotSame('', $step->with['registry']);
    }

    final public function test_login_step_specifies_username(): void
    {
        $provider = $this->createProvider();
        $context = new RegistryLoginContext($this->createDefinition());

        $step = $provider->loginStep($context);

        $this->assertArrayHasKey('username', $step->with, 'login step must declare a username');
        $this->assertNotSame('', $step->with['username']);
    }

    final public function test_login_step_specifies_password(): void
    {
        $provider = $this->createProvider();
        $context = new RegistryLoginContext($this->createDefinition());

        $step = $provider->loginStep($context);

        $this->assertArrayHasKey('password', $step->with, 'login step must declare a password/token');
        $this->assertNotSame('', $step->with['password']);
    }

    final public function test_required_permissions_returns_permissions_instance(): void
    {
        $provider = $this->createProvider();

        $perms = $provider->requiredPermissions();

        $this->assertInstanceOf(Permissions::class, $perms);
    }

    final public function test_login_step_is_deterministic(): void
    {
        $provider = $this->createProvider();
        $context = new RegistryLoginContext($this->createDefinition());

        $first = $provider->loginStep($context);
        $second = $provider->loginStep($context);

        $this->assertSame($first->name, $second->name);
        $this->assertSame($first->action->sha, $second->action->sha);
        $this->assertSame($first->with, $second->with);
    }
}
