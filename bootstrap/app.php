<?php

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\ErrorHandler\Debug;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = new Dotenv();
$dotenv->overload(__DIR__ . '/../.env');

$localEnv = __DIR__ . '/../.env.local';
if (is_file($localEnv)) {
    $dotenv->overload($localEnv);
}

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
