<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Edge;

/**
 * An operator-supplied edge base config, resolved from disk.
 *
 * This is the "static" half of the static+dynamic edge split: the operator owns everything in this
 * file (TLS, headers, redirects, matchers, extra routes) and the framework injects only the live
 * blue/green upstream at cutover. The raw bytes are kept for the adapt/merge pipeline; the {@see
 * $sha256} content hash is what gets recorded in the release manifest and the audit ledger so a
 * config change is auditable and drift is detectable WITHOUT ever storing the body (which routinely
 * carries secrets such as basicauth hashes or forward_auth tokens).
 *
 * Never stringify the body into a log or exception. {@see __toString()} deliberately returns a
 * redacted, secret-free descriptor for exactly that reason.
 */
final readonly class EdgeBaseConfig
{
    public string $sha256;

    public function __construct(
        public string $path,
        public string $contents,
        public EdgeConfigFormat $format,
    ) {
        if ($path === '') {
            throw new \InvalidArgumentException('Edge base config path must not be empty.');
        }

        if ($contents === '') {
            throw new \InvalidArgumentException('Edge base config contents must not be empty.');
        }

        $this->sha256 = hash('sha256', $contents);
    }

    public function byteLength(): int
    {
        return \strlen($this->contents);
    }

    /** A secret-free descriptor safe to log: path, format, size, content hash — never the body. */
    public function __toString(): string
    {
        return sprintf(
            'EdgeBaseConfig(path=%s, format=%s, bytes=%d, sha256=%s)',
            $this->path,
            $this->format->value,
            $this->byteLength(),
            substr($this->sha256, 0, 12),
        );
    }
}
