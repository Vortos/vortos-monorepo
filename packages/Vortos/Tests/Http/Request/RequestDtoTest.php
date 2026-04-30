<?php

declare(strict_types=1);

namespace Vortos\Tests\Http\Request;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;
use Vortos\Cqrs\Validation\ValidationException;
use Vortos\Cqrs\Validation\VortosValidator;
use Vortos\Http\Request\RequestDto;

final class RegisterUserRequestFixture extends RequestDto
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public string $email = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    public string $name = '';

    #[Assert\Positive]
    public int $age = 1;

    public ?string $bio = null;

    public bool $newsletter = false;

    public array $tags = [];
}

final class RequestDtoTest extends TestCase
{
    private VortosValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new VortosValidator();
    }

    private function jsonRequest(array $data, array $query = []): Request
    {
        return Request::create('/test', 'POST', $query, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($data));
    }

    public function test_hydrates_from_json_body(): void
    {
        $dto = RegisterUserRequestFixture::fromRequest(
            $this->jsonRequest(['email' => 'alice@example.com', 'name' => 'Alice', 'age' => 30]),
            $this->validator,
        );
        $this->assertSame('alice@example.com', $dto->email);
        $this->assertSame('Alice', $dto->name);
        $this->assertSame(30, $dto->age);
    }

    public function test_trims_string_inputs(): void
    {
        $dto = RegisterUserRequestFixture::fromRequest(
            $this->jsonRequest(['email' => '  alice@example.com  ', 'name' => '  Alice  ', 'age' => 30]),
            $this->validator,
        );
        $this->assertSame('alice@example.com', $dto->email);
        $this->assertSame('Alice', $dto->name);
    }

    public function test_unknown_keys_ignored(): void
    {
        $dto = RegisterUserRequestFixture::fromRequest(
            $this->jsonRequest(['email' => 'alice@example.com', 'name' => 'Alice', 'age' => 30, 'is_admin' => true, '__proto__' => 'evil']),
            $this->validator,
        );
        $this->assertFalse(isset($dto->is_admin));
    }

    public function test_validation_failure_throws(): void
    {
        $this->expectException(ValidationException::class);
        RegisterUserRequestFixture::fromRequest($this->jsonRequest(['email' => 'bad', 'name' => 'Alice', 'age' => 30]), $this->validator);
    }

    public function test_violation_map_contains_field_paths(): void
    {
        try {
            RegisterUserRequestFixture::fromRequest($this->jsonRequest(['email' => 'bad', 'name' => 'A', 'age' => 30]), $this->validator);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->getViolationMap());
        }
    }

    public function test_nullable_field_absent_is_null(): void
    {
        $dto = RegisterUserRequestFixture::fromRequest(
            $this->jsonRequest(['email' => 'alice@example.com', 'name' => 'Alice', 'age' => 30]),
            $this->validator,
        );
        $this->assertNull($dto->bio);
    }

    public function test_bool_coercion_string_true(): void
    {
        $dto = RegisterUserRequestFixture::fromRequest(
            $this->jsonRequest(['email' => 'a@b.com', 'name' => 'Alice', 'age' => 30, 'newsletter' => 'true']),
            $this->validator,
        );
        $this->assertTrue($dto->newsletter);
    }

    public function test_bool_coercion_string_false(): void
    {
        $dto = RegisterUserRequestFixture::fromRequest(
            $this->jsonRequest(['email' => 'a@b.com', 'name' => 'Alice', 'age' => 30, 'newsletter' => 'false']),
            $this->validator,
        );
        $this->assertFalse($dto->newsletter);
    }

    public function test_bool_coercion_int_1(): void
    {
        $dto = RegisterUserRequestFixture::fromRequest(
            $this->jsonRequest(['email' => 'a@b.com', 'name' => 'Alice', 'age' => 30, 'newsletter' => 1]),
            $this->validator,
        );
        $this->assertTrue($dto->newsletter);
    }

    public function test_int_coercion_from_string(): void
    {
        $dto = RegisterUserRequestFixture::fromRequest(
            $this->jsonRequest(['email' => 'a@b.com', 'name' => 'Alice', 'age' => '30']),
            $this->validator,
        );
        $this->assertSame(30, $dto->age);
    }

    public function test_array_coercion_from_json_array(): void
    {
        $dto = RegisterUserRequestFixture::fromRequest(
            $this->jsonRequest(['email' => 'a@b.com', 'name' => 'Alice', 'age' => 30, 'tags' => ['php', 'ddd']]),
            $this->validator,
        );
        $this->assertSame(['php', 'ddd'], $dto->tags);
    }

    public function test_array_coercion_from_csv_query_string(): void
    {
        $request = Request::create('/test', 'GET', ['email' => 'a@b.com', 'name' => 'Alice', 'age' => '30', 'tags' => 'php,ddd,cqrs']);
        $dto = RegisterUserRequestFixture::fromRequest($request, $this->validator);
        $this->assertSame(['php', 'ddd', 'cqrs'], $dto->tags);
    }

    public function test_invalid_json_throws(): void
    {
        $request = Request::create('/test', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{invalid');
        $this->expectException(InvalidArgumentException::class);
        RegisterUserRequestFixture::fromRequest($request, $this->validator);
    }

    public function test_array_as_string_throws(): void
    {
        $this->expectException(ValidationException::class);
        RegisterUserRequestFixture::fromRequest(
            $this->jsonRequest(['email' => ['not', 'a', 'string'], 'name' => 'Alice', 'age' => 30]),
            $this->validator,
        );
    }

    public function test_non_numeric_string_as_int_throws(): void
    {
        $this->expectException(ValidationException::class);
        RegisterUserRequestFixture::fromRequest(
            $this->jsonRequest(['email' => 'a@b.com', 'name' => 'Alice', 'age' => 'nope']),
            $this->validator,
        );
    }

    public function test_string_over_65535_throws(): void
    {
        $this->expectException(ValidationException::class);
        RegisterUserRequestFixture::fromRequest(
            $this->jsonRequest(['email' => 'a@b.com', 'name' => str_repeat('a', 65536), 'age' => 30]),
            $this->validator,
        );
    }

    public function test_json_body_overrides_query_string(): void
    {
        $request = Request::create('/test?name=QueryAlice', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'a@b.com', 'name' => 'BodyAlice', 'age' => 30]));
        $dto = RegisterUserRequestFixture::fromRequest($request, $this->validator);
        $this->assertSame('BodyAlice', $dto->name);
    }

    public function test_query_string_used_when_no_body(): void
    {
        $request = Request::create('/test', 'GET', ['email' => 'a@b.com', 'name' => 'Alice', 'age' => '30']);
        $dto = RegisterUserRequestFixture::fromRequest($request, $this->validator);
        $this->assertSame('a@b.com', $dto->email);
    }
}
