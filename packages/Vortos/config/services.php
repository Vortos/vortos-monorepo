<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Vortos\Foundation\Module\ModulePathResolver;

return static function (ContainerConfigurator $configurator): void {
   
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Vortos\\', '../src/')
        ->exclude([
            '../src/Container/',
            '../src/Domain/',
            '../src/Http/Kernel.php',
            '../src/Auth/Provider/',
            '../src/*/DependencyInjection/',
            '../src/Messaging/Middleware/',
            '../src/Messaging/Registry/',
            '../src/Messaging/Runtime/',
            '../src/Messaging/Outbox/',
            '../src/Messaging/Serializer/',
            '../src/Messaging/Bus/',
            '../src/Messaging/Driver/',
            '../src/Messaging/DeadLetter/',
            '../src/Messaging/Hook/',
            '../src/Messaging/Retry/',
            '../src/Messaging/ValueObject/',
            '../src/Messaging/Definition/',
            '../src/Messaging/Contract/',
            '../src/Messaging/Attribute/',
            '../src/Auth/Jwt/',
            '../src/Auth/Storage/',
            '../src/Auth/Hasher/',
            '../src/Auth/Identity/',
            '../src/Auth/Middleware/',
        '../src/Messaging/Command/',
            // '../src/Auth/Controller/',
            // '../src/Auth/Contract/',
            '../src/Auth/Exception/',
            '../src/Auth/Attribute/',
            '../src/Authorization/Engine/',
            '../src/Authorization/Middleware/',
            '../src/Authorization/Voter/',
            '../src/Authorization/Contract/',
            '../src/Authorization/Exception/',
            '../src/Authorization/Attribute/',
            '../src/Cache/Adapter/',
            '../src/Cache/Contract/',
            '../src/Cache/Command/',
            '../src/Persistence/Write/',
            '../src/Persistence/Read/',
            '../src/Persistence/Transaction/',
            '../src/Persistence/Command/',
            '../src/PersistenceDbal/',
            '../src/PersistenceMongo/',
            '../src/Cqrs/Command/',
            '../src/Cqrs/Query/',
            '../src/Cqrs/Exception/',
            '../src/Cqrs/Projection/',
            '../src/Cqrs/Attribute/',
            '../src/Tracing/',
            '../src/Logger/',
            '../src/Http/',
            '../src/Foundation/',
            '../src/Routing/',
            '../src/Controller/',
            '../src/View/',
            '../src/Attribute/',
            '../src/Contract/',
        '../src/Cqrs/Validation/',
        '../src/Http/Request/',
        '../src/Http/EventListener/',
        '../src/Docker/',
        '../src/Migration/',
        '../src/Make/',
        '../src/Debug/',
        ]);

    $services->set(ModulePathResolver::class)
        ->arg('$projectDir', '%kernel.project_dir%');
};
