<?php

declare(strict_types=1);

namespace Vortos\Messaging\Serializer;

use Vortos\Messaging\Contract\SerializerInterface;
use Vortos\Messaging\Serializer\Exception\DeserializationException;
use Vortos\Messaging\Serializer\Exception\SerializationException;
use JsonException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;
use Vortos\Domain\Event\DomainEventInterface;

final class JsonSerializer implements SerializerInterface
{
    private const MAX_DEPTH = 8;

    /** @var array<string, ReflectionClass> */
    private static array $reflectionCache = [];

    public function supports(string $format): bool
    {
        return $format === 'json';
    }

    public function serialize(DomainEventInterface $event): string
    {
        $properties = self::reflect(get_class($event))->getProperties(ReflectionProperty::IS_PUBLIC);
        $data = ['aggregateId' => $event->aggregateId()];

        foreach ($properties as $property) {
            $value = $property->getValue($event);
            $data[$property->getName()] = $value instanceof \Stringable ? (string) $value : $value;
        }

        $data['_class'] = get_class($event);

        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SerializationException(
                message: "Failed to serialize event of class '" . get_class($event) . "': " . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function deserialize(string $payload, string $eventClass, int $depth = 0): DomainEventInterface
    {
        if ($depth > self::MAX_DEPTH) {
            throw new DeserializationException(
                message: "Maximum deserialization depth (" . self::MAX_DEPTH . ") exceeded for class '{$eventClass}'."
            );
        }

        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            unset($data['_class']);

            $reflClass = self::reflect($eventClass);
            $constructor = $reflClass->getConstructor();

            if ($constructor === null) {
                return $reflClass->newInstance();
            }

            $params = $constructor->getParameters();
            $args = [];

            foreach ($params as $param) {
                $paramName = $param->getName();

                if (isset($data[$paramName])) {
                    $paramType = $param->getType();

                    if ($paramType instanceof ReflectionNamedType && !$paramType->isBuiltin()) {
                        $nestedClass = $paramType->getName();

                        if (is_array($data[$paramName])) {
                            $args[] = $this->deserialize(json_encode($data[$paramName]), $nestedClass, $depth + 1);
                        } elseif (method_exists($nestedClass, 'fromString')) {
                            $args[] = $nestedClass::fromString($data[$paramName]);
                        } elseif (method_exists($nestedClass, 'fromRfc4122')) {
                            $args[] = $nestedClass::fromRfc4122($data[$paramName]);
                        } else {
                            $args[] = new $nestedClass($data[$paramName]);
                        }
                    } else {
                        $args[] = $data[$paramName];
                    }
                } elseif ($param->isOptional()) {
                    $args[] = $param->getDefaultValue();
                } else {
                    throw DeserializationException::forPayload(
                        $payload,
                        $eventClass,
                        new RuntimeException("Missing required parameter '{$paramName}'")
                    );
                }
            }

            return $reflClass->newInstanceArgs($args);
        } catch (\Throwable $e) {
            throw DeserializationException::forPayload($payload, $eventClass, $e);
        }
    }

    private static function reflect(string $class): ReflectionClass
    {
        return self::$reflectionCache[$class] ??= new ReflectionClass($class);
    }
}
