<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Edge;

/**
 * The on-disk format of an operator-supplied edge base config.
 *
 * A Caddyfile must be adapted to JSON (via the Caddy parser) before it can be merged; JSON is already
 * structured and skips the adapt step. Format is detected from the file extension so an operator can
 * hand-write either without a config flag.
 */
enum EdgeConfigFormat: string
{
    case Caddyfile = 'caddyfile';
    case Json = 'json';

    /**
     * Detect the format from a file path's extension. Caddyfiles are conventionally extensionless or
     * carry .caddy/.caddyfile; anything ending .json is treated as pre-adapted JSON. An unknown
     * extension defaults to Caddyfile — the safe choice, since it forces an adapt+validate pass rather
     * than trusting an unparsed blob as JSON.
     */
    public static function fromPath(string $path): self
    {
        $ext = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

        return $ext === 'json' ? self::Json : self::Caddyfile;
    }

    public function requiresAdapt(): bool
    {
        return $this === self::Caddyfile;
    }
}
