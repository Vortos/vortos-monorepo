<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;

/**
 * Fail-closed gate on the realtime hub: the baked Caddyfile and the delivered environment must agree.
 *
 * ## Why this needs a gate rather than care
 *
 * The two halves of the hub live in different artefacts that travel separately. Whether the hub exists
 * is decided by the Caddyfile **baked into the image** (published with 'vortos:docker:publish
 * --with-mercure'); whether it can start, and whether the app talks to it, is decided by the
 * **environment file delivered to the target**. Nothing forces those to move together, and each way of
 * disagreeing fails differently and badly:
 *
 *   - **Hub block, no secret.** The hub refuses to start without its signing keys, so Caddy fails to
 *     parse its own config and the color never boots. Caught here, this is a failed preflight; missed,
 *     it is a cutover that aborts with a config error and an operator reading Caddy logs to find out
 *     that an env var is missing.
 *
 *   - **Secret, no hub block.** Worse, because everything appears to work. vortos-sse selects the
 *     Mercure transport on the strength of the secret alone, so the app hands every browser a
 *     subscription URL pointing at a hub that does not exist. Publishing is fail-safe and silently
 *     swallows its failures by design, the API keeps returning 200s, and the only symptom is that live
 *     updates stop — indistinguishable from "nothing has happened yet". This is the case that would
 *     otherwise be found by a user reporting a stale notification bell weeks later.
 *
 *   - **Hub block and secret, but no public URL.** The app mints subscriptions against an empty URL.
 *     Same silent-death symptom as above.
 *
 * So the check asserts agreement in *both* directions. A half-configured hub is never a warning here.
 *
 * Read-only, per the doctor's contract: it reads the baked Caddyfile and the process environment and
 * mints nothing.
 */
final class RealtimeHubConfigCheck implements PreflightCheckInterface
{
    /** Presence of this directive in the baked Caddyfile is what "the hub exists" means. */
    private const HUB_DIRECTIVE_PATTERN = '/^\s*mercure\s*\{/m';

    private const SECRET_VAR = 'VORTOS_MERCURE_JWT_SECRET';
    private const CORS_VAR = 'VORTOS_MERCURE_CORS_ORIGINS';
    private const PUBLIC_URL_VAR = 'VORTOS_MERCURE_PUBLIC_URL';

    /** @var \Closure(string): ?string */
    private \Closure $readFile;

    /** @var \Closure(string): string */
    private \Closure $env;

    /**
     * @param (\Closure(string): ?string)|null $readFile returns file contents, or null when absent.
     *        Injected so both failure directions are testable without a real image layout.
     * @param (\Closure(string): string)|null  $env      reads an environment variable, '' when unset
     */
    public function __construct(?\Closure $readFile = null, ?\Closure $env = null)
    {
        $this->readFile = $readFile ?? static function (string $path): ?string {
            if (!is_file($path) || !is_readable($path)) {
                return null;
            }

            $contents = file_get_contents($path);

            return $contents === false ? null : $contents;
        };

        $this->env = $env ?? static fn (string $name): string => trim(
            (string) ($_SERVER[$name] ?? $_ENV[$name] ?? ''),
        );
    }

    public function id(): string
    {
        return 'realtime.hub_config_agrees';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Capability;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        $caddyfilePath = $this->caddyfilePathFrom($context->definition->runtimeService->command);

        if ($caddyfilePath === null) {
            return PreflightFinding::skip(
                $this->id(),
                $this->category(),
                'app command declares no --config Caddyfile; nothing to compare the environment against',
            );
        }

        $caddyfile = ($this->readFile)($caddyfilePath);

        if ($caddyfile === null) {
            // Running off the target, or a non-FrankenPHP image. Presence of the baked config is
            // asserted by the image build, not here.
            return PreflightFinding::skip(
                $this->id(),
                $this->category(),
                sprintf('%s is not readable in this context; hub agreement asserted on the target', $caddyfilePath),
            );
        }

        $hubPresent = preg_match(self::HUB_DIRECTIVE_PATTERN, $caddyfile) === 1;
        $secret = ($this->env)(self::SECRET_VAR);

        if ($hubPresent && $secret === '') {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'the baked Caddyfile runs a Mercure hub but no signing secret is configured',
                sprintf(
                    'the hub cannot start without %s and %s, so Caddy will fail to parse %s and the '
                    . 'color will never boot',
                    self::SECRET_VAR,
                    self::CORS_VAR,
                    $caddyfilePath,
                ),
                sprintf(
                    'Set %s (high entropy, >= 32 chars) and %s in the delivered env file, or republish '
                    . 'the Caddyfile without the hub (vortos:docker:publish with no --with-mercure) to '
                    . 'fall back to in-process streaming.',
                    self::SECRET_VAR,
                    self::CORS_VAR,
                ),
            );
        }

        if (!$hubPresent && $secret !== '') {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'a Mercure secret is configured but the baked Caddyfile runs no hub',
                sprintf(
                    'vortos-sse selects the Mercure transport whenever %s is set, so the app would hand '
                    . 'browsers subscriptions to a hub that does not exist in %s. Publishing is fail-safe '
                    . 'and swallows the failure, so the API keeps returning 200s and live updates simply '
                    . 'stop — with no error anywhere.',
                    self::SECRET_VAR,
                    $caddyfilePath,
                ),
                sprintf(
                    'Either republish the Caddyfile with "vortos:docker:publish --with-mercure" and '
                    . 'rebuild, or unset %s so the in-process stream fallback is wired instead.',
                    self::SECRET_VAR,
                ),
            );
        }

        if (!$hubPresent) {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                'no Mercure hub configured; realtime degrades to in-process streaming from the worker pool',
            );
        }

        $missing = [];
        foreach ([self::CORS_VAR, self::PUBLIC_URL_VAR] as $name) {
            if (($this->env)($name) === '') {
                $missing[] = $name;
            }
        }

        if ($missing !== []) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'the Mercure hub is configured but incompletely',
                sprintf(
                    'missing: %s. Without a public URL the app mints subscriptions against an empty '
                    . 'address, and without an allowed origin the browser\'s subscription is rejected by '
                    . 'CORS — both present as live updates that silently never arrive.',
                    implode(', ', $missing),
                ),
                sprintf('Set %s in the delivered env file.', implode(' and ', $missing)),
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            'Mercure hub is baked into the Caddyfile and fully configured in the environment',
        );
    }

    /**
     * The Caddyfile the app actually boots, taken from the argument after '--config' in the declared app
     * command — so this follows a project that relocates its config rather than assuming a path.
     *
     * @param list<string> $command
     */
    private function caddyfilePathFrom(array $command): ?string
    {
        foreach ($command as $index => $argument) {
            if ($argument === '--config') {
                return $command[$index + 1] ?? null;
            }
        }

        return null;
    }
}
