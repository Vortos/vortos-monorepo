<?php

declare(strict_types=1);

/**
 * Materializes a plaintext env file from a sealed one, using the framework's X25519 identity
 * (VORTOS_AGE_IDENTITY) — the same recipient the secret store uses. Run by the deploy inside the
 * app image, BEFORE the colors boot, so the plaintext env only ever exists on the trusted box and
 * never in git or CI logs. The sealed input is produced by {@see seal-env.php}.
 *
 *   php reveal-env.php <sealed-in> <plaintext-out>
 *
 * Runs WITHOUT booting the application kernel (it only autoloads the crypto codec), so it works
 * before a valid env file exists — the chicken-and-egg a console command could not escape.
 *
 * Fails closed: on a missing identity, wrong key, or tampered ciphertext it exits non-zero and
 * writes nothing, leaving any existing target env file untouched so the deploy proceeds on the
 * last-good env rather than a corrupted one.
 */

use Vortos\Secrets\Crypto\AgeKeyCodec;

// Find the app's composer autoloader by walking up from this script (…/vendor/vortos/vortos-secrets/bin).
$autoload = null;
foreach ([__DIR__ . '/../../../../autoload.php', __DIR__ . '/../../../autoload.php', getcwd() . '/vendor/autoload.php'] as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}
if ($autoload === null) {
    fwrite(STDERR, "reveal-env: cannot locate vendor/autoload.php\n");
    exit(2);
}
require $autoload;

$in = $argv[1] ?? null;
$out = $argv[2] ?? null;
if ($in === null || $out === null) {
    fwrite(STDERR, "usage: php reveal-env.php <sealed-in> <plaintext-out>\n");
    exit(2);
}

$identity = getenv('VORTOS_AGE_IDENTITY');
if ($identity === false || $identity === '') {
    fwrite(STDERR, "reveal-env: VORTOS_AGE_IDENTITY not set — leaving {$out} untouched\n");
    exit(2);
}

$secretKey = AgeKeyCodec::decodeIdentity($identity);           // 32-byte X25519 scalar
$publicKey = sodium_crypto_box_publickey_from_secretkey($secretKey);
$keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secretKey, $publicKey);

$sealed = @file_get_contents($in);
if ($sealed === false) {
    fwrite(STDERR, "reveal-env: cannot read {$in}\n");
    exit(1);
}

$plaintext = sodium_crypto_box_seal_open($sealed, $keypair);
if ($plaintext === false) {
    fwrite(STDERR, "reveal-env: decryption failed (wrong identity or tampered ciphertext) — leaving {$out} untouched\n");
    exit(1);
}

// Write atomically with owner+group read only, so a partial or world-readable plaintext env never lingers.
$tmp = $out . '.tmp';
if (file_put_contents($tmp, $plaintext, LOCK_EX) === false) {
    fwrite(STDERR, "reveal-env: cannot write {$tmp}\n");
    exit(1);
}
@chmod($tmp, 0640);
if (!rename($tmp, $out)) {
    @unlink($tmp);
    fwrite(STDERR, "reveal-env: cannot move into place {$out}\n");
    exit(1);
}

sodium_memzero($secretKey);
sodium_memzero($keypair);
fwrite(STDERR, "reveal-env: materialized {$out} (" . strlen($plaintext) . " bytes)\n");
