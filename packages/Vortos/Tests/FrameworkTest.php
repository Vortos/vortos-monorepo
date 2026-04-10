<?php

namespace Vortos\Tests;

use App\Context\Representation\Controller\LeapYearController;
use Exception;
use Vortos\Http\Kernal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\Routing;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;


class FrameworkTest extends TestCase
{
    public function testNotFoundHandling(): void
    {
        $framework = $this->getFrameworkForException(new ResourceNotFoundException());

        $response = $framework->handle(new Request());

        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testInternalErrorHandling(): void
    {
        $framework = $this->getFrameworkForException(new Exception());

        $response = $framework->handle(new Request());

        $this->assertEquals(500, $response->getStatusCode());
    }

    private function getFrameworkForException($exception): Kernal
    {
        $matcher = $this->createMock(Routing\Matcher\UrlMatcherInterface::class);

        $matcher
            ->expects($this->once())
            ->method('match')
            ->willThrowException($exception)
        ;
        $matcher
            ->expects($this->once())
            ->method('getContext')
            ->willReturn($this->createMock(Routing\RequestContext::class))
        ;
        $controllerResolver = $this->createMock(ControllerResolverInterface::class);
        $argumentResolver = $this->createMock(ArgumentResolverInterface::class);

        return new Kernal($matcher, $controllerResolver, $argumentResolver);
    }

    public function testControllerResponse(): void
    {
        $matcher = $this->createMock(Routing\Matcher\UrlMatcherInterface::class);

        $matcher
            ->expects($this->once())
            ->method('match')
            ->willReturn([
                '_route' => 'leap_year/{year}',
                'year' => '2000',
                '_controller' => [new LeapYearController(), 'index'],
            ])
        ;
        $matcher
            ->expects($this->once())
            ->method('getContext')
            ->willReturn($this->createMock(Routing\RequestContext::class))
        ;
        $controllerResolver = new ControllerResolver();
        $argumentResolver = new ArgumentResolver();

        $framework = new Kernal($matcher, $controllerResolver, $argumentResolver);

        $response = $framework->handle(new Request());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('yes, its a leap year', $response->getContent());
    }
}