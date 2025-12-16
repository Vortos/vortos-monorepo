<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Fortizan\Tekton\Controller\ErrorController;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\Dotenv\Dotenv;    
use Symfony\Component\ErrorHandler\Debug;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouteCollection;

$dotenv = new Dotenv();
$dotenv->load(__DIR__. '/../.env');

$env = $_ENV['APP_ENV'] ?? 'prod';
$debug = false; 

$request = null;

try{

    $debugBool = filter_var($_ENV['APP_DEBUG'], FILTER_VALIDATE_BOOL);

    if ($env === 'dev' && $debugBool) {
        Debug::enable();
        $debug = true;
    }

    $request = Request::createFromGlobals();

    function render_template(Request $request): Response
    {
        extract($request->attributes->all(), EXTR_SKIP);
        ob_start();
        include sprintf(__DIR__ . "/../src/pages/%s.php", $_route);
        return new Response(ob_get_clean());
    }

    $dumpFile = __DIR__ . "/container_dump.php";

    if (false) {
        require_once $dumpFile;
        $container = new CachedContainer();
    } else {
        $container = include __DIR__ . "/../packages/Tekton/src/Container/Container.php";

        $container->setParameter('kernel.env', $env);
        $container->setParameter('kernel.debug', $debug);

        $container->compile();

        $dumper = new PhpDumper($container);
        file_put_contents(__DIR__ . "/container_dump.php", $dumper->dump(['class' => 'CachedContainer']));
    }

    $routeLoader = include __DIR__ . "/../packages/Tekton/config/routes.php";
    $routes = $routeLoader($container);
    $container->set(RouteCollection::class, $routes);

    $tekton = $container->get('tekton');

    $response = $tekton->handle(request: $request);

    $response->send();

}catch(\Throwable $e){

    $logger = null;
    if(isset($container)){
        try {
            if($container->get(LoggerInterface::class)){
                $logger = $container->get(LoggerInterface::class);
            }
        } catch (\Throwable $th) {
            
        }
    }

    if(!$request){
        $request = Request::createFromGlobals();
    }

    $errorController = new ErrorController($debug, $logger);
    $errorController->__invoke($e, $request)->send();
}
