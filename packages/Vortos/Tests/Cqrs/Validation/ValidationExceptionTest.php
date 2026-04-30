<?php

declare(strict_types=1);

namespace Vortos\Tests\Cqrs\Validation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Vortos\Cqrs\Validation\ValidationException;
use Vortos\Http\Contract\PublicExceptionInterface;

final class ValidationExceptionTest extends TestCase
{
    private function make(array $pairs): ValidationException
    {
        $list = new ConstraintViolationList();
        foreach ($pairs as [$path, $msg]) {
            $list->add(new ConstraintViolation($msg, $msg, [], null, $path, null));
        }
        return new ValidationException($list);
    }

    public function test_http_status_code_is_422(): void
    {
        $this->assertSame(422, $this->make([['email', 'bad']])->getHttpStatusCode());
    }

    public function test_violation_map_keyed_by_path(): void
    {
        $map = $this->make([['email', 'Bad email']])->getViolationMap();
        $this->assertArrayHasKey('email', $map);
        $this->assertContains('Bad email', $map['email']);
    }

    public function test_multiple_messages_for_same_path(): void
    {
        $map = $this->make([['email', 'Msg1'], ['email', 'Msg2']])->getViolationMap();
        $this->assertCount(2, $map['email']);
    }

    public function test_nested_path_preserved(): void
    {
        $map = $this->make([['address.postcode', 'Too short']])->getViolationMap();
        $this->assertArrayHasKey('address.postcode', $map);
    }

    public function test_collection_path_preserved(): void
    {
        $map = $this->make([['items[2].quantity', 'Positive']])->getViolationMap();
        $this->assertArrayHasKey('items[2].quantity', $map);
    }

    public function test_to_response_array_shape(): void
    {
        $arr = $this->make([['name', 'Required']])->toResponseArray();
        $this->assertSame('validation_failed', $arr['error']);
        $this->assertArrayHasKey('message', $arr);
        $this->assertArrayHasKey('violations', $arr);
    }

    public function test_empty_path_maps_to_root(): void
    {
        $map = $this->make([['', 'Root error']])->getViolationMap();
        $this->assertArrayHasKey('_root', $map);
    }

    public function test_implements_public_exception_interface(): void
    {
        $this->assertInstanceOf(PublicExceptionInterface::class, $this->make([['x', 'y']]));
    }
}
