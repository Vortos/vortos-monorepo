<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Builder;

use Vortos\Release\Manifest\Arch;

final class ArchAssertionScript
{
    public function generate(string $imageRef, Arch $expectedArch): string
    {
        $expectedOs = 'linux';
        $expectedCpuArch = match ($expectedArch) {
            Arch::Arm64 => 'arm64',
            Arch::Amd64 => 'amd64',
        };

        return <<<BASH
            MANIFEST=\$(docker manifest inspect {$imageRef} 2>&1)
            if [ \$? -ne 0 ]; then
              echo "::error::Failed to inspect manifest for {$imageRef}"
              echo "\$MANIFEST"
              exit 1
            fi
            ARCH=\$(echo "\$MANIFEST" | grep -o '"architecture"[[:space:]]*:[[:space:]]*"[^"]*"' | head -1 | sed 's/.*"\\([^"]*\\)"/\\1/')
            OS=\$(echo "\$MANIFEST" | grep -o '"os"[[:space:]]*:[[:space:]]*"[^"]*"' | head -1 | sed 's/.*"\\([^"]*\\)"/\\1/')
            echo "Detected: os=\$OS arch=\$ARCH"
            if [ "\$ARCH" != "{$expectedCpuArch}" ] || [ "\$OS" != "{$expectedOs}" ]; then
              echo "::error::Architecture mismatch: expected {$expectedOs}/{$expectedCpuArch}, got \$OS/\$ARCH"
              exit 1
            fi
            echo "Architecture verified: {$expectedOs}/{$expectedCpuArch}"
            BASH;
    }
}
