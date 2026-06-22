<?php

declare(strict_types=1);

namespace Vortos\Foundation\Assets;

final class PublishResult
{
    public function __construct(
        public readonly string $package,
        public readonly string $target,
        public readonly string $action, // 'copied' | 'symlinked' | 'failed'
        public readonly ?string $error = null,
    ) {}
}
