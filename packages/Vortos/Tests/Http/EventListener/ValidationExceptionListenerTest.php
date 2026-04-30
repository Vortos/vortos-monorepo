<?php

declare(strict_types=1);

namespace Vortos\Tests\Http\EventListener;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Vortos\Cqrs\Validation\ValidationException;
use Vortos\Http\EventListener\ValidationExceptionListener;

final class ValidationExceptionListenerTest extends TestCase
{
    private function makeEvent(\Throwable $ex, int $type = HttpKernelInterface::MAIN_REQUEST): ExceptionEvent
    {
        return new ExceptionEvent($this->createMock(HttpKernelInterface::class), Request::create('/'), $type, $ex);
    }

    private function makeException(array $pairs = []): ValidationException
    {
        $list = new ConstraintViolationList();
        foreach ($pairs ?: [['field', 'Error']] as [$path, $msg]) {
            $list->add(new ConstraintViolation($msg, $msg, [], null, $path, null));
        }
        return new ValidationException($list);
    }

    public function test_returns_422_for_validation_exception(): void
    {
        $listener = new ValidationExceptionListener();
        $event    = $this->makeEvent($this->makeException());
        $listener->onKernelException($event);
        $this->assertSame(422, $event->getResponse()->getStatusCode());
    }

    public function test_response_body_has_correct_shape(): void
    {
        $listener = new ValidationExceptionListener();
        $event    = $this->makeEvent($this->makeException([['email', 'Bad email']]));
        $listener->onKernelException($event);
        $body = json_decode($event->getResponse()->getContent(), true);
        $this->assertSame('validation_failed', $body['error']);
        $this->assertArrayHasKey('message', $body);
        $this->assertArrayHasKey('violations', $body);
    }

    public function test_non_validation_exception_not_handled(): void
    {
        $listener = new ValidationExceptionListener();
        $event    = $this->makeEvent(new RuntimeException('oops'));
        $listener->onKernelException($event);
        $this->assertNull($event->getResponse());
    }

    public function test_sub_request_not_handled(): void
    {
        $listener = new ValidationExceptionListener();
        $event    = $this->makeEvent($this->makeException(), HttpKernelInterface::SUB_REQUEST);
        $listener->onKernelException($event);
        $this->assertNull($event->getResponse());
    }

    public function test_multiple_violations_all_in_response(): void
    {
        $listener = new ValidationExceptionListener();
        $event    = $this->makeEvent($this->makeException([['email', 'Bad'], ['name', 'Short'], ['age', 'Positive']]));
        $listener->onKernelException($event);
        $body = json_decode($event->getResponse()->getContent(), true);
        $this->assertArrayHasKey('email', $body['violations']);
        $this->assertArrayHasKey('name', $body['violations']);
        $this->assertArrayHasKey('age', $body['violations']);
    }

    public function test_subscribes_to_kernel_exception_at_priority_64(): void
    {
        $events = ValidationExceptionListener::getSubscribedEvents();
        $this->assertSame('onKernelException', $events[KernelEvents::EXCEPTION][0]);
        $this->assertSame(64, $events[KernelEvents::EXCEPTION][1]);
    }
}
