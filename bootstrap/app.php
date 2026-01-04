<?php

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\ErrorHandler\Debug;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = new Dotenv();
$dotenv->overload(__DIR__ . '/../.env');

$env = $_ENV['APP_ENV'] ?? 'prod';
$debug = $env === 'dev' && filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);

if ($debug) {
    Debug::enable();
}

return [
    'environment' => $env,
    'debug' => $debug,
    'projectRoot' => dirname(__DIR__)
];
