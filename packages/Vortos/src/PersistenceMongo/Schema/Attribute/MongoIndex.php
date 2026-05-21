<?php

declare(strict_types=1);

namespace Vortos\PersistenceMongo\Schema\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class MongoIndex
{
    /**
     * @param array<string, int|string> $key       Field → direction (1 asc, -1 desc, 'text')
     * @param bool                      $unique
     * @param bool                      $sparse
     * @param int|null                  $expireAfterSeconds TTL in seconds
     * @param string|null               $name      Optional explicit index name
     */
    public function __construct(
        public readonly array $key,
        public readonly bool $unique = false,
        public readonly bool $sparse = false,
        public readonly ?int $expireAfterSeconds = null,
        public readonly ?string $name = null,
    ) {}

    /** @return array<string, mixed> */
    public function toOptions(): array
    {
        $options = [];

        if ($this->unique) {
            $options['unique'] = true;
        }

        if ($this->sparse) {
            $options['sparse'] = true;
        }

        if ($this->expireAfterSeconds !== null) {
            $options['expireAfterSeconds'] = $this->expireAfterSeconds;
        }

        if ($this->name !== null) {
            $options['name'] = $this->name;
        }

        return $options;
    }
}
