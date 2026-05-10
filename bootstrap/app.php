<?php

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\ErrorHandler\Debug;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->overload(__DIR__ . '/../.env');

$env = $_ENV['APP_ENV'] ?? 'prod';
$debug = $env !== 'prod' && filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);

if ($debug) {
    Debug::enable();
}

return [
    'environment' => $env,
    'debug' => $debug,
    'projectRoot' => dirname(__DIR__)
];
