<?php

namespace App\User\Application\Query\GetUser;

use Fortizan\Tekton\Bus\Query\Attribute\QueryHandler;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use PDO;
use Redis;

#[QueryHandler] 
class GetUserQueryHandler
{

    public function __invoke(GetUserQuery $query): GetUserResponse
    {

        $redis = new Redis();
        $redis->connect($_ENV['REDIS_HOST'], 6379);
        $redis->set('key', "values");
        $cacheRedis = $redis->get('key');


        $mongoUrl = sprintf("mongodb://%s:%s@%s:27017", 
            $_ENV['MONGO_INITDB_ROOT_USERNAME'], 
            $_ENV['MONGO_INITDB_ROOT_PASSWORD'], 
            $_ENV['MONGO_HOST']
        );

        $manager = new Manager($mongoUrl);
        $command = new Command(['ping'=> 1]);
        $cursor = $manager->executeCommand('admin', $command);
        $mongoStatus = $cursor->toArray()[0]->ok;



    $dsn = sprintf("pgsql:host=%s; dbname=%s", $_ENV['POSTGRES_HOST'], $_ENV['POSTGRES_DB']);
        $pdo = new PDO($dsn, $_ENV['POSTGRES_USER'], $_ENV['POSTGRES_PASSWORD']);
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (id int primary key, email varchar(255) , name varchar(255) )");
        // $pdo->exec("INSERT INTO users values ('1', 'abc@gmail.com', 'jhon')");
        $result = $pdo->query("SELECT * FROM users WHERE id = 1");
        $user = $result->fetch(PDO::FETCH_ASSOC);
        

        return new GetUserResponse(
            userId: $user['id'],
            userEmail: $user['email'] . " | " . $cacheRedis ." | " . $mongoStatus,
            userName: $user["name"]
        );
    }
}
