<?php

declare(strict_types=1);

namespace Vortos\Tests\Http\EventListener;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Vortos\Cqrs\Validation\ValidationException;
use Vortos\Http\EventListener\ValidationExceptionHandler;
use Vortos\Http\Request;

final class ValidationExceptionListenerTest extends TestCase
{
    private function makeException(array $pairs = []): ValidationException
    {
        $list = new ConstraintViolationList();
        foreach ($pairs ?: [['field', 'Error']] as [$path, $msg]) {
            $list->add(new ConstraintViolation($msg, $msg, [], null, $path, null));
        }
        return new ValidationException($list);
    }

    private function makeRequest(): Request
    {
        return Request::create('/');
    }

    public function test_returns_422_for_validation_exception(): void
    {
        $handler = new ValidationExceptionHandler();
        $response = $handler->handle($this->makeException(), $this->makeRequest());
        $this->assertNotNull($response);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_response_body_has_correct_shape(): void
    {
        $handler = new ValidationExceptionHandler();
        $response = $handler->handle($this->makeException([['email', 'Bad email']]), $this->makeRequest());
        $body = json_decode($response->getContent(), true);
        $this->assertSame('validation_failed', $body['error']);
        $this->assertArrayHasKey('message', $body);
        $this->assertArrayHasKey('violations', $body);
    }

    public function test_non_validation_exception_returns_null(): void
    {
        $handler = new ValidationExceptionHandler();
        $response = $handler->handle(new RuntimeException('oops'), $this->makeRequest());
        $this->assertNull($response);
    }

    public function test_multiple_violations_all_in_response(): void
    {
        $handler = new ValidationExceptionHandler();
        $response = $handler->handle(
            $this->makeException([['email', 'Bad'], ['name', 'Short'], ['age', 'Positive']]),
            $this->makeRequest(),
        );
        $body = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('email', $body['violations']);
        $this->assertArrayHasKey('name', $body['violations']);
        $this->assertArrayHasKey('age', $body['violations']);
    }
}
