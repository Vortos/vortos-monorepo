<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Build;

/**
 * Resolves the base image digest via `docker buildx imagetools inspect` against the registry the
 * base image is pulled from, at build time. Reproducibility is guaranteed without the app pinning a
 * digest by hand.
 *
 * B2/R8-8: multi-stage Dockerfiles whose FINAL stage builds `FROM <stage-alias>` (e.g. `FROM base`)
 * must not pass the alias to imagetools — it is not a registry ref. The generated script builds the
 * `FROM x AS alias` map and follows the final stage's base back through the aliases until it reaches
 * an EXTERNAL reference (an actual registry image), then inspects that. The warning fires only when
 * resolution genuinely cannot happen (missing Dockerfile, unresolvable/cyclic alias, unreachable
 * registry).
 */
final class RegistryBaseImageDigestResolver implements BaseImageDigestResolverInterface
{
    public function generate(string $dockerfilePath): string
    {
        // Single-quoted heredoc-safe: $VARs are shell runtime vars, not PHP. Only {$dockerfilePath}
        // is interpolated by PHP.
        $df = $dockerfilePath;

        return <<<BASH
            DOCKERFILE="{$df}"
            if [ ! -f "\$DOCKERFILE" ]; then
              echo "::warning::Dockerfile \$DOCKERFILE not found — base image digest not pinned; build reproducibility is not guaranteed."
              exit 0
            fi
            # Follow stage aliases to the external base of the FINAL stage. awk builds base[alias]=ref
            # from every `FROM ref [AS alias]`, remembers the last FROM's ref, then walks that ref
            # through the alias map until it is no longer a declared stage (i.e. an external image).
            BASE_REF=\$(awk '
              toupper(\$1) == "FROM" {
                ref = \$2
                last = ref
                if (NF >= 4 && toupper(\$3) == "AS") { base[tolower(\$4)] = ref }
              }
              END {
                seen = 0
                r = last
                while ((tolower(r) in base) && seen < 100) { r = base[tolower(r)]; seen++ }
                print r
              }
            ' "\$DOCKERFILE")
            if [ -z "\$BASE_REF" ]; then
              echo "::warning::Could not determine base image from \$DOCKERFILE — digest not pinned; build reproducibility is not guaranteed."
              exit 0
            fi
            case "\$BASE_REF" in
              scratch)
                echo "Base image is 'scratch' — no digest to pin."
                exit 0
                ;;
            esac
            # buildx is always set up earlier in the build job (Set up Docker Buildx step).
            DIGEST=\$(docker buildx imagetools inspect "\$BASE_REF" --format '{{json .Manifest.Digest}}' 2>/dev/null | tr -d '"')
            if [ -z "\$DIGEST" ]; then
              echo "::warning::Could not resolve a digest for base image \$BASE_REF — build reproducibility is not guaranteed."
              exit 0
            fi
            echo "Resolved base image \$BASE_REF -> \$DIGEST"
            echo "BASE_IMAGE_DIGEST=\$DIGEST" >> "\$GITHUB_ENV"
            BASH;
    }
}
