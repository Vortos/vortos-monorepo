<?php

declare(strict_types=1);

namespace Vortos\PersistenceMongo\Connection;

use MongoDB\Client;

/**
 * Builds MongoDB\Client instances from DSN strings.
 *
 * This is a pure static factory — it cannot be instantiated.
 * It has one responsibility: construct a configured MongoDB Client.
 *
 * ## Important: MongoDB\Client is NOT lazy
 *
 * Unlike Doctrine DBAL, MongoDB\Client establishes a connection
 * immediately on construction. If the MongoDB server is unreachable,
 * construction will fail. Plan your container boot accordingly —
 * if MongoDB is down, the container will fail to compile in production.
 *
 * ## Standard usage
 *
 * Set a single MONGODB_URL environment variable:
 *
 *   MONGODB_URL=mongodb://root:secret@read_db:27017
 *
 * Then in config/persistence.php:
 *
 *   $config->readDsn($_ENV['MONGODB_URL']);
 *   $config->readDatabase($_ENV['MONGO_DB_NAME']);
 *
 * ## DSN format
 *
 *   mongodb://[user:pass@]host[:port][/database][?options]
 *
 * Replica sets, TLS, and auth mechanisms can be expressed via query params:
 *
 *   mongodb://user:pass@host1:27017,host2:27017/?replicaSet=rs0&tls=true
 *
 * ## Store _id as string UUID
 *
 * Always store document _id fields as string UUIDs, never as MongoDB ObjectId.
 * Your aggregates use UuidV7 — keep the type consistent across write and read sides.
 * Mixing ObjectId and string UUIDs causes silent type mismatch bugs when querying by ID.
 */
final class MongoClientFactory
{
    /**
     * Prevents instantiation — this class is a static factory only.
     */
    private function __construct() {}

    /**
     * Build a MongoDB\Client from a DSN string.
     *
     * @param string $dsn     Full MongoDB DSN string
     * @param array  $options URI options passed to MongoDB\Client constructor.
     *                        Use for options not expressible in the DSN string,
     *                        such as custom SSL context or auth mechanism details.
     *                        See: https://www.mongodb.com/docs/php-library/current/reference/class/MongoDBClient/
     *
     * @throws \MongoDB\Driver\Exception\InvalidArgumentException If the DSN is malformed
     * @throws \MongoDB\Driver\Exception\RuntimeException         If the connection fails
     */
    public static function fromDsn(string $dsn, array $options = []): Client
    {
        return new Client($dsn, $options);
    }
}
