<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Cutover\Edge\EdgeConfigAssembler;
use Vortos\Deploy\Exception\EdgeBaseConfigException;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Deploy\Target\ActiveColor;

/**
 * Fail-closed preflight gate for the operator's edge base config.
 *
 * Runs the ENTIRE adapt-merge pipeline ahead of the deploy — resolve the base config, adapt it (in a
 * throwaway caddy container that works even when the edge is DOWN), identify the app proxy, merge a
 * dry live color, and run it through the config firewall — WITHOUT touching live traffic. If the base
 * config is broken, ambiguous (>=2 app proxies), missing a site block, or would violate an invariant
 * (e.g. an admin override that exposes the API), the deploy is refused here, before anything is
 * deployed and while prod is untouched. The cutover firewall is the backstop; this catches it first.
 *
 * Read-only: it only reads the base config file and spawns an ephemeral, network-less adapt container
 * (mutating nothing durable), exactly as the "doctor is read-only" contract allows.
 *
 * Skips (not fails) when no base config is configured — the from-scratch path is unchanged and needs
 * no gate.
 */
final class EdgeBaseConfigCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly EdgeConfigAssembler $assembler,
        private readonly string $appDomain = '',
    ) {}

    public function id(): string
    {
        return 'edge.base_config';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Plan;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        try {
            if (!$this->assembler->hasBaseConfig()) {
                return PreflightFinding::skip(
                    $this->id(),
                    $this->category(),
                    'no edge base config configured; using the from-scratch generated edge (unchanged)',
                );
            }
        } catch (EdgeBaseConfigException $e) {
            // A configured-but-unreadable / traversal-escaping base path fails closed here.
            return $this->fail($e->getMessage());
        }

        $dryRoute = $this->dryRoute($context);

        try {
            $assembled = $this->assembler->assembleForRoute($dryRoute);
        } catch (EdgeBaseConfigException $e) {
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            // Anything unexpected (adapt transport failure, etc.) is a fail-closed — a deploy never
            // proceeds on a base config we could not fully validate.
            return $this->fail('edge base config could not be validated: ' . $e->getMessage());
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            sprintf(
                'edge base config adapts, merges, and passes the config firewall (action=%s)',
                $assembled->mergeOutcome?->action->value ?? 'generated',
            ),
        );
    }

    private function dryRoute(PreflightContext $context): DesiredRoute
    {
        $port = $context->definition->runtimeService->containerPort;
        $domain = $this->appDomain !== '' ? $this->appDomain : null;

        // A representative color is enough to exercise identification, patch/insert, and the firewall;
        // the merge is deterministic in the color and this route is never loaded anywhere.
        return new DesiredRoute(
            env: $context->environment->value,
            activeColor: ActiveColor::Blue,
            upstream: new ColorEndpoint('app-blue', $port),
            domain: $domain,
        );
    }

    private function fail(string $message): PreflightFinding
    {
        return PreflightFinding::fail(
            $this->id(),
            $this->category(),
            'edge base config is not deployable',
            $message,
            'Fix the Caddyfile so exactly one app-<color> reverse_proxy serves the domain and the '
            . 'config adapts cleanly, then redeploy. Production is untouched until this passes.',
        );
    }
}
