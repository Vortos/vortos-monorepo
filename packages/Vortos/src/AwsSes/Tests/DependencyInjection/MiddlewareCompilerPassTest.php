<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\AwsSes\Attribute\AsEmailMiddleware;
use Vortos\AwsSes\Contract\EmailMiddlewareInterface;
use Vortos\AwsSes\Contract\MailerInterface;
use Vortos\AwsSes\DependencyInjection\Compiler\MiddlewareCompilerPass;
use Vortos\AwsSes\Middleware\EmailMiddlewareStack;
use Vortos\AwsSes\Middleware\HookMiddleware;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

final class MiddlewareCompilerPassTest extends TestCase
{
    private function buildContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        // Register a dummy driver
        $driver = new Definition(NullMailer::class);
        $container->setDefinition(NullMailer::class, $driver);
        $container->setAlias('vortos_aws_ses.driver', NullMailer::class);

        // Register EmailMiddlewareStack
        $stackDef = new Definition(EmailMiddlewareStack::class);
        $stackDef->setArguments([new Reference('vortos_aws_ses.driver'), []]);
        $container->setDefinition(EmailMiddlewareStack::class, $stackDef);

        // Register HookMiddleware
        $hookDef = new Definition(HookMiddleware::class);
        $hookDef->setArguments([[], new Reference(\Psr\Log\LoggerInterface::class)]);
        $container->setDefinition(HookMiddleware::class, $hookDef);
        $container->register(\Psr\Log\LoggerInterface::class, NullLogger::class);

        return $container;
    }

    public function test_no_op_when_stack_not_registered(): void
    {
        $container = new ContainerBuilder();
        $pass = new MiddlewareCompilerPass();

        // Should not throw
        $pass->process($container);
        $this->assertTrue(true);
    }

    public function test_tagged_middleware_injected_into_stack(): void
    {
        $container = $this->buildContainer();

        $mwDef = new Definition(PriorityMw::class);
        $mwDef->addTag('vortos_aws_ses.email_middleware', ['priority' => 100]);
        $container->setDefinition(PriorityMw::class, $mwDef);

        (new MiddlewareCompilerPass())->process($container);

        $stackArgs = $container->getDefinition(EmailMiddlewareStack::class)->getArguments();
        $middlewares = $stackArgs['$middlewares'];

        $this->assertCount(1, $middlewares);
        $this->assertInstanceOf(Reference::class, $middlewares[0]);
        $this->assertSame(PriorityMw::class, (string) $middlewares[0]);
    }

    public function test_middlewares_sorted_by_priority_descending(): void
    {
        $container = $this->buildContainer();

        $mwLow = new Definition(LowPriorityMw::class);
        $mwLow->addTag('vortos_aws_ses.email_middleware', ['priority' => 10]);
        $container->setDefinition(LowPriorityMw::class, $mwLow);

        $mwHigh = new Definition(HighPriorityMw::class);
        $mwHigh->addTag('vortos_aws_ses.email_middleware', ['priority' => 500]);
        $container->setDefinition(HighPriorityMw::class, $mwHigh);

        (new MiddlewareCompilerPass())->process($container);

        $args        = $container->getDefinition(EmailMiddlewareStack::class)->getArguments();
        $middlewares = $args['$middlewares'];

        $this->assertSame(HighPriorityMw::class, (string) $middlewares[0]);
        $this->assertSame(LowPriorityMw::class,  (string) $middlewares[1]);
    }

    public function test_priority_read_from_attribute_when_tag_has_none(): void
    {
        $container = $this->buildContainer();

        $mwDef = new Definition(AttrPriorityMw::class);
        $mwDef->addTag('vortos_aws_ses.email_middleware'); // no priority in tag
        $container->setDefinition(AttrPriorityMw::class, $mwDef);

        $mwLow = new Definition(LowPriorityMw::class);
        $mwLow->addTag('vortos_aws_ses.email_middleware', ['priority' => 5]);
        $container->setDefinition(LowPriorityMw::class, $mwLow);

        (new MiddlewareCompilerPass())->process($container);

        $args        = $container->getDefinition(EmailMiddlewareStack::class)->getArguments();
        $middlewares = $args['$middlewares'];

        // AttrPriorityMw has attribute priority 300, LowPriorityMw has 5
        $this->assertSame(AttrPriorityMw::class, (string) $middlewares[0]);
        $this->assertSame(LowPriorityMw::class,  (string) $middlewares[1]);
    }

    public function test_tag_priority_overrides_attribute(): void
    {
        $container = $this->buildContainer();

        $mwDef = new Definition(AttrPriorityMw::class); // attribute priority = 300
        $mwDef->addTag('vortos_aws_ses.email_middleware', ['priority' => 1]); // tag overrides to 1
        $container->setDefinition(AttrPriorityMw::class, $mwDef);

        $mwHigh = new Definition(HighPriorityMw::class);
        $mwHigh->addTag('vortos_aws_ses.email_middleware', ['priority' => 500]);
        $container->setDefinition(HighPriorityMw::class, $mwHigh);

        (new MiddlewareCompilerPass())->process($container);

        $args        = $container->getDefinition(EmailMiddlewareStack::class)->getArguments();
        $middlewares = $args['$middlewares'];

        $this->assertSame(HighPriorityMw::class, (string) $middlewares[0]);
        $this->assertSame(AttrPriorityMw::class,  (string) $middlewares[1]);
    }

    public function test_observers_injected_into_hook_middleware(): void
    {
        $container = $this->buildContainer();

        $obs = new Definition(DummyObserver::class);
        $obs->addTag('vortos_aws_ses.send_observer');
        $container->setDefinition(DummyObserver::class, $obs);

        (new MiddlewareCompilerPass())->process($container);

        $hookArgs  = $container->getDefinition(HookMiddleware::class)->getArguments();
        $observers = $hookArgs['$observers'];

        $this->assertCount(1, $observers);
        $this->assertSame(DummyObserver::class, (string) $observers[0]);
    }

    public function test_no_tagged_middleware_results_in_empty_stack(): void
    {
        $container = $this->buildContainer();

        (new MiddlewareCompilerPass())->process($container);

        $args        = $container->getDefinition(EmailMiddlewareStack::class)->getArguments();
        $middlewares = $args['$middlewares'];

        $this->assertSame([], $middlewares);
    }
}

// ---------------------------------------------------------------------------
// Stub classes for testing (defined at file scope to allow ReflectionClass)
// ---------------------------------------------------------------------------

final class NullMailer implements MailerInterface
{
    public function send(Email $email): SentEmail
    {
        return new SentEmail('null', new \DateTimeImmutable(), 1, 'null', null);
    }
}

final class PriorityMw implements EmailMiddlewareInterface
{
    public function process(Email $email, callable $next): SentEmail { return $next($email); }
}

#[AsEmailMiddleware(priority: 300)]
final class AttrPriorityMw implements EmailMiddlewareInterface
{
    public function process(Email $email, callable $next): SentEmail { return $next($email); }
}

final class LowPriorityMw implements EmailMiddlewareInterface
{
    public function process(Email $email, callable $next): SentEmail { return $next($email); }
}

final class HighPriorityMw implements EmailMiddlewareInterface
{
    public function process(Email $email, callable $next): SentEmail { return $next($email); }
}

final class DummyObserver implements \Vortos\AwsSes\Contract\EmailSendObserverInterface
{
    public function beforeSend(Email $email): void {}
    public function afterSend(Email $email, SentEmail $r): void {}
    public function onSendError(Email $email, \Throwable $e): void {}
}
