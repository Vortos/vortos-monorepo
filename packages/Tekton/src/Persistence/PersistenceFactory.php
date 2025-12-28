<?php

namespace Fortizan\Tekton\Persistence;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Fortizan\Tekton\Infrastructure\Doctrine\DomainEventDispatcher;
use Fortizan\Tekton\Persistence\Adapter\DoctrineSourceReader;
use Fortizan\Tekton\Persistence\Adapter\DoctrineSourceWriter;
use Fortizan\Tekton\Persistence\Adapter\MongoProjectionReader;
use Fortizan\Tekton\Persistence\Adapter\MongoProjectionWriter;
use Fortizan\Tekton\Persistence\Contract\PersistenceFactoryInterface;
use Fortizan\Tekton\Persistence\Contract\ProjectionReaderInterface;
use Fortizan\Tekton\Persistence\Contract\ProjectionWriterInterface;
use Fortizan\Tekton\Persistence\Contract\SourceReaderInterface;
use Fortizan\Tekton\Persistence\Contract\SourceWriterInterface;
use Fortizan\Tekton\Persistence\CustomType\Uuid;
use MongoDB\Client as MongoClient;
use MongoDB\Database as MongoDatabase;
use Redis;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Messenger\MessageBusInterface;

class PersistenceFactory implements PersistenceFactoryInterface
{
    private array $activeConnections = [];
    private string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/');
    }

    public function createSourceWriter(MessageBusInterface $bus, array $connectionParams = [], array $entityPaths = [], bool $isDevMode = false): SourceWriterInterface
    {
        [$defaultConnectionParams, $defaultEntityPaths] = $this->postgresqlDefaults();

        $connectionParams = array_merge($defaultConnectionParams, $connectionParams);
        $entityPaths = array_merge($defaultEntityPaths, $entityPaths);

        $cache = $this->createCache($isDevMode);

        $configuration = ORMSetup::createAttributeMetadataConfig(
            $entityPaths, 
            $isDevMode,
            null,
            $cache
        );

        $this->configureProxies($configuration, $isDevMode);

        $eventManager = new EventManager();

        $domainEventDispatcher = new DomainEventDispatcher($bus);

        $eventManager->addEventListener(
            [
                Events::postPersist,
                Events::postUpdate,
                Events::postRemove
            ],
            $domainEventDispatcher
        );

        $connection = $this->getSharedConnection($connectionParams);

        $entityManager = new EntityManager($connection, $configuration, $eventManager);

        return new DoctrineSourceWriter($entityManager);
    }

    private function createCache(bool $isDevMode):ArrayAdapter| RedisAdapter
    {
        if($isDevMode){
            return new ArrayAdapter();
        }

        $redisHost = $_ENV['REDIS_HOST'] ?? null;

        if($redisHost){

            try {
                $redis = new Redis();
                $redis->connect(
                    $redisHost,
                    $_ENV['REDIS_PORT']
                );

                if (!empty($_ENV['REDIS_PASSWORD'])) {
                    $redis->auth($_ENV['REDIS_PASSWORD']);
                }

                return new RedisAdapter($redis, 'doctrine_cache');
            } catch (\Exception $e) {
                error_log('Redis connection failed: ' . $e->getMessage());
                return new ArrayAdapter();
            }
        }
        
        return new ArrayAdapter();
    }

    private function configureProxies(Configuration $configuration, bool $isDevMode): void
    {
        $proxyDir = dirname(__DIR__, 2) . '/var/doctrine/proxies';
        if (!is_dir($proxyDir)) {
            @mkdir($proxyDir, 0775, true);
        }

        $configuration->setProxyDir($proxyDir);
        $configuration->setProxyNamespace('DoctrineProxies');
        $configuration->setAutoGenerateProxyClasses($isDevMode ? 1 : 0);
    }

    public function createSourceReader(array $connectionParams = [], bool $isDevMode = false): SourceReaderInterface
    {
        [$defaultConnectionParams,] = $this->postgresqlDefaults();

        $connectionParams = array_merge($defaultConnectionParams, $connectionParams);

        $connection = $this->getSharedConnection($connectionParams);

        return new DoctrineSourceReader($connection);
    }

    private function getSharedConnection(array $connectionParams):Connection
    {
        $key = md5(json_encode($connectionParams));

        if(!isset($this->activeConnections[$key])){
            $configuration = new DBALConfiguration();

            $this->registerCustomTypes();

            // You can add middlewares here (logging, profiling) using $config->setMiddlewares(...)

            $this->activeConnections[$key] = DriverManager::getConnection($connectionParams, $configuration);
        }

        return $this->activeConnections[$key];
    }

    private function registerCustomTypes():void
    {
        if(!Type::hasType(Uuid::class)){
            Type::addType(Uuid::TYPE_NAME, Uuid::class);
        }
    }

    private function postgresqlDefaults():array
    {
        $entityPaths = glob($this->projectRoot. "/src/*/Domain/Entity", GLOB_ONLYDIR);

        $entityPaths = array_filter($entityPaths, function ($path){
            return is_dir($path) && count(glob($path . '/*.php')) > 0;
        });
        if(empty($entityPaths)){
            throw new RuntimeException("You need to at least define one Entity class" . json_encode($entityPaths));
        }

        $connectionParams = [
            'host' => $_ENV['POSTGRES_HOST'],
            'user' => $_ENV['POSTGRES_USER'],
            'password' => $_ENV['POSTGRES_PASSWORD'],
            'dbname' => $_ENV['POSTGRES_DB_NAME'],
            'driver' => 'pdo_pgsql'
        ];

        return [$connectionParams, $entityPaths];
    }

    public function createProjectionWriter(array $config = []): ProjectionWriterInterface
    {
        $finalConfig = array_merge($this->mongoDefaults(), $config);

        $db = $this->createMongoConnection($finalConfig);
        return new MongoProjectionWriter($db);
    }

    public function createProjectionReader(array $config = []): ProjectionReaderInterface
    {
        $finalConfig = array_merge($this->mongoDefaults(), $config);
        
        $db = $this->createMongoConnection($finalConfig);
        return new MongoProjectionReader($db);
    }

    private function createMongoConnection(array $config = []): MongoDatabase
    {

        $uri = sprintf(
            "mongodb://%s:%s@%s:%s",
            $config['username'],
            $config['password'],
            $config['host'],
            $config['port']
        );

        $options = [
            'appname' => $_ENV['APP_NAME'] ?? 'Tekton_App',
            'connectTimeoutMS' => 1000,
        ];

        $client = new MongoClient($uri, $options);

        return $client->selectDatabase($config['db_name']);
    }

    private function mongoDefaults():array
    {
        return [
            'username' => $_ENV['MONGO_INITDB_ROOT_USERNAME'],
            'password' => $_ENV['MONGO_INITDB_ROOT_PASSWORD'],
            'host' => $_ENV['MONGO_HOST'],
            'port' => $_ENV['MONGO_PORT'],
            'db_name' => $_ENV['MONGO_DB_NAME']
        ];
    }
}
