<?php

declare(strict_types=1);

$dir = basename(getcwd());
$e = chr(27);

printf(
    PHP_EOL . $e . '[32m✔ Vortos downloaded successfully!' . $e . '[0m' . PHP_EOL . PHP_EOL .
        'To configure your environment, run:' . PHP_EOL . PHP_EOL .
        '  ' . $e . '[1mcd %s' . $e . '[0m' . PHP_EOL .
        '  ' . $e . '[1mphp vortos setup' . PHP_EOL . PHP_EOL,
    $dir
);
