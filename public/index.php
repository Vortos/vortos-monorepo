<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Vortos\Foundation\Runner;

$config = require __DIR__ . '/../bootstrap/app.php';

if (isset($_SERVER['FRANKENPHP_WORKER'])) {
    $runner = new Runner(...$config, context: 'http');

    // Boot container once outside the loop
    $runner->getContainer();

    // Handle requests in a loop — process stays alive
    while (frankenphp_handle_request(function () use ($runner): void {
        header('X-Vortos-Mode: Worker-Active');
        $response = $runner->run();
        $response->send();
        $runner->cleanUp();
    })) {
        // You can leave this empty or add a request counter here to restart 
        // the worker gracefully after X requests to prevent memory leaks.
    }
} else {

    // Fallback for standard execution (e.g., standard Docker boot or local php -S)
    $runner = new Runner(...$config, context: 'http');
    $response = $runner->run();
    $response->send();
    $runner->cleanUp();
}