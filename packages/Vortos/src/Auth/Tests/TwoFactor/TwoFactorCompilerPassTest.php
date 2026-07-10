<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests\TwoFactor;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\TwoFactor\Attribute\Requires2FA;
use Vortos\Auth\TwoFactor\Compiler\TwoFactorCompilerPass;
use Vortos\Auth\TwoFactor\Contract\TwoFactorVerifierInterface;
use Vortos\Auth\TwoFactor\Middleware\TwoFactorMiddleware;
use Vortos\Http\Request;

final class TwoFactorCompilerPassTest extends TestCase
{
    private function containerWithMiddleware(): ContainerBuilder
    {
        $c = new ContainerBuilder();
        $c->setDefinition(TwoFactorMiddleware::class, new Definition(TwoFactorMiddleware::class));

        return $c;
    }

    private function registerVerifier(ContainerBuilder $c, string $id, string $class): void
    {
        $c->setDefinition($id, new Definition($class));
    }

    private function registerProtectedController(ContainerBuilder $c): void
    {
        $c->setDefinition(TfcpProtectedController::class, (new Definition(TfcpProtectedController::class))
            ->addTag('vortos.api.controller'));
    }

    public function test_no_middleware_definition_is_a_noop(): void
    {
        $c = new ContainerBuilder();
        (new TwoFactorCompilerPass())->process($c);
        $this->assertFalse($c->hasDefinition(TwoFactorMiddleware::class));
    }

    public function test_single_implementation_is_wired_and_aliased(): void
    {
        $c = $this->containerWithMiddleware();
        $this->registerVerifier($c, 'app.verifier', TfcpVerifierA::class);

        (new TwoFactorCompilerPass())->process($c);

        // Alias published for any interface consumer.
        $this->assertTrue($c->hasAlias(TwoFactorVerifierInterface::class));
        $this->assertSame('app.verifier', (string) $c->getAlias(TwoFactorVerifierInterface::class));

        // Middleware wired to the concrete.
        $arg = $c->getDefinition(TwoFactorMiddleware::class)->getArgument('$verifier');
        $this->assertSame('app.verifier', (string) $arg);
    }

    public function test_explicit_alias_is_respected_and_not_clobbered(): void
    {
        $c = $this->containerWithMiddleware();
        $this->registerVerifier($c, 'app.verifier_a', TfcpVerifierA::class);
        $this->registerVerifier($c, 'app.verifier_b', TfcpVerifierB::class);
        // App declares the canonical one.
        $c->setAlias(TwoFactorVerifierInterface::class, 'app.verifier_b');

        (new TwoFactorCompilerPass())->process($c);

        $this->assertSame('app.verifier_b', (string) $c->getAlias(TwoFactorVerifierInterface::class));
        $arg = $c->getDefinition(TwoFactorMiddleware::class)->getArgument('$verifier');
        $this->assertSame('app.verifier_b', (string) $arg);
    }

    public function test_multiple_impls_without_binding_and_protected_controller_fails_fast(): void
    {
        $c = $this->containerWithMiddleware();
        $this->registerVerifier($c, 'app.verifier_a', TfcpVerifierA::class);
        $this->registerVerifier($c, 'app.verifier_b', TfcpVerifierB::class);
        $this->registerProtectedController($c);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/implementations are registered/');
        (new TwoFactorCompilerPass())->process($c);
    }

    public function test_multiple_impls_with_alias_and_protected_controller_uses_alias(): void
    {
        $c = $this->containerWithMiddleware();
        $this->registerVerifier($c, 'app.verifier_a', TfcpVerifierA::class);
        $this->registerVerifier($c, 'app.verifier_b', TfcpVerifierB::class);
        $c->setAlias(TwoFactorVerifierInterface::class, 'app.verifier_a');
        $this->registerProtectedController($c);

        (new TwoFactorCompilerPass())->process($c);

        $arg = $c->getDefinition(TwoFactorMiddleware::class)->getArgument('$verifier');
        $this->assertSame('app.verifier_a', (string) $arg);
    }

    public function test_zero_impls_with_protected_controller_fails_fast(): void
    {
        $c = $this->containerWithMiddleware();
        $this->registerProtectedController($c);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no .* implementation is registered/');
        (new TwoFactorCompilerPass())->process($c);
    }

    public function test_no_verifier_and_no_protected_controller_wires_null(): void
    {
        $c = $this->containerWithMiddleware();

        (new TwoFactorCompilerPass())->process($c);

        $this->assertFalse($c->hasAlias(TwoFactorVerifierInterface::class));
        $this->assertNull($c->getDefinition(TwoFactorMiddleware::class)->getArgument('$verifier'));
    }

    public function test_multiple_impls_without_binding_but_no_protected_controller_does_not_throw(): void
    {
        $c = $this->containerWithMiddleware();
        $this->registerVerifier($c, 'app.verifier_a', TfcpVerifierA::class);
        $this->registerVerifier($c, 'app.verifier_b', TfcpVerifierB::class);

        (new TwoFactorCompilerPass())->process($c);

        // Ambiguous but harmless (no #[Requires2FA]): no canonical, no alias, null verifier.
        $this->assertFalse($c->hasAlias(TwoFactorVerifierInterface::class));
        $this->assertNull($c->getDefinition(TwoFactorMiddleware::class)->getArgument('$verifier'));
    }
}

class TfcpVerifierA implements TwoFactorVerifierInterface
{
    public function isVerified(UserIdentityInterface $identity, Request $request): bool { return true; }
    public function getChallengeUrl(): string { return '/a'; }
}

class TfcpVerifierB implements TwoFactorVerifierInterface
{
    public function isVerified(UserIdentityInterface $identity, Request $request): bool { return true; }
    public function getChallengeUrl(): string { return '/b'; }
}

class TfcpProtectedController
{
    #[Requires2FA]
    public function action(): void {}
}
