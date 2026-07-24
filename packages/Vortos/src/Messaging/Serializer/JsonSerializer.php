<?php

declare(strict_types=1);

namespace Vortos\Messaging\Serializer;

use JsonException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;
use Vortos\Messaging\Contract\SerializerInterface;
use Vortos\Messaging\Serializer\Exception\DeserializationException;
use Vortos\Messaging\Serializer\Exception\SerializationException;

/**
 * JSON serializer for pure event payloads.
 *
 * Serializes only the public properties of the payload object. Stringable
 * value objects (UUIDs, typed IDs, money) are cast to string for the wire
 * format and reconstructed on deserialize via fromString()/fromRfc4122() or
 * direct constructor injection. Framework metadata is NEVER added to the
 * payload — that lives on the envelope/headers.
 */
final class JsonSerializer implements SerializerInterface
{
    private const MAX_DEPTH = 8;

    /** @var array<class-string, ReflectionClass> */
    private static array $reflectionCache = [];

    public function supports(string $format): bool
    {
        return $format === 'json';
    }

    public function serialize(object $payload): string
    {
        $properties = self::reflect($payload::class)->getProperties(ReflectionProperty::IS_PUBLIC);
        $data = [];

        foreach ($properties as $property) {
            $value = $property->getValue($payload);
            $data[$property->getName()] = $value instanceof \Stringable ? (string) $value : $value;
        }

        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SerializationException(
                message: "Failed to serialize payload of class '" . $payload::class . "': " . $e->getMessage(),
                previous: $e,
            );
        }
    }

    public function deserialize(string $payload, string $payloadClass, int $depth = 0): object
    {
        if ($depth > self::MAX_DEPTH) {
            throw new DeserializationException(
                message: "Maximum deserialization depth (" . self::MAX_DEPTH . ") exceeded for class '{$payloadClass}'.",
            );
        }

        if (!class_exists($payloadClass)) {
            throw DeserializationException::forPayload(
                $payload,
                $payloadClass,
                new RuntimeException("Payload class '{$payloadClass}' does not exist."),
            );
        }

        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            // Legacy field — older serialized payloads stored `_class`. Strip if present.
            unset($data['_class']);

            $reflClass = self::reflect($payloadClass);
            $constructor = $reflClass->getConstructor();

            if ($constructor === null) {
                return $reflClass->newInstance();
            }

            $params = $constructor->getParameters();
            $args = [];

            foreach ($params as $param) {
                $paramName = $param->getName();

                // array_key_exists (not isset): a present key with a null value is a
                // supplied argument, not a missing one. isset() treated `"x":null` as
                // absent, so a required-but-nullable param (`?string $x` with no default)
                // wrongly threw "Missing required parameter" and the whole event failed
                // to deserialize — silently dropping every consumer downstream of it.
                if (array_key_exists($paramName, $data)) {
                    $value     = $data[$paramName];
                    $paramType = $param->getType();

                    if ($value !== null && $paramType instanceof ReflectionNamedType && !$paramType->isBuiltin()) {
                        $nestedClass = $paramType->getName();

                        if (is_array($value)) {
                            $args[] = $this->deserialize(json_encode($value), $nestedClass, $depth + 1);
                        } elseif (method_exists($nestedClass, 'fromString')) {
                            $args[] = $nestedClass::fromString($value);
                        } elseif (method_exists($nestedClass, 'fromRfc4122')) {
                            $args[] = $nestedClass::fromRfc4122($value);
                        } else {
                            $args[] = new $nestedClass($value);
                        }
                    } else {
                        $args[] = $value;
                    }
                } elseif ($param->isOptional()) {
                    $args[] = $param->getDefaultValue();
                } else {
                    throw DeserializationException::forPayload(
                        $payload,
                        $payloadClass,
                        new RuntimeException("Missing required parameter '{$paramName}'"),
                    );
                }
            }

            return $reflClass->newInstanceArgs($args);
        } catch (\Throwable $e) {
            throw DeserializationException::forPayload($payload, $payloadClass, $e);
        }
    }

    private static function reflect(string $class): ReflectionClass
    {
        return self::$reflectionCache[$class] ??= new ReflectionClass($class);
    }
}
