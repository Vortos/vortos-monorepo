<?php

declare(strict_types=1);

namespace App\Edge;

// This service talks to Caddy over its admin API — but the word "caddy" appears
// only in this comment, so it must NOT be flagged (AST excludes comments).
final class EdgeConfigService
{
    public function configure(): string
    {
        $github = 'a-token-variable-name';   // variable named $github — must NOT flag
        $endpoint = 'prometheus.local:9090'; // provider in a STRING literal — must NOT flag

        return $github . $endpoint;
    }
}
