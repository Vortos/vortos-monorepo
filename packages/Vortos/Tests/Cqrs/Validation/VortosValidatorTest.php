<?php

declare(strict_types=1);

namespace Vortos\Tests\Cqrs\Validation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints as Assert;
use Vortos\Cqrs\Validation\ValidationException;
use Vortos\Cqrs\Validation\VortosValidator;

final class VortosValidatorTest extends TestCase
{
    private VortosValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new VortosValidator();
    }

    public function test_valid_object_passes(): void
    {
        $obj = new class { #[Assert\Email] public string $email = 'valid@example.com'; };
        $this->assertCount(0, $this->validator->validate($obj));
    }

    public function test_invalid_email_produces_violation(): void
    {
        $obj = new class { #[Assert\Email] public string $email = 'not-an-email'; };
        $violations = $this->validator->validate($obj);
        $this->assertCount(1, $violations);
        $this->assertSame('email', $violations[0]->getPropertyPath());
    }

    public function test_multiple_violations_returned(): void
    {
        $obj = new class {
            #[Assert\NotBlank] public string $name = '';
            #[Assert\Email]    public string $email = 'bad';
        };
        $this->assertGreaterThanOrEqual(2, count($this->validator->validate($obj)));
    }

    public function test_validate_or_throw_throws_on_invalid(): void
    {
        $obj = new class { #[Assert\NotBlank] public string $name = ''; };
        $this->expectException(ValidationException::class);
        $this->validator->validateOrThrow($obj);
    }

    public function test_validate_or_throw_passes_on_valid(): void
    {
        $obj = new class { #[Assert\NotBlank] public string $name = 'Alice'; };
        $this->validator->validateOrThrow($obj);
        $this->assertTrue(true);
    }

    public function test_nested_valid_cascades(): void
    {
        $address = new class { #[Assert\Length(min: 10)] public string $postcode = 'AB'; };
        $obj     = new class($address) {
            public function __construct(#[Assert\Valid] public readonly object $address) {}
        };
        $violations  = $this->validator->validate($obj);
        $hasNested = false;
        foreach ($violations as $v) {
            if (str_contains($v->getPropertyPath(), 'address')) {
                $hasNested = true;
            }
        }
        $this->assertTrue($hasNested);
    }

    public function test_has_constraints_true_when_present(): void
    {
        $obj = new class { #[Assert\NotBlank] public string $name = 'x'; };
        $this->assertTrue($this->validator->hasConstraints($obj));
    }

    public function test_has_constraints_false_when_absent(): void
    {
        $obj = new class { public string $name = 'x'; };
        $this->assertFalse($this->validator->hasConstraints($obj));
    }

    public function test_validation_groups_work(): void
    {
        $obj = new class { #[Assert\NotBlank(groups: ['Create'])] public string $name = ''; };
        $this->assertCount(0, $this->validator->validate($obj, ['Default']));
        $this->assertCount(1, $this->validator->validate($obj, ['Create']));
    }
}
