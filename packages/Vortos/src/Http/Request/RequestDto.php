<?php

declare(strict_types=1);

namespace Vortos\Http\Request;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Vortos\Cqrs\Validation\ValidationException;
use Vortos\Cqrs\Validation\VortosValidator;

/**
 * Base class for HTTP request DTOs.
 *
 * Hydration priority (highest → lowest):
 *   1. JSON body
 *   2. Form body
 *   3. Query string
 *   4. Route attributes (skipping _prefixed Symfony internals)
 *
 * Unknown keys are silently ignored — mass-assignment prevention.
 * Reflection runs once per request per DTO type — acceptable cost.
 */
abstract class RequestDto
{
    /**
     * Hydrate, coerce, and validate a DTO from the request.
     *
     * @throws ValidationException      on constraint violations or coercion errors
     * @throws InvalidArgumentException on malformed JSON body
     */
    final public static function fromRequest(Request $request, VortosValidator $validator): static
    {
        $instance = new static();
        $data     = $instance->extractData($request);
        $instance->hydrate($data);
        $validator->validateOrThrow($instance);

        return $instance;
    }

    /** @return array<string, mixed> */
    private function extractData(Request $request): array
    {
        $data = [];

        // Lowest priority: route attributes (skip _prefixed Symfony internals)
        foreach ($request->attributes->all() as $key => $value) {
            if (str_starts_with((string) $key, '_')) {
                continue;
            }
            $data[$key] = $value;
        }

        // Query string
        foreach ($request->query->all() as $key => $value) {
            $data[$key] = $value;
        }

        // Form body
        foreach ($request->request->all() as $key => $value) {
            $data[$key] = $value;
        }

        // Highest priority: JSON body
        $content = $request->getContent();
        if ($content !== '') {
            $decoded = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('Invalid JSON body: ' . json_last_error_msg());
            }

            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    $data[$key] = $value;
                }
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function hydrate(array $data): void
    {
        $reflection = new ReflectionClass(static::class);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            if (!array_key_exists($name, $data)) {
                continue;
            }

            $this->{$name} = $this->coerce($data[$name], $property->getType(), $name);
        }
    }

    private function coerce(mixed $value, ?ReflectionNamedType $type, string $prop): mixed
    {
        // No type declaration or union type — pass through raw
        if ($type === null) {
            return $value;
        }

        // Nullable: null input → null, non-null input → coerce to inner type
        if ($value === null) {
            return null;
        }

        return match ($type->getName()) {
            'string' => $this->coerceString($value, $prop),
            'int'    => $this->coerceInt($value, $prop),
            'float'  => $this->coerceFloat($value, $prop),
            'bool'   => $this->coerceBool($value, $prop),
            'array'  => $this->coerceArray($value, $prop),
            default  => $value,
        };
    }

    private function coerceString(mixed $value, string $prop): string
    {
        if (is_array($value) || is_object($value)) {
            throw new ValidationException($this->violation($prop, 'This value must be a string, array given.'));
        }

        $str = trim((string) $value);

        if (strlen($str) > 65535) {
            throw new ValidationException($this->violation($prop, 'This value is too long (max 65535 characters).'));
        }

        return $str;
    }

    private function coerceInt(mixed $value, string $prop): int
    {
        if (is_array($value) || is_object($value)) {
            throw new ValidationException($this->violation($prop, 'This value must be an integer.'));
        }

        if (is_string($value) && !is_numeric($value)) {
            throw new ValidationException($this->violation($prop, 'This value must be numeric to convert to integer.'));
        }

        return intval($value);
    }

    private function coerceFloat(mixed $value, string $prop): float
    {
        if (is_array($value) || is_object($value)) {
            throw new ValidationException($this->violation($prop, 'This value must be a float.'));
        }

        if (is_string($value) && !is_numeric($value)) {
            throw new ValidationException($this->violation($prop, 'This value must be numeric to convert to float.'));
        }

        return floatval($value);
    }

    private function coerceBool(mixed $value, string $prop): bool
    {
        if (in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($value, [false, 0, '0', 'false', 'no', 'off', ''], true)) {
            return false;
        }

        throw new ValidationException($this->violation($prop, 'This value must be a boolean.'));
    }

    private function coerceArray(mixed $value, string $prop): array
    {
        if (is_object($value)) {
            throw new ValidationException($this->violation($prop, 'This value must be an array, object given.'));
        }

        if (is_array($value)) {
            return $value;
        }

        // CSV split for query string scalars
        if (is_string($value) && str_contains($value, ',')) {
            return array_map('trim', explode(',', $value));
        }

        // Wrap single scalar
        return [$value];
    }

    private function violation(string $path, string $message): ConstraintViolationList
    {
        $list = new ConstraintViolationList();
        $list->add(new ConstraintViolation($message, $message, [], $this, $path, null));

        return $list;
    }
}
