<?php

declare(strict_types=1);

namespace Vortos\Http\Request;

use Attribute;
use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionUnionType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Vortos\Cqrs\Validation\ValidationException;
use Vortos\Cqrs\Validation\VortosValidator;

/**
 * Marks a public array property as a typed collection of a specific class.
 *
 * Usage:
 *   #[CastArray(OrderLineDto::class)]
 *   public array $lines = [];
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class CastArray
{
    public function __construct(public readonly string $type) {}
}

/**
 * Base class for HTTP request DTOs.
 *
 * ## Hydration priority (highest → lowest)
 *   1. JSON body          (strict Content-Type: application/json check)
 *   2. Form body
 *   3. Query string
 *   4. Route attributes   (skips _prefixed Symfony internals)
 *
 * ## Hydration strategy
 *   - Constructor params first (supports readonly promoted properties)
 *   - Then remaining public properties
 *   - Unknown keys silently ignored — mass-assignment prevention
 *   - snake_case keys auto-aliased to camelCase properties
 *   - Missing nullable = null, missing non-nullable = left at default
 *
 * ## Type coercion
 *   - Scalars: string (trim + 65535 guard), int, float, bool
 *   - Arrays: pass-through, CSV split, scalar wrap
 *   - Nested RequestDto: recursive hydration
 *   - BackedEnum: tryFrom() with hard error on invalid value
 *   - DateTimeImmutable / DateTimeInterface: ISO 8601 string parsing
 *   - Typed arrays: #[CastArray(SomeDto::class)]
 *   - Union types: null-safe, coerces first non-null named type
 *
 * ## Reflection cost
 *   Runs once per request per DTO type — acceptable.
 *   No caching needed at this layer; container handles request lifecycle.
 */
abstract class RequestDto
{
    /**
     * Entry point. Hydrates, coerces, and validates a DTO from the request.
     *
     * @throws ValidationException      on constraint violations or coercion errors
     * @throws InvalidArgumentException on malformed JSON body
     */
    final public static function fromRequest(Request $request, VortosValidator $validator): static
    {
        $data     = static::extractData($request);
        $instance = static::instantiate($data);
        $validator->validateOrThrow($instance);

        return $instance;
    }

    /**
     * Instantiate and hydrate from a raw data array.
     * Useful for nested DTO hydration and testing.
     *
     * Not public API — internal use and subclass recursion only.
     */
    protected static function instantiate(array $data): static
    {
        $reflection  = new ReflectionClass(static::class);
        $constructor = $reflection->getConstructor();
        $handled     = [];

        // Phase 1: constructor params (handles readonly promoted properties)
        $args      = [];
        $missing   = new ConstraintViolationList();
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $param) {
                $name  = $param->getName();
                $found = static::findValue($name, $data);

                if ($found !== null) {
                    [$value] = $found;
                    $args[$name] = static::coerceParam($value, $param, $name);
                } elseif ($param->isDefaultValueAvailable()) {
                    $args[$name] = $param->getDefaultValue();
                } elseif ($param->allowsNull()) {
                    $args[$name] = null;
                } else {
                    $missing->add(new ConstraintViolation('This field is required.', 'This field is required.', [], null, $name, null));
                }

                $handled[] = $name;
            }
        }

        if (count($missing) > 0) {
            throw new ValidationException($missing);
        }

        $instance = $reflection->newInstanceArgs($args);

        // Phase 2: remaining public non-readonly properties
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            if (in_array($name, $handled, true) || $property->isReadOnly()) {
                continue;
            }

            $found = static::findValue($name, $data);

            if ($found === null) {
                // Key absent — leave at declared default
                continue;
            }

            [$value] = $found;
            $type    = $property->getType();

            $instance->{$name} = static::coerceByType($value, $type, $property, $name);
        }

        return $instance;
    }

    /**
     * Merge all request sources into a flat map.
     * Later sources overwrite earlier ones (priority order).
     *
     * @return array<string, mixed>
     */
    protected static function extractData(Request $request): array
    {
        $data = [];

        // Lowest: route attributes (skip _prefixed Symfony internals)
        foreach ($request->attributes->all() as $key => $value) {
            if (!str_starts_with((string) $key, '_')) {
                $data[(string) $key] = $value;
            }
        }

        foreach ($request->query->all() as $key => $value) {
            $data[$key] = $value;
        }

        foreach ($request->request->all() as $key => $value) {
            $data[$key] = $value;
        }

        // Highest: JSON body — strict Content-Type check
        if (str_contains($request->headers->get('Content-Type', ''), 'application/json')) {
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
        }

        return $data;
    }

    /**
     * Find a value by property name, with automatic snake_case aliasing.
     *
     * Returns [mixed $value] tuple when found, null when absent.
     * Uses array_key_exists — correctly distinguishes absent from explicit null.
     *
     * @return array{0: mixed}|null
     */
    private static function findValue(string $name, array $data): ?array
    {
        if (array_key_exists($name, $data)) {
            return [$data[$name]];
        }

        // camelCase → snake_case alias (e.g. firstName → first_name)
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
        if ($snake !== $name && array_key_exists($snake, $data)) {
            return [$data[$snake]];
        }

        return null;
    }

    /**
     * Coerce a value using a ReflectionParameter (constructor param).
     */
    private static function coerceParam(mixed $value, ReflectionParameter $param, string $path): mixed
    {
        return static::coerceByType($value, $param->getType(), $param, $path);
    }

    /**
     * Core coercion dispatcher. Handles union types, nullables, and all supported types.
     */
    private static function coerceByType(
        mixed $value,
        \ReflectionType|null $type,
        ReflectionProperty|ReflectionParameter $reflector,
        string $path,
    ): mixed {
        if ($type === null) {
            return $value;
        }

        // Union types — find first non-null named type
        if ($type instanceof ReflectionUnionType) {
            if ($value === null && $type->allowsNull()) {
                return null;
            }
            foreach ($type->getTypes() as $t) {
                if ($t instanceof ReflectionNamedType && $t->getName() !== 'null') {
                    $type = $t;
                    break;
                }
            }
        }

        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        if ($value === null) {
            return $type->allowsNull() ? null : $value;
        }

        $typeName = $type->getName();

        // Nested RequestDto — recursive
        if (is_subclass_of($typeName, self::class)) {
            if (!is_array($value)) {
                throw static::makeViolation($path, 'Expected an object for nested DTO.');
            }
            return $typeName::instantiate($value);
        }

        // BackedEnum
        if (is_subclass_of($typeName, BackedEnum::class)) {
            $enum = $typeName::tryFrom($value);
            if ($enum === null) {
                throw static::makeViolation($path, sprintf('Invalid value "%s" for enum %s.', $value, $typeName));
            }
            return $enum;
        }

        // DateTimeImmutable / DateTimeInterface
        if ($typeName === DateTimeImmutable::class || is_a($typeName, DateTimeInterface::class, true)) {
            if (!is_string($value)) {
                throw static::makeViolation($path, 'Expected an ISO 8601 date string.');
            }
            $date = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value);
            if ($date === false) {
                $date = new DateTimeImmutable($value);
            }
            return $date;
        }

        // Typed array via #[CastArray]
        if ($typeName === 'array') {
            $castAttrs = $reflector->getAttributes(CastArray::class);
            if (!empty($castAttrs)) {
                if (!is_array($value)) {
                    throw static::makeViolation($path, 'Expected an array.');
                }
                $innerType = $castAttrs[0]->newInstance()->type;
                if (is_subclass_of($innerType, self::class)) {
                    return array_map(
                        static fn(mixed $item) => $innerType::instantiate(is_array($item) ? $item : []),
                        $value,
                    );
                }
                return $value;
            }
            return static::coerceArray($value, $path);
        }

        return match ($typeName) {
            'string' => static::coerceString($value, $path),
            'int'    => static::coerceInt($value, $path),
            'float'  => static::coerceFloat($value, $path),
            'bool'   => static::coerceBool($value, $path),
            default  => $value,
        };
    }

    private static function coerceString(mixed $value, string $prop): string
    {
        if (is_array($value) || is_object($value)) {
            throw static::makeViolation($prop, 'This value must be a string, array given.');
        }
        $str = trim((string) $value);
        if (strlen($str) > 65535) {
            throw static::makeViolation($prop, 'This value is too long (max 65535 characters).');
        }
        return $str;
    }

    private static function coerceInt(mixed $value, string $prop): int
    {
        if (!is_scalar($value) || is_bool($value)) {
            throw static::makeViolation($prop, 'This value must be an integer.');
        }
        if (is_string($value) && !is_numeric($value)) {
            throw static::makeViolation($prop, 'This value must be numeric to convert to integer.');
        }
        return intval($value);
    }

    private static function coerceFloat(mixed $value, string $prop): float
    {
        if (!is_scalar($value) || is_bool($value)) {
            throw static::makeViolation($prop, 'This value must be a float.');
        }
        if (is_string($value) && !is_numeric($value)) {
            throw static::makeViolation($prop, 'This value must be numeric to convert to float.');
        }
        return floatval($value);
    }

    private static function coerceBool(mixed $value, string $prop): bool
    {
        if (in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, [false, 0, '0', 'false', 'no', 'off', ''], true)) {
            return false;
        }
        throw static::makeViolation($prop, 'This value must be a boolean.');
    }

    private static function coerceArray(mixed $value, string $prop): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && str_contains($value, ',')) {
            return array_map('trim', explode(',', $value));
        }
        if (is_scalar($value)) {
            return [$value];
        }
        throw static::makeViolation($prop, 'This value must be an array.');
    }

    private static function makeViolation(string $path, string $message): ValidationException
    {
        $list = new ConstraintViolationList();
        $list->add(new ConstraintViolation($message, $message, [], null, $path, null));
        return new ValidationException($list);
    }
}
