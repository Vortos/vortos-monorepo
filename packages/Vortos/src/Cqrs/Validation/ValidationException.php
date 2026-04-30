<?php

declare(strict_types=1);

namespace Vortos\Cqrs\Validation;

use DomainException;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Vortos\Http\Contract\PublicExceptionInterface;

/**
 * Thrown when an object fails validation.
 *
 * Extends DomainException — validation failures are expected domain
 * conditions, not unexpected runtime errors.
 *
 * Implements PublicExceptionInterface so ErrorController surfaces
 * the message in production instead of a generic 500.
 *
 * HTTP status: 422 Unprocessable Entity.
 */
final class ValidationException extends DomainException implements PublicExceptionInterface
{
    public function __construct(private readonly ConstraintViolationListInterface $violations)
    {
        parent::__construct('Validation failed.');
    }

    public function getHttpStatusCode(): int
    {
        return 422;
    }

    public function getViolations(): ConstraintViolationListInterface
    {
        return $this->violations;
    }

    /**
     * Returns violations keyed by property path.
     *
     * - Nested:     "address.postcode"
     * - Collection: "items[2].quantity"
     * - Root:       "_root"
     *
     * @return array<string, string[]>
     */
    public function getViolationMap(): array
    {
        $map = [];

        foreach ($this->violations as $violation) {
            $path = $violation->getPropertyPath();

            if ($path === '' || $path === null) {
                $path = '_root';
            }

            $map[$path][] = $violation->getMessage();
        }

        return $map;
    }

    /**
     * @return array{error: string, message: string, violations: array<string, string[]>}
     */
    public function toResponseArray(): array
    {
        return [
            'error'      => 'validation_failed',
            'message'    => 'The given data was invalid.',
            'violations' => $this->getViolationMap(),
        ];
    }
}
