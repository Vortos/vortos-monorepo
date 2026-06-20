<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Vortos\Foundation\DependencyInjection\Attribute\Value;

final class ValueAttributeTest extends TestCase
{
    public function test_plain_param_routes_to_param_syntax(): void
    {
        $attr = new Value('my.param');

        $this->assertSame('%my.param%', $attr->value);
    }

    public function test_percent_expression_routes_to_value_syntax(): void
    {
        $attr = new Value('%prefix%role_permissions');

        // Raw value string is preserved as-is (not double-wrapped)
        $this->assertSame('%prefix%role_permissions', $attr->value);
    }

    public function test_env_routes_to_env_syntax(): void
    {
        $attr = new Value(env: 'MY_VAR');

        $this->assertSame('%env(MY_VAR)%', $attr->value);
    }

    public function test_expr_routes_to_expression_syntax(): void
    {
        if (!class_exists(\Symfony\Component\ExpressionLanguage\Expression::class)) {
            $this->markTestSkipped('symfony/expression-language not installed');
        }

        $attr = new Value(expr: 'parameter("a")');

        $this->assertInstanceOf(\Symfony\Component\ExpressionLanguage\Expression::class, $attr->value);
        $this->assertSame('parameter("a")', (string) $attr->value);
    }

    public function test_default_with_param_produces_expression(): void
    {
        if (!class_exists(\Symfony\Component\ExpressionLanguage\Expression::class)) {
            $this->markTestSkipped('symfony/expression-language not installed');
        }

        $attr = new Value('p', default: 'x');

        $this->assertInstanceOf(\Symfony\Component\ExpressionLanguage\Expression::class, $attr->value);
        $this->assertStringContainsString('container.hasParameter("p")', (string) $attr->value);
        $this->assertStringContainsString('parameter("p")', (string) $attr->value);
        $this->assertStringContainsString('"x"', (string) $attr->value);
    }

    public function test_throws_when_no_argument_provided(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/requires exactly one/i');

        new Value();
    }

    public function test_throws_when_multiple_arguments_provided(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/exactly one/i');

        new Value(param: 'foo', env: 'BAR');
    }

    public function test_throws_when_default_used_with_env(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/\$default is only valid alongside \$param/i');

        new Value(env: 'MY_VAR', default: 'fallback');
    }

    public function test_throws_when_default_used_with_expr(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/\$default is only valid alongside \$param/i');

        new Value(expr: 'parameter("x")', default: 'fallback');
    }

    public function test_throws_when_default_requires_expression_language(): void
    {
        if (class_exists(\Symfony\Component\ExpressionLanguage\Expression::class)) {
            $this->markTestSkipped('symfony/expression-language is installed; cannot test the missing-package error');
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/expression-language/i');

        new Value('my.param', default: 'fallback');
    }

    public function test_existing_param_mode_unchanged(): void
    {
        // Regression: existing code using the named $param argument must still work.
        $attr = new Value(param: 'x');

        $this->assertSame('%x%', $attr->value);
    }

    public function test_existing_env_mode_unchanged(): void
    {
        // Regression: existing code using the named $env argument must still work.
        $attr = new Value(env: 'X');

        $this->assertSame('%env(X)%', $attr->value);
    }

    public function test_extends_autowire(): void
    {
        $this->assertInstanceOf(Autowire::class, new Value('param.name'));
    }
}
