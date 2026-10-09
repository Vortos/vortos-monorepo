<?php

declare(strict_types=1);

/*
 * A minimal S3 GetObject endpoint for BulkTransferClientTest, run under `php -S`.
 *
 *   /<bucket>/trickle-<bytes>-<bytesPerSecond>  serves the object at a steady throttled rate
 *   /<bucket>/stall-<bytes>                     sends 1 KiB, then goes silent
 *
 * Honours `Range: bytes=a-b` with 206 + Content-Range, like S3 and R2, and logs each Range it served
 * to the file named by THROTTLED_S3_LOG so the test can see how a read was split.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$name = basename((string) $path);

if (preg_match('/^trickle-(\d+)-(\d+)$/', $name, $m) === 1) {
    [$size, $rate, $stall] = [(int) $m[1], (int) $m[2], false];
} elseif (preg_match('/^stall-(\d+)$/', $name, $m) === 1) {
    [$size, $rate, $stall] = [(int) $m[1], 0, true];
} else {
    http_response_code(404);
    header('Content-Type: application/xml');
    echo '<?xml version="1.0"?><Error><Code>NoSuchKey</Code><Message>missing</Message></Error>';
    return true;
}

$first = 0;
$last = $size - 1;
$range = $_SERVER['HTTP_RANGE'] ?? null;

if ($range !== null && preg_match('/^bytes=(\d+)-(\d+)$/', $range, $r) === 1) {
    $first = (int) $r[1];
    $last = min((int) $r[2], $size - 1);
    http_response_code(206);
    header(sprintf('Content-Range: bytes %d-%d/%d', $first, $last, $size));
}

$log = getenv('THROTTLED_S3_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, ($range ?? 'none') . "\n", FILE_APPEND | LOCK_EX);
}

$length = $last - $first + 1;
header('Content-Type: application/octet-stream');
header('Content-Length: ' . $length);
header('ETag: "fixture-etag"');

while (ob_get_level() > 0) {
    ob_end_flush();
}

// Byte i of the object is chr(i % 251): any misplaced or duplicated part changes the content.
$cycle = '';
for ($i = 0; $i < 251; $i++) {
    $cycle .= chr($i);
}
$bytes = static function (int $from, int $count) use ($cycle): string {
    $offset = $from % 251;

    return substr(str_repeat($cycle, intdiv($offset + $count, 251) + 1), $offset, $count);
};

if ($stall) {
    echo $bytes($first, min(1024, $length));
    flush();
    sleep(30);
    return true;
}

$tick = 0.1;
$perTick = max(1, (int) ($rate * $tick));
$sent = 0;

while ($sent < $length) {
    $n = min($perTick, $length - $sent);
    echo $bytes($first + $sent, $n);
    flush();
    $sent += $n;
    if ($sent < $length) {
        usleep((int) ($tick * 1_000_000));
    }
}

return true;
