<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\Auth\TwoFactor\Contract\TwoFactorVerifierInterface;
use Vortos\FeatureFlagsAdmin\DependencyInjection\Compiler\RequireTwoFactorVerifierPass;
use Vortos\FeatureFlagsAdmin\Http\Middleware\AdminAuthMiddleware;

final class RequireTwoFactorVerifierPassTest extends TestCase
{
    private function containerWithConsole(bool $require2fa): ContainerBuilder
    {
        $c = new ContainerBuilder();
        $c->setDefinition(AdminAuthMiddleware::class, new Definition(AdminAuthMiddleware::class));
        $c->setParameter('feature_flags_admin.require_2fa', $require2fa);

        return $c;
    }

    public function test_require_2fa_without_any_verifier_fails_fast(): void
    {
        $c = $this->containerWithConsole(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/require_2fa = true/');
        (new RequireTwoFactorVerifierPass())->process($c);
    }

    public function test_require_2fa_with_interface_alias_passes(): void
    {
        $c = $this->containerWithConsole(true);
        $c->setDefinition('app.verifier', new Definition(\stdClass::class));
        $c->setAlias(TwoFactorVerifierInterface::class, 'app.verifier');

        (new RequireTwoFactorVerifierPass())->process($c);
        $this->addToAssertionCount(1); // no exception
    }

    public function test_require_2fa_with_interface_definition_passes(): void
    {
        $c = $this->containerWithConsole(true);
        $c->setDefinition(TwoFactorVerifierInterface::class, new Definition(\stdClass::class));

        (new RequireTwoFactorVerifierPass())->process($c);
        $this->addToAssertionCount(1);
    }

    public function test_require_2fa_false_is_a_noop(): void
    {
        $c = $this->containerWithConsole(false);

        (new RequireTwoFactorVerifierPass())->process($c);
        $this->addToAssertionCount(1);
    }

    public function test_console_disabled_is_a_noop(): void
    {
        // No AdminAuthMiddleware definition ⇒ console not enabled ⇒ nothing to guard,
        // even with require_2fa set.
        $c = new ContainerBuilder();
        $c->setParameter('feature_flags_admin.require_2fa', true);

        (new RequireTwoFactorVerifierPass())->process($c);
        $this->addToAssertionCount(1);
    }
}
