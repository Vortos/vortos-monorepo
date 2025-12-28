<?php

namespace Fortizan\Tekton\Persistence\CustomType;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;
use Symfony\Component\Uid\UuidV7;

class Uuid extends Type
{
    public const TYPE_NAME = 'uuid';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'UUID';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if(!$value){
            return null;
        }

        if(!$value instanceof UuidV7){
            throw new InvalidArgumentException("Provided value for type {$this->getName()} is invalid");
        }

        return $value->toRfc4122();
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if ($value === null || $value instanceof UuidV7) {
            return $value;
        }

        try {
            
            return UuidV7::fromRfc4122($value);
        } catch (\Exception $e) {
            throw new InvalidArgumentException("Invalid UUID string :" . $value);
        }
    }

    public function getName():string
    {
        return self::TYPE_NAME;
    }
}