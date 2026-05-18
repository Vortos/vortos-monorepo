<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Vortos\Foundation\Runner;

$config = require __DIR__ . '/../bootstrap/app.php';

if (isset($_SERVER['FRANKENPHP_WORKER'])) {
    $runner = new Runner(...$config, context: 'http');

    // Warm the container once before the request loop.
    // On failure: survive — run() owns error handling and the prod/dev distinction.
    try {
        $runner->getContainer();
    } catch (\Throwable $e) {
        // Logged by run() on each request. Nothing else to do here.
    }

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